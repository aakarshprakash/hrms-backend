<?php

namespace App\Services\Billing;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The subscription lifecycle:
 *
 *  - a new tenant starts a trial (plan: billing.trial_plan);
 *  - choosing a plan during the trial confirms it -- billing starts when
 *    the trial ends; after a trial/cancellation it starts at once;
 *  - each period end renews with an invoice (or ends a cancelled plan);
 *  - an invoice unpaid past the grace days makes it past due (features stay
 *    on; the admin sees a banner) until paid;
 *  - a trial that ends without a plan falls back to the free core.
 */
class SubscriptionService
{
    public function __construct(
        private InvoiceService $invoices,
        private BillingFeatureResolver $resolver,
        private TenantContext $context,
    ) {
    }

    public function current(Company $company): ?Subscription
    {
        return $this->context->withoutScoping(
            fn () => Subscription::with('plan')->where('company_id', $company->id)->latest('id')->first()
        );
    }

    public function startTrial(Company $company, ?string $planCode = null): Subscription
    {
        $plan = Plan::where('code', $planCode ?: config('billing.trial_plan', 'growth'))->firstOrFail();

        return $this->create($company, [
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'billing_cycle' => 'monthly',
            'trial_ends_at' => now()->addDays((int) config('billing.trial_days', 14)),
        ]);
    }

    /** Demo organisations: the top plan, free, never invoiced. */
    public function startDemo(Company $company): ?Subscription
    {
        $plan = Plan::where('code', 'enterprise')->first() ?? Plan::where('code', 'legacy')->first();

        return $plan ? $this->create($company, [
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'plan_confirmed_at' => now(),
            'custom_monthly_price' => 0,
            'notes' => 'Demo organisation.',
        ]) : null;
    }

    /** Organisations from before billing: every module, never invoiced. */
    public function assignLegacy(Company $company): Subscription
    {
        return $this->create($company, [
            'plan_id' => Plan::where('code', 'legacy')->firstOrFail()->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'plan_confirmed_at' => now(),
            'notes' => 'Founding customer: on the product before plans existed.',
        ]);
    }

    /**
     * The tenant chooses (or changes) its plan. Refused when current usage
     * is over the new plan's limits.
     */
    public function choosePlan(Company $company, Plan $plan, string $cycle): Subscription
    {
        abort_unless($plan->is_active && $plan->is_public, 422, 'That plan isn’t available.');
        $this->assertFits($company, $plan);

        $current = $this->current($company);

        // Still in the trial: keep it, billing starts when it ends.
        if ($current && $current->status === 'trialing' && $current->trial_ends_at?->isFuture()) {
            $current->update(['plan_id' => $plan->id, 'billing_cycle' => $cycle, 'plan_confirmed_at' => now(),
                'cancel_at_period_end' => false]);
            $this->resolver->forget($company->id);

            return $current->fresh('plan');
        }

        // A live paid subscription: the new plan applies from the next invoice.
        if ($current && in_array($current->status, ['active', 'past_due'], true) && $current->current_period_end) {
            $current->update(['plan_id' => $plan->id, 'billing_cycle' => $cycle, 'plan_confirmed_at' => now(),
                'cancel_at_period_end' => false, 'custom_monthly_price' => null]);
            $this->resolver->forget($company->id);

            return $current->fresh('plan');
        }

        // Trial over, cancelled, founding plan, or nothing yet: start now and bill the first period.
        $start = CarbonImmutable::now()->startOfDay();
        $subscription = $this->create($company, [
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => $cycle,
            'plan_confirmed_at' => now(),
            'current_period_start' => $start->toDateString(),
            'current_period_end' => $this->periodEnd($start, $cycle)->toDateString(),
        ]);
        $this->invoices->issue($subscription, $start, $this->periodEnd($start, $cycle));

        return $subscription->fresh('plan');
    }

    /** Cancel at the end of the paid period (a trial just ends). */
    public function cancel(Company $company): Subscription
    {
        $current = $this->current($company);
        abort_unless($current && $current->isLive(), 422, 'There is no active subscription to cancel.');

        if ($current->status === 'trialing') {
            $current->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        } else {
            $current->update(['cancel_at_period_end' => true, 'cancelled_at' => now()]);
        }
        $this->resolver->forget($company->id);

        return $current->fresh('plan');
    }

    public function resume(Company $company): Subscription
    {
        $current = $this->current($company);
        abort_unless($current && $current->cancel_at_period_end && $current->isLive(), 422, 'Nothing to resume.');
        $current->update(['cancel_at_period_end' => false, 'cancelled_at' => null]);

        return $current->fresh('plan');
    }

    /**
     * Daily: end trials, renew periods, flag overdue invoices.
     *
     * @return array{trials_ended: int, renewed: int, cancelled: int, past_due: int}
     */
    public function runCycle(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now()->startOfDay();
        $stats = ['trials_ended' => 0, 'renewed' => 0, 'cancelled' => 0, 'past_due' => 0];

        $this->context->withoutScoping(function () use ($today, &$stats) {
            // Only each company's latest subscription counts.
            $latestIds = Subscription::query()->selectRaw('MAX(id) as id')->groupBy('company_id')->pluck('id');

            foreach (Subscription::with('plan')->whereIn('id', $latestIds)->get() as $s) {
                $this->context->runAs($s->company_id, function () use ($s, $today, &$stats) {
                    if ($s->status === 'trialing' && $s->trial_ends_at && $s->trial_ends_at->lte($today->endOfDay())) {
                        if ($s->plan_confirmed_at) {
                            $start = CarbonImmutable::parse($s->trial_ends_at)->startOfDay();
                            $s->update(['status' => 'active', 'current_period_start' => $start->toDateString(),
                                'current_period_end' => $this->periodEnd($start, $s->billing_cycle)->toDateString()]);
                            $this->invoices->issue($s, $start, $this->periodEnd($start, $s->billing_cycle));
                        } else {
                            $s->update(['status' => 'expired']);
                        }
                        $stats['trials_ended']++;

                        return;
                    }

                    if (in_array($s->status, ['active', 'past_due'], true) && $s->current_period_end && $s->current_period_end->lt($today)) {
                        if ($s->cancel_at_period_end) {
                            $s->update(['status' => 'cancelled']);
                            $stats['cancelled']++;

                            return;
                        }
                        $start = CarbonImmutable::parse($s->current_period_end)->addDay();
                        $end = $this->periodEnd($start, $s->billing_cycle);
                        $s->update(['current_period_start' => $start->toDateString(), 'current_period_end' => $end->toDateString()]);
                        $this->invoices->issue($s, $start, $end);
                        $stats['renewed']++;
                    }

                    $overdue = Invoice::where('subscription_id', $s->id)->where('status', 'issued')
                        ->where('due_on', '<', $today->subDays((int) config('billing.grace_days', 7))->toDateString())->exists();
                    if ($s->status === 'active' && $overdue) {
                        $s->update(['status' => 'past_due']);
                        $stats['past_due']++;
                    }
                });
            }
        });

        return $stats;
    }

    public function periodEnd(CarbonImmutable $start, string $cycle): CarbonImmutable
    {
        return ($cycle === 'yearly' ? $start->addYear() : $start->addMonth())->subDay();
    }

    /** Usage must fit the plan's quotas before switching to it. */
    public function assertFits(Company $company, Plan $plan): void
    {
        $this->context->runAs($company->id, function () use ($plan) {
            $branches = Branch::count();
            if (($limit = $plan->limit('branches')) !== null && $branches > $limit) {
                abort(422, "You have {$branches} branches; {$plan->name} allows {$limit}. Remove branches or choose a bigger plan.");
            }
            $employees = $this->invoices->activeEmployees(app(TenantContext::class)->id());
            if (($limit = $plan->limit('employees')) !== null && $employees > $limit) {
                abort(422, "You have {$employees} active employees; {$plan->name} allows {$limit}.");
            }
        });
    }

    private function create(Company $company, array $attributes): Subscription
    {
        return DB::transaction(function () use ($company, $attributes) {
            $subscription = new Subscription($attributes);
            $subscription->forceFill(['company_id' => $company->id]);
            $this->context->runAs($company->id, fn () => $subscription->save());
            $this->resolver->forget($company->id);

            return $subscription->load('plan');
        });
    }
}
