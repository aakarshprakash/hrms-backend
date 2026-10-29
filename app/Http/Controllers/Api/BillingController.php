<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Http\Controllers\Api\Platform\PlatformLicenseController;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\LicenseService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\RazorpayGateway;
use App\Services\Billing\SubscriptionService;
use App\Support\Billing\Cycles;
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
        $yearlyMonths = (int) Cycles::monthsCharged('yearly');

        $plans = Plan::where('is_public', true)->where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn (Plan $p) => $p->only(['code', 'name', 'description', 'base_price', 'per_employee_price', 'included_employees', 'features', 'limits']) + [
                'monthly_estimate' => $p->monthlyPriceFor($employees),
                'yearly_estimate' => round($p->monthlyPriceFor($employees) * $yearlyMonths, 2),
                // What one period of each cycle costs at today's headcount, before GST.
                'estimates' => collect(Cycles::keys())->mapWithKeys(fn ($c) => [$c => round($p->monthlyPriceFor($employees) * Cycles::monthsCharged($c), 2)]),
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
                'license' => $subscription->billing_mode === 'license' && $subscription->licenseKey ? [
                    'key' => $subscription->licenseKey->masked(),
                    'months' => $subscription->licenseKey->months,
                    'redeemed_at' => $subscription->licenseKey->redeemed_at,
                ] : null,
            ] : null,
            'autopay_available' => PlatformLicenseController::billingSettings()['autopay_available'],
            'usage' => ['employees' => $employees, 'branches' => Branch::count()],
            'plans' => $plans,
            'next_invoice' => $next,
            'unpaid' => Invoice::where('status', 'issued')->orderBy('due_on')->get(['id', 'number', 'total', 'due_on']),
            'online_payment' => $this->razorpay->enabled(),
            'bank_details' => config('billing.seller.bank_details'),
            'billing_details' => $company->only(['name', 'legal_name', 'gstin', 'email', 'address_line1', 'address_line2', 'city', 'state', 'postal_code']),
            'yearly_months_charged' => $yearlyMonths,
            'cycles' => Cycles::all(),
            'gst_rate' => (float) config('billing.gst_rate', 18),
        ]]);
    }

    /** Activates (or extends) the plan with a licence key bought offline. */
    public function redeemLicense(Request $request, LicenseService $licenses): JsonResponse
    {
        $v = $request->validate(['key' => ['required', 'string', 'max:60']]);
        $company = app(TenantContext::class)->company();
        $subscription = app(TenantContext::class)->withoutScoping(fn () => $licenses->redeem($company, $v['key'], $request->user()));

        return response()->json(['data' => $subscription->load('plan'), 'message' => "Licence activated: {$subscription->plan->name} until "
            . CarbonImmutable::parse($subscription->current_period_end)->format('j M Y') . '.']);
    }

    /**
     * Automatic renewal by card / UPI mandate. Stored now; charging starts once
     * the platform turns mandates on. Until then renewals are invoiced as usual.
     */
    public function setAutopay(Request $request): JsonResponse
    {
        $v = $request->validate(['autopay' => ['required', 'boolean']]);
        $subscription = $this->subscriptions->current(app(TenantContext::class)->company());
        abort_unless($subscription && $subscription->isLive(), 422, 'There is no active subscription to renew.');
        $subscription->update(['autopay' => $v['autopay']]);

        $available = PlatformLicenseController::billingSettings()['autopay_available'];

        return response()->json(['data' => $subscription->fresh(), 'message' => $v['autopay']
            ? ($available ? 'Autopay is on: renewals will be charged automatically.' : 'Autopay is on. Automatic charging starts once it’s enabled for your account — until then you’ll get renewal invoices as usual.')
            : 'Autopay is off: you’ll pay each renewal invoice yourself.']);
    }

    public function choosePlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_code' => ['required', Rule::exists('plans', 'code')->where('is_public', true)->where('is_active', true)],
            'billing_cycle' => ['required', Rule::in(Cycles::keys())],
            'start_now' => 'sometimes|boolean',
        ]);

        $company = app(TenantContext::class)->company();
        $plan = Plan::where('code', $validated['plan_code'])->firstOrFail();
        $subscription = $this->subscriptions->choosePlan($company, $plan, $validated['billing_cycle'], (bool) ($validated['start_now'] ?? false));

        activity('billing')->causedBy($request->user())->performedOn($subscription)->event('plan_chosen')
            ->withProperties(['plan' => $plan->code, 'cycle' => $validated['billing_cycle']])->log("Chose the {$plan->name} plan");

        // A paid period that just started: hand back its invoice so it can be paid straight away.
        $invoice = $subscription->status === 'active'
            ? Invoice::where('subscription_id', $subscription->id)->where('status', 'issued')->latest('id')->first()
            : null;

        return response()->json([
            'data' => $subscription,
            'invoice' => $invoice,
            'message' => $subscription->status === 'trialing'
                ? "{$plan->name} it is — billing starts when your trial ends."
                : "You’re on {$plan->name}.",
        ]);
    }

    /** Open Razorpay Checkout for an invoice. */
    public function checkout(Invoice $invoice, PaymentService $payments): JsonResponse
    {
        return response()->json(['data' => $payments->checkout($invoice)]);
    }

    /** Checkout's success callback, verified by signature. */
    public function verifyPayment(Request $request, PaymentService $payments): JsonResponse
    {
        $validated = $request->validate([
            'razorpay_order_id' => 'required|string|max:64',
            'razorpay_payment_id' => 'required|string|max:64',
            'razorpay_signature' => 'required|string|max:128',
        ]);

        // The order must belong to this organisation.
        abort_unless(Payment::where('gateway_order_id', $validated['razorpay_order_id'])
            ->where('company_id', app(TenantContext::class)->id())->exists(), 404);

        $payment = $payments->confirm($validated['razorpay_order_id'], $validated['razorpay_payment_id'], $validated['razorpay_signature']);
        activity('billing')->causedBy($request->user())->event('payment_received')
            ->withProperties(['payment_id' => $payment->gateway_payment_id, 'amount' => $payment->amount])->log('Paid online');

        return response()->json(['data' => $payment, 'message' => 'Payment received — thank you!']);
    }

    /** A failed / dismissed Checkout attempt, for the record. */
    public function paymentFailed(Request $request, PaymentService $payments): JsonResponse
    {
        $validated = $request->validate([
            'razorpay_order_id' => 'required|string|max:64',
            'razorpay_payment_id' => 'nullable|string|max:64',
            'error' => 'nullable|string|max:500',
        ]);
        abort_unless(Payment::where('gateway_order_id', $validated['razorpay_order_id'])
            ->where('company_id', app(TenantContext::class)->id())->exists(), 404);

        $payments->failed($validated['razorpay_order_id'], $validated['razorpay_payment_id'] ?? null, $validated['error'] ?? null);

        return response()->json(['ok' => true]);
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
