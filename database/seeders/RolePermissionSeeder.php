<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\Access\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Development accounts. Roles themselves come from PermissionCatalogSeeder.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Platform operator: belongs to no organisation, never an employee.
        $platform = User::firstOrCreate(['email' => 'admin@hrms.test'], [
            'name' => 'Platform Admin',
            'password' => Hash::make('password'),
            'is_super_admin' => true,
            'user_type' => 'system',
        ]);
        $platform->syncRoles([Roles::SUPER_ADMIN]);

        $acme = Company::where('slug', 'acme-corp')->first();
        if (! $acme) {
            return;
        }
        $headOffice = $acme->branches()->orderBy('id')->first();

        $owner = User::firstOrCreate(['email' => 'owner@acme.test'], [
            'name' => 'Acme Owner',
            'password' => Hash::make('password'),
            'user_type' => 'system',
            'branch_id' => $headOffice->id,
        ]);
        $owner->syncRoles([Roles::TENANT_ADMIN]);

        // Branch HR is a staff member (employee login) for testing branch scope.
        $hrUser = User::firstOrCreate(['email' => 'hr@hrms.test'], [
            'name' => 'Jane HR',
            'password' => Hash::make('password'),
            'branch_id' => $headOffice->id,
            'user_type' => 'employee',
        ]);
        $hrUser->syncRoles([Roles::HR]);

        $hrEmployee = Employee::firstOrCreate(
            ['company_id' => $acme->id, 'employee_code' => 'EMP002'],
            [
                'branch_id' => $headOffice->id,
                'first_name' => 'Jane',
                'last_name' => 'HR',
                'email' => 'hr@hrms.test',
                'date_of_joining' => now()->toDateString(),
                'status' => 'active',
                'user_id' => $hrUser->id,
            ]
        );

        $hrUser->update(['employee_id' => $hrEmployee->id]);
    }
}
