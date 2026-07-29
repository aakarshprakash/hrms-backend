<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Create roles
        $roles = ['super_admin', 'branch_admin', 'hr', 'manager', 'employee'];

        foreach ($roles as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        // Super admin is a SYSTEM user (solution operator) — it must never
        // appear in the employee directory, so no Employee record is created.
        $superAdminUser = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@hrms.test',
            'password' => Hash::make('password'),
            'is_super_admin' => true,
            'user_type' => 'system',
        ]);

        $superAdminUser->assignRole('super_admin');

        // Branch HR is a staff member (employee login) for testing branch scope
        $hrUser = User::create([
            'name' => 'Jane HR',
            'email' => 'hr@hrms.test',
            'password' => Hash::make('password'),
            'branch_id' => 1,
            'user_type' => 'employee',
        ]);

        $hrUser->assignRole('hr');

        $hrEmployee = Employee::create([
            'branch_id' => 1,
            'employee_code' => 'EMP002',
            'first_name' => 'Jane',
            'last_name' => 'HR',
            'email' => 'hr@hrms.test',
            'date_of_joining' => now()->toDateString(),
            'status' => 'active',
        ]);

        $hrUser->update(['employee_id' => $hrEmployee->id]);
        $hrEmployee->update(['user_id' => $hrUser->id]);
    }
}
