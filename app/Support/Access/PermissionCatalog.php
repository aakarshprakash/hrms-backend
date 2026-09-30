<?php

namespace App\Support\Access;

/**
 * Every permission a role can hold, grouped by module, plus the defaults for
 * the built-in roles. Tenant admins hold everything implicitly (Gate::before);
 * platform abilities ("platform.*") are never part of this catalog.
 */
final class PermissionCatalog
{
    public const CATALOG = [
        'Employees' => [
            'employees.view' => 'View the employee directory and profiles',
            'employees.manage' => 'Create, edit and terminate employees',
            'employees.sensitive' => 'View and edit bank, PAN, Aadhaar and other sensitive details',
        ],
        'Organisation' => [
            'departments.manage' => 'Manage departments and designations',
        ],
        'Attendance' => [
            'attendance.view' => 'View team attendance records',
            'attendance.manage' => 'Mark, correct and reprocess attendance',
        ],
        'Shifts' => [
            'shifts.manage' => 'Manage shifts, rosters and holidays',
        ],
        'Leave' => [
            'leaves.view' => 'View team leave requests',
            'leaves.approve' => 'Approve or reject leave, overtime and regularization requests',
            'leaves.manage' => 'Configure leave types, policies and balances',
        ],
        'Payroll' => [
            'payroll.view' => 'View payroll runs, salary structures and payslips',
            'payroll.manage' => 'Run payroll and manage salary structures',
            'payroll.finalize' => 'Lock payroll runs and publish payslips',
        ],
        'Reports' => [
            'reports.view' => 'View statutory, compliance and HR reports',
        ],
        'Certificates' => [
            'certificates.manage' => 'Manage certificate templates and requests',
        ],
        'Administration' => [
            'users.manage' => 'Manage user accounts',
            'roles.manage' => 'Create and edit roles and permissions',
            'settings.manage' => 'Manage branches, company and integration settings',
            'notifications.manage' => 'Configure SMS / WhatsApp notifications',
            'insights.view' => 'View AI insights and analytics',
            'audit.view' => 'View the audit log',
            'billing.manage' => 'Manage subscription and billing',
        ],
    ];

    public const ROLE_DEFAULTS = [
        Roles::BRANCH_ADMIN => [
            'employees.view', 'employees.manage', 'employees.sensitive', 'departments.manage',
            'attendance.view', 'attendance.manage', 'shifts.manage',
            'leaves.view', 'leaves.approve', 'leaves.manage',
            'payroll.view', 'payroll.manage', 'payroll.finalize', 'reports.view',
            'certificates.manage', 'users.manage', 'settings.manage', 'notifications.manage', 'insights.view',
        ],
        Roles::HR => [
            'employees.view', 'employees.manage', 'employees.sensitive', 'departments.manage',
            'attendance.view', 'attendance.manage', 'shifts.manage',
            'leaves.view', 'leaves.approve', 'leaves.manage',
            'payroll.view', 'payroll.manage', 'reports.view', 'certificates.manage', 'insights.view',
        ],
        Roles::MANAGER => [
            'employees.view', 'attendance.view', 'attendance.manage', 'leaves.view', 'leaves.approve', 'insights.view',
        ],
        Roles::EMPLOYEE => [],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::CATALOG)));
    }
}
