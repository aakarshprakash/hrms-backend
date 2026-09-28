<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\RazorpayGateway;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Access\SessionPayload;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

/**
 * Unauthenticated endpoints: what the login page may offer (sign-up,
 * plans), self-serve sign-up into a trial, and Razorpay's payment webhook.
 */
class PublicSignupController extends Controller
{
    public function config(): JsonResponse
    {
        return response()->json(['data' => [
            'signup' => (bool) config('billing.public_signup'),
            'trial_days' => (int) config('billing.trial_days', 14),
            'industries' => Company::INDUSTRIES,
            'plans' => config('billing.public_signup')
                ? Plan::where('is_public', true)->where('is_active', true)->orderBy('sort_order')
                    ->get(['code', 'name', 'description', 'base_price', 'per_employee_price', 'included_employees'])
                : [],
        ]]);
    }

    public function signup(Request $request, TenantProvisioner $provisioner, TenantContext $context): JsonResponse
    {
        abort_unless(config('billing.public_signup'), 404);

        $validated = $request->validate([
            'company_name' => 'required|string|min:2|max:191',
            'industry' => ['nullable', 'string', 'in:' . implode(',', array_keys(Company::INDUSTRIES))],
            'state' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'admin_name' => 'required|string|min:2|max:191',
            'admin_email' => 'required|email|max:191|unique:users,email',
            'admin_phone' => ['nullable', 'string', 'max:20', 'regex:/^[+0-9 ()-]{8,20}$/'],
            'admin_password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'plan_code' => ['nullable', 'exists:plans,code'],
        ], ['admin_email.unique' => 'An account with this email already exists — sign in instead.']);

        $result = $provisioner->provision($validated + ['branch_name' => 'Head Office']);
        $admin = $result['admin'];

        $context->set($admin->company_id);
        $context->enforce();

        $expiresAt = ($minutes = config('sanctum.expiration')) ? now()->addMinutes((int) $minutes) : null;
        $token = $admin->createToken('signup', ['*'], $expiresAt)->plainTextToken;

        Log::info('Self-serve sign-up', ['company_id' => $admin->company_id, 'ip' => $request->ip()]);

        return response()->json([
            'data' => array_merge(SessionPayload::for($admin->fresh()), ['token' => $token, 'expires_at' => $expiresAt?->toIso8601String()]),
            'message' => 'Your organisation is ready.',
        ], 201);
    }

    /** Razorpay: payment_link.paid → the invoice is paid. */
    public function razorpayWebhook(Request $request, RazorpayGateway $razorpay, InvoiceService $invoices, TenantContext $context): JsonResponse
    {
        abort_unless($razorpay->validWebhook($request->getContent(), $request->header('X-Razorpay-Signature')), 400, 'Invalid signature.');

        if ($request->input('event') === 'payment_link.paid') {
            $number = $request->input('payload.payment_link.entity.reference_id');
            $paymentId = $request->input('payload.payment.entity.id');
            $invoice = $context->withoutScoping(fn () => Invoice::where('number', $number)->first());

            if ($invoice && $invoice->status === 'issued') {
                $context->runAs($invoice->company_id, fn () => $invoices->markPaid($invoice, 'razorpay', $paymentId));
            }
        }

        return response()->json(['ok' => true]);
    }
}
