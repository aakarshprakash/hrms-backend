<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission catalog that dynamic roles are built from. Grouped by module;
 * safe to re-run (idempotent). Super admin bypasses permissions entirely via
 * Gate::before, so it is not listed here.
 */
class PermissionCatalogSeeder extends Seeder
{
    public const CATALOG = [
        'Employees' => [
            'employees.view' => 'View the employee directory and profiles',
            'employees.manage' => 'Create, edit and terminate employees',
        ],
        'Attendance' => [
            'attendance.view' => 'View team attendance records',
            'attendance.manage' => 'Mark and correct attendance manually',
        ],
        'Leave' => [
            'leaves.view' => 'View team leave requests',
            'leaves.approve' => 'Approve or reject leave requests',
        ],
        'Shifts' => [
            'shifts.manage' => 'Manage shifts, rosters and holidays',
        ],
        'Payroll' => [
            'payroll.view' => 'View payroll summaries and payslips',
            'payroll.manage' => 'Run payroll and manage salary structures',
        ],
        'Certificates' => [
            'certificates.manage' => 'Manage certificate templates and requests',
        ],
        'Organisation' => [
            'departments.manage' => 'Manage departments and designations',
        ],
        'Administration' => [
            'users.manage' => 'Manage user accounts in own branch',
            'insights.view' => 'View AI insights and analytics',
            'settings.manage' => 'Manage branches and company settings',
        ],
    ];

    public const ROLE_DEFAULTS = [
        'branch_admin' => [
            'employees.view', 'employees.manage', 'attendance.view', 'attendance.manage',
            'leaves.view', 'leaves.approve', 'shifts.manage', 'payroll.view', 'payroll.manage',
            'certificates.manage', 'departments.manage', 'users.manage', 'insights.view', 'settings.manage',
        ],
        'hr' => [
            'employees.view', 'employees.manage', 'attendance.view', 'attendance.manage',
            'leaves.view', 'leaves.approve', 'shifts.manage', 'payroll.view',
            'certificates.manage', 'departments.manage', 'insights.view',
        ],
        'manager' => [
            'employees.view', 'attendance.view', 'attendance.manage', 'leaves.view', 'leaves.approve', 'insights.view',
        ],
        'employee' => [],
    ];

    public function run(): void
    {
        foreach (self::CATALOG as $group => $permissions) {
            foreach ($permissions as $name => $description) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }
        }

        foreach (self::ROLE_DEFAULTS as $roleName => $permissions) {
            $role = Role::where('name', $roleName)->first();
            if ($role) {
                $role->syncPermissions($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
