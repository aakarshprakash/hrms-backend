<?php

namespace App\Support\Access;

use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Who may hand out which role. Prevents privilege escalation: below the
 * tenant admin, a user can only grant roles whose permissions are a subset
 * of their own and whose data scope is no broader than theirs.
 */
final class RoleGrants
{
    public static function canGrant(User $actor, Role $role): bool
    {
        if ($role->name === Roles::SUPER_ADMIN) {
            return false;
        }

        if ($actor->isTenantAdmin() || $actor->isPlatformAdmin()) {
            return true;
        }

        if ($role->name === Roles::TENANT_ADMIN) {
            return false;
        }

        if (Roles::SCOPE_RANK[$role->effectiveDataScope()] > Roles::SCOPE_RANK[$actor->dataScope()]) {
            return false;
        }

        $actorPermissions = $actor->getAllPermissions()->pluck('name')->all();

        return empty(array_diff($role->permissions->pluck('name')->all(), $actorPermissions));
    }

    /** The named role if it exists for this tenant and $actor may grant it; aborts otherwise. */
    public static function assignable(User $actor, string $name): Role
    {
        $role = Role::availableTo(app(TenantContext::class)->id())->where('name', $name)->first();

        abort_unless($role, 422, 'That role is not available.');
        abort_unless(self::canGrant($actor, $role), 403, 'You cannot grant a role with more access than your own.');

        return $role;
    }
}
