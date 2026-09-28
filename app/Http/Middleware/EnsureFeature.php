<?php

namespace App\Http\Middleware;

use App\Support\Billing\Features;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `feature:payroll` -- the route belongs to a module the organisation's
 * plan must include. Answers 403 with code FEATURE_NOT_IN_PLAN so the app
 * can show an upgrade prompt instead of an error.
 */
class EnsureFeature
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $company = app(TenantContext::class)->company();

        foreach ($features as $feature) {
            if ($company && ! Features::has($company, $feature)) {
                return response()->json([
                    'message' => (Features::ALL[$feature] ?? ucfirst($feature)) . ' is not included in your plan.',
                    'code' => 'FEATURE_NOT_IN_PLAN',
                    'feature' => $feature,
                ], 403);
            }
        }

        return $next($request);
    }
}
