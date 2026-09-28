<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Local development data:
 *   admin@hrms.test / password   platform super admin (no organisation)
 *   owner@acme.test / password   tenant admin of "Acme Corp"
 *   hr@hrms.test    / password   HR at Acme's Head Office
 * plus the demo-ready automotive tenant when DemoAutomotiveSeeder exists.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(PermissionCatalogSeeder::class);

        if (class_exists(PlanSeeder::class)) {
            $this->call(PlanSeeder::class);
        }

        $this->call(CompanyBranchSeeder::class);
        $this->call(RolePermissionSeeder::class);

        if (class_exists(DemoAutomotiveSeeder::class)) {
            $this->call(DemoAutomotiveSeeder::class);
        }
    }
}
