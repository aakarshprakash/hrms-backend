<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant for an authenticated API request.
 *
 * Registered ahead of SubstituteBindings in the middleware priority list,
 * so route-model binding ({employee}, {payslip}, ...) already resolves
 * through the tenant scope -- another tenant's id is a 404, not a leak.
 *
 * - Tenant users act for their own company, always.
 * - The platform admin has no company; they may act inside one ("support
 *   mode") by sending the X-Company-Id header. Without it, every tenant
 *   query fails closed.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->is_active === false) {
            return $this->deny('ACCOUNT_DISABLED', 'Your account has been deactivated. Contact your administrator.', 403);
        }

        if ($user->isPlatformAdmin()) {
            $header = $request->header(config('tenancy.support_header', 'X-Company-Id'));
            $companyId = ctype_digit((string) $header) ? (int) $header : null;

            if ($companyId !== null && ! $this->context->withoutScoping(fn () => Company::whereKey($companyId)->exists())) {
                return $this->deny('TENANT_NOT_FOUND', 'Organisation not found.', 404);
            }

            $this->context->set($companyId);
            $this->context->enforce();

            return $next($request);
        }

        if ($user->company_id === null) {
            return $this->deny('NO_TENANT', 'This account is not attached to an organisation.', 403);
        }

        $this->context->set($user->company_id);
        $this->context->enforce();

        $company = $this->context->company();

        if (! $company || ! $company->isActive()) {
            return $this->deny(
                'TENANT_SUSPENDED',
                $company?->status === Company::STATUS_CANCELLED
                    ? 'This organisation\'s account has been closed.'
                    : 'This organisation\'s account is suspended. Please contact support.',
                403
            );
        }

        return $next($request);
    }

    /**
     * Runs after the response is sent (including streamed downloads), so a
     * long-lived process -- or the next request in a test -- starts clean.
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->context->reset();
    }

    private function deny(string $code, string $message, int $status): Response
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
