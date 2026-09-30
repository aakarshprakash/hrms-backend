<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\BillingFeatureResolver;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\SubscriptionService;
use App\Support\Billing\Features;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The platform operator's side of billing: the plan catalogue, each
 * organisation's subscription (plan, status, trial, negotiated price) and
 * settling invoices paid offline.
 */
class PlatformBillingController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private SubscriptionService $subscriptions,
        private InvoiceService $invoices,
        private BillingFeatureResolver $resolver,
    ) {
    }

    public function plans(): JsonResponse
    {
        return response()->json(['data' => Plan::orderBy('sort_order')->get(), 'features' => Features::ALL]);
    }

    public function updatePlan(Request $request, Plan $plan): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:500',
            'base_price' => 'sometimes|numeric|min:0|max:10000000',
            'per_employee_price' => 'sometimes|numeric|min:0|max:100000',
            'included_employees' => 'sometimes|integer|min:0|max:100000',
            'features' => 'sometimes|array',
            'features.*' => [Rule::in(array_keys(Features::ALL))],
            'limits.branches' => 'nullable|integer|min:1',
            'limits.employees' => 'nullable|integer|min:1',
            'is_public' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($validated['features']) && ! in_array('core', $validated['features'], true)) {
            $validated['features'][] = 'core';
        }
        if ($request->has('limits')) {
            $validated['limits'] = ['branches' => $request->input('limits.branches'), 'employees' => $request->input('limits.employees')];
        }

        $plan->update($validated);
        activity('platform')->performedOn($plan)->event('plan_updated')->withProperties(['attributes' => $validated])->log("Plan {$plan->code} updated");

        return response()->json(['data' => $plan->fresh(), 'message' => 'Plan saved. Organisations on it pick up the change on their next request.']);
    }

    public function show(int $company): JsonResponse
    {
        $model = $this->context->withoutScoping(fn () => Company::findOrFail($company));
        $subscription = $this->subscriptions->current($model);
        $invoices = $this->context->withoutScoping(fn () => Invoice::where('company_id', $model->id)->orderByDesc('id')->limit(24)->get());

        return response()->json(['data' => [
            'subscription' => $subscription,
            'features' => Features::enabledFor($model),
            'invoices' => $invoices,
            'employees' => $this->invoices->activeEmployees($model->id),
        ], 'plans' => Plan::orderBy('sort_order')->get(['id', 'code', 'name', 'is_public', 'is_active'])]);
    }

    /** Set plan, status, trial end or a negotiated price directly. */
    public function update(Request $request, int $company): JsonResponse
    {
        $validated = $request->validate([
            'plan_code' => ['sometimes', Rule::exists('plans', 'code')],
            'status' => ['sometimes', Rule::in(['trialing', 'active', 'past_due', 'cancelled', 'expired'])],
            'billing_cycle' => 'sometimes|in:monthly,yearly',
            'trial_ends_at' => 'nullable|date',
            'current_period_end' => 'nullable|date',
            'custom_monthly_price' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $model = $this->context->withoutScoping(fn () => Company::findOrFail($company));
        $subscription = $this->subscriptions->current($model);

        $changes = collect($validated)->except('plan_code')->all();
        if (isset($validated['plan_code'])) {
            $changes['plan_id'] = Plan::where('code', $validated['plan_code'])->value('id');
        }
        if (($changes['status'] ?? null) === 'active' && ! ($subscription?->current_period_end) && ! isset($changes['current_period_end'])) {
            $changes['current_period_start'] = now()->toDateString();
            $changes['current_period_end'] = $this->subscriptions->periodEnd(CarbonImmutable::now()->startOfDay(), $changes['billing_cycle'] ?? $subscription?->billing_cycle ?? 'monthly')->toDateString();
        }

        $this->context->runAs($model->id, function () use (&$subscription, $model, $changes) {
            if ($subscription) {
                $subscription->update($changes);
            } else {
                $subscription = new Subscription($changes + ['status' => 'active', 'plan_id' => Plan::where('code', 'legacy')->value('id')]);
                $subscription->forceFill(['company_id' => $model->id])->save();
            }
        });
        $this->resolver->forget($model->id);

        activity('platform')->performedOn($model)->event('subscription_updated')->withProperties(['attributes' => $validated])->log('Subscription changed by the platform');

        return response()->json(['data' => $subscription->fresh('plan'), 'message' => 'Subscription updated.']);
    }

    /** Issue the current period's invoice now (e.g. after agreeing a plan by phone). */
    public function issueInvoice(int $company): JsonResponse
    {
        $model = $this->context->withoutScoping(fn () => Company::findOrFail($company));
        $subscription = $this->subscriptions->current($model);
        abort_unless($subscription && $subscription->current_period_start && $subscription->current_period_end, 422, 'The subscription has no billing period yet.');

        $invoice = $this->invoices->issue($subscription, CarbonImmutable::parse($subscription->current_period_start), CarbonImmutable::parse($subscription->current_period_end));
        abort_unless($invoice, 422, 'Nothing to invoice: this subscription is free.');

        return response()->json(['data' => $invoice, 'message' => "Invoice {$invoice->number} issued."], 201);
    }

    public function markPaid(Request $request, int $invoice): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => 'required|in:bank_transfer,upi,cheque,razorpay,other',
            'payment_reference' => 'nullable|string|max:191',
            'paid_at' => 'nullable|date',
        ]);

        $model = $this->context->withoutScoping(fn () => Invoice::findOrFail($invoice));
        $paid = $this->context->runAs($model->company_id, fn () => $this->invoices->markPaid($model, $validated['payment_method'], $validated['payment_reference'] ?? null, $validated['paid_at'] ?? null));
        $this->resolver->forget($model->company_id);

        activity('platform')->performedOn($paid)->event('invoice_paid')->withProperties($validated)->log("Invoice {$paid->number} marked paid");

        return response()->json(['data' => $paid, 'message' => "Invoice {$paid->number} marked paid."]);
    }

    public function void(int $invoice): JsonResponse
    {
        $model = $this->context->withoutScoping(fn () => Invoice::findOrFail($invoice));
        abort_if($model->status === 'paid', 422, 'A paid invoice can’t be voided.');
        $this->context->runAs($model->company_id, fn () => $model->update(['status' => 'void']));

        return response()->json(['data' => $model->fresh(), 'message' => "Invoice {$model->number} voided."]);
    }
}
