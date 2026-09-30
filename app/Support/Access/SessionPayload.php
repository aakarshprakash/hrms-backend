<?php

namespace App\Support\Access;

use App\Models\Branch;
use App\Models\User;
use App\Support\Billing\Features;
use App\Support\Tenancy\TenantContext;

/**
 * What the SPA needs to render a session: who the user is, what they may do
 * (effective permissions, not just role names), whose data they see, their
 * organisation, the branches they can switch between, and which modules the
 * organisation's plan includes. Shared by login and /auth/me.
 */
class SessionPayload
{
    public static function for(User $user): array
    {
        $context = app(TenantContext::class);
        $user->loadMissing(['employee', 'branch', 'roles']);

        $permissions = ($user->isTenantAdmin() || $user->isPlatformAdmin())
            ? PermissionCatalog::all()
            : $user->getAllPermissions()->pluck('name')->values()->all();

        $company = $context->company();

        $branches = $company
            ? Branch::with('company:id,name')
                ->when($user->accessibleBranchIds() !== null, fn ($q) => $q->whereIn('id', $user->accessibleBranchIds()))
                ->orderBy('name')
                ->get()
            : collect();

        return [
            'user' => array_merge($user->toArray(), [
                'roles' => $user->getRoleNames(),
                'permissions' => $permissions,
                'data_scope' => $user->dataScope(),
                'is_platform_admin' => $user->isPlatformAdmin(),
                'is_tenant_admin' => $user->isTenantAdmin(),
            ]),
            'company' => $company ? [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'industry' => $company->industry,
                'logo_url' => $company->logo_url,
                'status' => $company->status,
                'timezone' => $company->timezone,
                'currency_code' => $company->currency_code,
                'is_demo' => $company->is_demo,
                'onboarded' => $company->onboarded_at !== null,
            ] : null,
            'branches' => $branches,
            'features' => $company ? Features::enabledFor($company) : [],
            'subscription' => $company ? Features::subscriptionSummary($company) : null,
            'support_mode' => $user->isPlatformAdmin() && $company !== null,
        ];
    }
}
