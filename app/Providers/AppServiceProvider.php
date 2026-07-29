<?php

namespace App\Providers;

use App\Models\Employee;
use App\Policies\EmployeePolicy;
use App\Services\HtmlPurifierService;
use Illuminate\Database\Schema\Builder;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends AuthServiceProvider
{
    protected $policies = [
        Employee::class => EmployeePolicy::class,
    ];

    public function register(): void
    {
        $this->app->singleton(HtmlPurifierService::class, function () {
            return new HtmlPurifierService();
        });
    }

    public function boot(): void
    {
        Builder::defaultStringLength(191);
        $this->registerPolicies();

        // Super admins bypass every permission check.
        Gate::before(function ($user) {
            return ($user->is_super_admin || $user->hasRole('super_admin')) ? true : null;
        });
    }
}
