<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\RazorpayGateway;
use App\Services\Billing\SubscriptionService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The organisation's own subscription, plans and invoices (billing.manage). */
class BillingController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private InvoiceService $invoices,
        private RazorpayGateway $razorpay,
    ) {
    }

    public function show(): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        $subscription = $this->subscriptions->current($company);
        $employees = $this->invoices->activeEmployees($company->id);
        $yearlyMonths = (int) config('billing.yearly_months_charged', 10);

        $plans = Plan::where('is_public', true)->where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn (Plan $p) => $p->only(['code', 'name', 'description', 'base_price', 'per_employee_price', 'included_employees', 'features', 'limits']) + [
                'monthly_estimate' => $p->monthlyPriceFor($employees),
                'yearly_estimate' => round($p->monthlyPriceFor($employees) * $yearlyMonths, 2),
            ]);

        $next = null;
        if ($subscription && $subscription->isLive() && $subscription->plan) {
            $start = $subscription->status === 'trialing'
                ? CarbonImmutable::parse($subscription->trial_ends_at ?? now())->startOfDay()
                : ($subscription->current_period_end ? CarbonImmutable::parse($subscription->current_period_end)->addDay() : null);
            if ($start && ($subscription->status !== 'trialing' || $subscription->plan_confirmed_at)) {
                $quote = $this->invoices->quote($subscription, $company, $start, $this->subscriptions->periodEnd($start, $subscription->billing_cycle));
                $next = $quote['total'] > 0 ? ['date' => $start->toDateString()] + $quote : null;
            }
        }

        return response()->json(['data' => [
            'subscription' => $subscription ? $subscription->toArray() + [
                'trial_days_left' => $subscription->trialDaysLeft(),
                'is_live' => $subscription->isLive(),
            ] : null,
            'usage' => ['employees' => $employees, 'branches' => Branch::count()],
            'plans' => $plans,
            'next_invoice' => $next,
            'unpaid' => Invoice::where('status', 'issued')->orderBy('due_on')->get(['id', 'number', 'total', 'due_on']),
            'online_payment' => $this->razorpay->enabled(),
            'bank_details' => config('billing.seller.bank_details'),
            'billing_details' => $company->only(['name', 'legal_name', 'gstin', 'email', 'address_line1', 'address_line2', 'city', 'state', 'postal_code']),
            'yearly_months_charged' => $yearlyMonths,
        ]]);
    }

    public function choosePlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_code' => ['required', Rule::exists('plans', 'code')->where('is_public', true)->where('is_active', true)],
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $company = app(TenantContext::class)->company();
        $plan = Plan::where('code', $validated['plan_code'])->firstOrFail();
        $subscription = $this->subscriptions->choosePlan($company, $plan, $validated['billing_cycle']);

        activity('billing')->causedBy($request->user())->performedOn($subscription)->event('plan_chosen')
            ->withProperties(['plan' => $plan->code, 'cycle' => $validated['billing_cycle']])->log("Chose the {$plan->name} plan");

        return response()->json([
            'data' => $subscription,
            'message' => $subscription->status === 'trialing'
                ? "{$plan->name} it is — billing starts when your trial ends."
                : "You’re on {$plan->name}.",
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $subscription = $this->subscriptions->cancel(app(TenantContext::class)->company());
        activity('billing')->causedBy($request->user())->performedOn($subscription)->event('cancelled')->log('Subscription cancelled');

        return response()->json(['data' => $subscription, 'message' => $subscription->status === 'cancelled'
            ? 'Your trial is cancelled.' : 'Your plan ends on ' . $subscription->current_period_end?->format('j M Y') . '.']);
    }

    public function resume(Request $request): JsonResponse
    {
        $subscription = $this->subscriptions->resume(app(TenantContext::class)->company());

        return response()->json(['data' => $subscription, 'message' => 'Your plan continues.']);
    }

    public function invoices(Request $request): JsonResponse
    {
        $page = Invoice::orderByDesc('issued_on')->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (Invoice $i) => $i->toArray() + ['overdue' => $i->isOverdue()]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function invoicePdf(Invoice $invoice)
    {
        return response($this->invoices->pdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$invoice->number}.pdf\"",
        ]);
    }

    public function pay(Invoice $invoice): JsonResponse
    {
        abort_unless($invoice->status === 'issued', 422, 'This invoice is not open for payment.');
        abort_unless($this->razorpay->enabled(), 422, 'Online payment isn’t available — please pay by bank transfer.');

        return response()->json(['data' => ['url' => $this->razorpay->paymentLink($invoice)]]);
    }

    /** Name, GSTIN and address printed on invoices. */
    public function updateDetails(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'legal_name' => 'nullable|string|max:191',
            'gstin' => ['nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/'],
            'email' => 'nullable|email|max:191',
            'address_line1' => 'nullable|string|max:191',
            'address_line2' => 'nullable|string|max:191',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:12',
        ], ['gstin.regex' => 'That doesn’t look like a valid GSTIN (15 characters, e.g. 32ABCDE1234F1Z5).']);

        $company = app(TenantContext::class)->company();
        if (isset($validated['gstin'])) {
            $validated['gstin'] = strtoupper($validated['gstin']);
        }
        $company->forceFill($validated)->save();

        return response()->json(['message' => 'Billing details saved.']);
    }
}
