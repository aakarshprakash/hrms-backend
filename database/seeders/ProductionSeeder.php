<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Minimal, idempotent bootstrap for a fresh production database -- unlike
 * RolePermissionSeeder/CompanyBranchSeeder (dev fixtures with fake company
 * data), this only creates the roles/permissions and a single real
 * super_admin account. That account logs in and uses Quick Setup to create
 * the actual company/branch data interactively. Safe to re-run.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['super_admin', 'branch_admin', 'hr', 'manager', 'employee'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        $this->call(PermissionCatalogSeeder::class);

        $admin = User::updateOrCreate(
            ['email' => 'admin@peoplenex.online'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('admin123'),
                'is_super_admin' => true,
                'user_type' => 'system',
            ]
        );

        $admin->syncRoles(['super_admin']);

        $this->command->warn('Change the admin@peoplenex.online password immediately after first login -- admin123 is a bootstrap default, not a real credential.');
    }
}
