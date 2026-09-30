<?php

namespace App\Providers;

use App\Models\Employee;
use App\Models\User;
use App\Policies\EmployeePolicy;
use App\Services\HtmlPurifierService;
use App\Support\Tenancy\TenantAwarePresenceVerifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Builder;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends AuthServiceProvider
{
    protected $policies = [
        Employee::class => EmployeePolicy::class,
    ];

    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->scoped(\App\Services\Leave\LeaveYear::class);
        $this->app->scoped(\App\Services\Notifications\Notifier::class);

        // Subscriptions decide which modules and quotas a tenant has (see Features / Limits).
        $this->app->scoped(\App\Services\Billing\BillingFeatureResolver::class);
        $this->app->alias(\App\Services\Billing\BillingFeatureResolver::class, 'billing.features');

        $this->app->singleton(HtmlPurifierService::class, function () {
            return new HtmlPurifierService();
        });

        // Every exists:/unique: rule becomes tenant-aware.
        $this->app->extend('validation.presence', function ($verifier, $app) {
            return new TenantAwarePresenceVerifier($app['db']);
        });
    }

    public function boot(): void
    {
        Builder::defaultStringLength(191);
        $this->registerPolicies();

        // Platform abilities belong to the platform operator alone. Inside a
        // tenant, the tenant admin (and the platform admin in support mode)
        // may do anything; tenant isolation is enforced by the data layer,
        // not by this grant.
        Gate::before(function (User $user, string $ability) {
            if (str_starts_with($ability, 'platform.')) {
                return $user->isPlatformAdmin();
            }

            if ($user->isPlatformAdmin() || $user->isTenantAdmin()) {
                return true;
            }

            return null;
        });

        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by($email . '|' . $request->ip()),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });

        RateLimiter::for('signup', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));
    }
}
