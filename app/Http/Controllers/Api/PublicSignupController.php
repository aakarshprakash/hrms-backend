<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\RazorpayGateway;
use App\Services\Tenancy\SignupVerificationService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Access\SessionPayload;
use App\Support\Billing\Cycles;
use App\Support\Billing\Features;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Unauthenticated endpoints: what the sign-up page may offer (plans, cycles,
 * trial), the onboarding flow -- verify email by OTP, then create the
 * workspace on a trial of the chosen plan -- and Razorpay's webhook.
 */
class PublicSignupController extends Controller
{
    public function config(): JsonResponse
    {
        $open = (bool) config('billing.public_signup');

        return response()->json(['data' => [
            'signup' => $open,
            'trial_days' => (int) config('billing.trial_days', 14),
            'trial_plan' => config('billing.trial_plan'),
            'industries' => Company::INDUSTRIES,
            'cycles' => Cycles::all(),
            'gst_rate' => (float) config('billing.gst_rate', 18),
            'feature_labels' => Features::ALL,
            'plans' => $open
                ? Plan::where('is_public', true)->where('is_active', true)->orderBy('sort_order')
                    ->get(['code', 'name', 'description', 'base_price', 'per_employee_price', 'included_employees', 'features', 'limits'])
                : [],
        ]]);
    }

    /** Step 1: email a one-time code to the would-be admin. */
    public function sendCode(Request $request, SignupVerificationService $verification): JsonResponse
    {
        abort_unless(config('billing.public_signup'), 404);
        $validated = $request->validate(['email' => 'required|email:rfc|max:191']);

        $result = $verification->send($validated['email'], $request->ip());

        return response()->json(['data' => $result, 'message' => 'We’ve emailed you a 6-digit code.']);
    }

    /** Step 1b: the code is right → a token that proves the email for sign-up. */
    public function verifyCode(Request $request, SignupVerificationService $verification): JsonResponse
    {
        abort_unless(config('billing.public_signup'), 404);
        $validated = $request->validate([
            'email' => 'required|email|max:191',
            'code' => 'required|digits:6',
        ]);

        $token = $verification->verify($validated['email'], $validated['code']);

        return response()->json(['data' => ['verification_token' => $token], 'message' => 'Email verified.']);
    }

    /** Final step: the workspace, its admin and a trial on the chosen plan. */
    public function signup(Request $request, TenantProvisioner $provisioner, TenantContext $context, SignupVerificationService $verification): JsonResponse
    {
        abort_unless(config('billing.public_signup'), 404);

        $validated = $request->validate([
            'company_name' => 'required|string|min:2|max:191',
            'industry' => ['nullable', 'string', 'in:' . implode(',', array_keys(Company::INDUSTRIES))],
            'company_phone' => ['nullable', 'string', 'max:20', 'regex:/^[+0-9 ()-]{8,20}$/'],
            'address_line1' => 'nullable|string|max:191',
            'state' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:12',
            'admin_name' => 'required|string|min:2|max:191',
            'admin_email' => 'required|email|max:191|unique:users,email',
            'admin_phone' => ['nullable', 'string', 'max:20', 'regex:/^[+0-9 ()-]{8,20}$/'],
            'admin_password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'plan_code' => ['required', Rule::exists('plans', 'code')->where('is_public', true)->where('is_active', true)],
            'billing_cycle' => ['required', Rule::in(Cycles::keys())],
            'verification_token' => 'required|string',
        ], ['admin_email.unique' => 'An account with this email already exists — sign in instead.']);

        abort_unless($verification->isValid($validated['admin_email'], $validated['verification_token']), 422,
            'Your email verification has expired. Please verify your email again.');

        $result = $provisioner->provision([
            'phone' => $validated['company_phone'] ?? null,
            'branch_name' => 'Head Office',
            'plan_confirmed' => true,
        ] + $validated);
        $admin = $result['admin'];

        $verification->consume($validated['admin_email'], $validated['verification_token']);
        $admin->forceFill(['email_verified_at' => now()])->save();

        $context->set($admin->company_id);
        $context->enforce();

        $expiresAt = ($minutes = config('sanctum.expiration')) ? now()->addMinutes((int) $minutes) : null;
        $token = $admin->createToken('signup', ['*'], $expiresAt)->plainTextToken;

        Log::info('Self-serve sign-up', ['company_id' => $admin->company_id, 'plan' => $validated['plan_code'], 'ip' => $request->ip()]);

        return response()->json([
            'data' => array_merge(SessionPayload::for($admin->fresh()), ['token' => $token, 'expires_at' => $expiresAt?->toIso8601String()]),
            'message' => 'Your workspace is ready.',
        ], 201);
    }

    /**
     * Razorpay webhook. Checkout orders: payment.captured / order.paid settle
     * the order's invoice, payment.failed records the failure. Payment links:
     * payment_link.paid settles the referenced invoice.
     */
    public function razorpayWebhook(Request $request, RazorpayGateway $razorpay, InvoiceService $invoices, PaymentService $payments, TenantContext $context): JsonResponse
    {
        abort_unless($razorpay->validWebhook($request->getContent(), $request->header('X-Razorpay-Signature')), 400, 'Invalid signature.');

        $event = $request->input('event');
        $payment = $request->input('payload.payment.entity', []);
        $orderId = $payment['order_id'] ?? $request->input('payload.order.entity.id');

        try {
            if (in_array($event, ['payment.captured', 'order.paid'], true) && $orderId && isset($payment['id'])) {
                $payments->captured($orderId, $payment['id'], $payment['method'] ?? null);
            } elseif ($event === 'payment.failed' && $orderId) {
                $payments->failed($orderId, $payment['id'] ?? null, $payment['error_description'] ?? null);
            } elseif ($event === 'payment_link.paid') {
                $number = $request->input('payload.payment_link.entity.reference_id');
                $invoice = $context->withoutScoping(fn () => Invoice::where('number', $number)->first());

                if ($invoice && $invoice->status === 'issued') {
                    $context->runAs($invoice->company_id, fn () => $invoices->markPaid($invoice, 'razorpay', $payment['id'] ?? null));
                }
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // An order we didn't create (another product on the same account): ignore.
        }

        return response()->json(['ok' => true]);
    }
}
