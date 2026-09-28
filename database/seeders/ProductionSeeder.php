<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Access\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Idempotent bootstrap run automatically on every deploy.
 *
 * - Ensures built-in roles, permissions and subscription plans exist.
 * - Creates the platform operator account ONLY if PLATFORM_ADMIN_EMAIL and
 *   PLATFORM_ADMIN_PASSWORD are set and no such user exists yet. It never
 *   modifies existing users -- in particular it never re-grants
 *   super_admin to anyone (pre-SaaS "super admins" were converted to
 *   tenant admins of their company by migration, deliberately).
 *
 * Alternatively create the operator interactively:
 *   php artisan platform:admin you@example.com
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionCatalogSeeder::class);

        if (class_exists(PlanSeeder::class)) {
            $this->call(PlanSeeder::class);
        }

        $email = env('PLATFORM_ADMIN_EMAIL');
        $password = env('PLATFORM_ADMIN_PASSWORD');

        if (! $email || ! $password || User::where('email', $email)->exists()) {
            return;
        }

        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => $email,
            'password' => Hash::make($password),
            'is_super_admin' => true,
            'user_type' => 'system',
            'must_change_password' => true,
        ]);

        $admin->assignRole(Roles::SUPER_ADMIN);

        $this->command?->warn("Platform admin {$email} created. Remove PLATFORM_ADMIN_PASSWORD from .env now.");
    }
}
