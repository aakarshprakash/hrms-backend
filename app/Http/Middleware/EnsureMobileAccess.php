<?php

namespace App\Http\Middleware;

use App\Support\Mobile\MobileAccess;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requests from the mobile app (X-Client: mobile) stop as soon as the app is
 * switched off for the organisation, not only at the next sign-in. Runs after
 * the tenant is resolved; web requests pass straight through.
 */
class EnsureMobileAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! MobileAccess::isMobile($request) || ! $request->user()) {
            return $next($request);
        }

        if ($refusal = MobileAccess::refusal($request, app(TenantContext::class)->company())) {
            [$code, $message] = $refusal;

            return response()->json(['message' => $message, 'code' => $code], 403);
        }

        return $next($request);
    }
}
