<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\Access\PermissionCatalog;
use App\Support\Access\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ensures every built-in role and catalog permission exists. Idempotent and
 * safe on every deploy: a built-in role only receives its default
 * permissions when it is first created, so edits a tenant makes to a role's
 * permissions are never reverted. (Permissions added in later releases are
 * granted to existing roles by the migration that introduces them.)
 */
class PermissionCatalogSeeder extends Seeder
{
    /** @deprecated use PermissionCatalog::CATALOG */
    public const CATALOG = PermissionCatalog::CATALOG;

    /** @deprecated use PermissionCatalog::ROLE_DEFAULTS */
    public const ROLE_DEFAULTS = PermissionCatalog::ROLE_DEFAULTS;

    public function run(): void
    {
        foreach (PermissionCatalog::all() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (Roles::SYSTEM as $name => [$display, $scope, $description]) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

            $role->forceFill([
                'display_name' => $display,
                'description' => $description,
                'data_scope' => $scope,
                'is_system' => true,
                'company_id' => null,
            ])->save();

            if ($role->wasRecentlyCreated && isset(PermissionCatalog::ROLE_DEFAULTS[$name])) {
                $role->syncPermissions(PermissionCatalog::ROLE_DEFAULTS[$name]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
