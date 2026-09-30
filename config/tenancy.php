<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant-owned tables
    |--------------------------------------------------------------------------
    |
    | Every table here carries a company_id and is filtered to the current
    | tenant. `exists:` / `unique:` validation rules against these tables are
    | automatically restricted to the acting tenant (TenantAwarePresenceVerifier),
    | so a request can never reference -- or collide with -- another tenant's
    | rows by id or code.
    |
    | `users` is deliberately absent: login emails are unique platform-wide.
    |
    */

    'tables' => [
        'branches',
        'departments',
        'designations',
        'employees',
        'employee_documents',
        'holidays',
        'shifts',
        'employee_shifts',
        'shift_rosters',
        'shift_swap_requests',
        'attendance_daily',
        'raw_punches',
        'attendance_regularizations',
        'leave_types',
        'leave_balances',
        'leave_transactions',
        'leaves',
        'approval_flows',
        'approval_actions',
        'overtime_rules',
        'overtime_requests',
        'salary_components',
        'salary_structures',
        'statutory_rules',
        'payroll_runs',
        'payslips',
        'payroll_run_adjustments',
        'certificate_templates',
        'certificate_template_versions',
        'certificate_requests',
        'issued_certificates',
        'biometric_configs',
        'biometric_sync_logs',
        'notification_settings',
        'notification_logs',
    ],

    /*
    | Tables whose rows are either global (company_id NULL, shared by every
    | tenant) or owned by one tenant. Validation sees global rows plus the
    | acting tenant's own.
    */

    'shared_tables' => [
        'roles',
    ],

    /*
    | Header a platform admin sends to act inside a tenant ("support mode").
    */

    'support_header' => 'X-Company-Id',

];
