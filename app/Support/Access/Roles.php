<?php

namespace App\Support\Access;

/**
 * Built-in roles and their fixed data scopes. The scope of a built-in role
 * is defined here, not in the database, so it can't drift or be edited into
 * something broader; tenant-defined custom roles carry their own
 * roles.data_scope.
 */
final class Roles
{
    public const SUPER_ADMIN = 'super_admin';

    public const TENANT_ADMIN = 'tenant_admin';

    public const BRANCH_ADMIN = 'branch_admin';

    public const HR = 'hr';

    public const MANAGER = 'manager';

    public const EMPLOYEE = 'employee';

    /** Data scopes, broadest first. */
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_BRANCH = 'branch';

    public const SCOPE_TEAM = 'team';

    public const SCOPE_SELF = 'self';

    public const SCOPE_RANK = [
        self::SCOPE_COMPANY => 4,
        self::SCOPE_BRANCH => 3,
        self::SCOPE_TEAM => 2,
        self::SCOPE_SELF => 1,
    ];

    public const SYSTEM = [
        self::SUPER_ADMIN => ['Super Admin', self::SCOPE_COMPANY, 'Platform operator with access to every tenant.'],
        self::TENANT_ADMIN => ['Tenant Admin', self::SCOPE_COMPANY, 'Full control of the organisation, all branches.'],
        self::BRANCH_ADMIN => ['Branch Admin', self::SCOPE_BRANCH, 'Runs their assigned branch(es).'],
        self::HR => ['HR', self::SCOPE_BRANCH, 'HR operations for their branch(es).'],
        self::MANAGER => ['Manager', self::SCOPE_TEAM, 'Their reporting team only.'],
        self::EMPLOYEE => ['Employee', self::SCOPE_SELF, 'Self-service for their own records.'],
    ];

    /** Roles a tenant can hand out (never the platform role). */
    public const TENANT_ASSIGNABLE = [
        self::TENANT_ADMIN, self::BRANCH_ADMIN, self::HR, self::MANAGER, self::EMPLOYEE,
    ];

    public static function isSystem(string $role): bool
    {
        return array_key_exists($role, self::SYSTEM);
    }

    public static function scopeOf(string $role): ?string
    {
        return self::SYSTEM[$role][1] ?? null;
    }

    public static function broadest(array $scopes): string
    {
        $best = self::SCOPE_SELF;

        foreach ($scopes as $scope) {
            if ((self::SCOPE_RANK[$scope] ?? 0) > self::SCOPE_RANK[$best]) {
                $best = $scope;
            }
        }

        return $best;
    }
}
