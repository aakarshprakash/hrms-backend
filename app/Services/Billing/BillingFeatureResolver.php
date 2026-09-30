<?php

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\Subscription;
use App\Support\Billing\Features;
use App\Support\Tenancy\TenantContext;

/**
 * What a tenant's subscription unlocks (bound as 'billing.features', read by
 * Features / Limits and the SPA):
 *
 *  - live subscription (trialing / active / past due): its plan's modules
 *    and quotas;
 *  - trial over or cancelled: the free core (attendance, leave,
 *    self-service) so people can still punch in and see payslips;
 *  - no subscription record at all: everything, as before billing existed.
 */
class BillingFeatureResolver
{
    /** @var array<int, ?Subscription> */
    private array $subscriptions = [];

    public function subscriptionFor(Company $company): ?Subscription
    {
        if (! array_key_exists($company->id, $this->subscriptions)) {
            $this->subscriptions[$company->id] = app(TenantContext::class)->withoutScoping(
                fn () => Subscription::with('plan')->where('company_id', $company->id)->latest('id')->first()
            );
        }

        return $this->subscriptions[$company->id];
    }

    /** @return list<string> */
    public function enabledFor(Company $company): array
    {
        $subscription = $this->subscriptionFor($company);

        if (! $subscription) {
            return array_keys(Features::ALL);
        }

        $features = $subscription->isLive() ? (array) ($subscription->plan?->features ?? []) : config('billing.free_features', ['core']);

        return array_values(array_intersect(array_keys(Features::ALL), $features));
    }

    public function limitFor(Company $company, string $resource): ?int
    {
        $subscription = $this->subscriptionFor($company);

        return $subscription?->isLive() ? $subscription->plan?->limit($resource) : null;
    }

    public function summary(Company $company): ?array
    {
        $s = $this->subscriptionFor($company);

        if (! $s) {
            return null;
        }

        return [
            'plan' => $s->plan?->code,
            'plan_name' => $s->plan?->name,
            'status' => $s->status,
            'billing_cycle' => $s->billing_cycle,
            'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
            'trial_days_left' => $s->trialDaysLeft(),
            'plan_confirmed' => $s->plan_confirmed_at !== null,
            'current_period_end' => $s->current_period_end?->toDateString(),
            'cancel_at_period_end' => (bool) $s->cancel_at_period_end,
        ];
    }

    public function forget(int $companyId): void
    {
        unset($this->subscriptions[$companyId]);
    }
}
