<?php

/*
|--------------------------------------------------------------------------
| Industry starter templates
|--------------------------------------------------------------------------
|
| Nothing in the product is hardcoded to one vertical. These are the
| defaults a new branch starts with, chosen by the company's industry; every
| item becomes an ordinary, editable record the tenant owns.
|
| Keys a model doesn't (yet) have a column for are ignored, so templates can
| describe richer settings than the current schema stores.
|
*/

$standardLeave = [
    ['name' => 'Casual Leave', 'code' => 'CL', 'days_per_year' => 12, 'paid' => true, 'carry_forward' => false, 'accrual' => 'annual',
        'max_consecutive_days' => 3, 'color' => '#2563eb'],
    ['name' => 'Sick Leave', 'code' => 'SL', 'days_per_year' => 12, 'paid' => true, 'carry_forward' => false, 'accrual' => 'annual',
        'requires_document_after_days' => 2, 'color' => '#dc2626'],
    ['name' => 'Earned Leave', 'code' => 'EL', 'days_per_year' => 15, 'paid' => true, 'carry_forward' => true, 'max_carry_forward' => 45,
        'encashable' => true, 'accrual' => 'monthly', 'min_notice_days' => 3, 'sandwich_rule' => true, 'color' => '#059669'],
    ['name' => 'Compensatory Off', 'code' => 'CO', 'days_per_year' => 0, 'paid' => true, 'carry_forward' => false, 'accrual' => 'none',
        'color' => '#7c3aed'],
    ['name' => 'Loss of Pay', 'code' => 'LOP', 'days_per_year' => 0, 'paid' => false, 'carry_forward' => false, 'accrual' => 'none',
        'allow_negative' => true, 'color' => '#64748b'],
    // Maternity Benefit Act: 26 weeks, after 80 days' service; not pro-rated or split into half days.
    ['name' => 'Maternity Leave', 'code' => 'ML', 'days_per_year' => 182, 'paid' => true, 'carry_forward' => false, 'accrual' => 'annual',
        'applicable_gender' => 'female', 'min_service_days' => 80, 'prorate_on_joining' => false, 'allow_half_day' => false, 'color' => '#db2777'],
    ['name' => 'Paternity Leave', 'code' => 'PTL', 'days_per_year' => 5, 'paid' => true, 'carry_forward' => false, 'accrual' => 'annual',
        'applicable_gender' => 'male', 'prorate_on_joining' => false, 'allow_half_day' => false, 'color' => '#0891b2'],
];

$standardComponents = [
    ['name' => 'Basic', 'code' => 'BASIC', 'type' => 'earning', 'calculation_type' => 'fixed', 'pf_applicable' => true, 'esi_applicable' => true, 'taxable' => true, 'prorate' => true],
    ['name' => 'House Rent Allowance', 'code' => 'HRA', 'type' => 'earning', 'calculation_type' => 'percentage', 'default_value' => 40, 'esi_applicable' => true, 'taxable' => true, 'prorate' => true],
    ['name' => 'Conveyance Allowance', 'code' => 'CONV', 'type' => 'earning', 'calculation_type' => 'fixed', 'esi_applicable' => true, 'taxable' => true, 'prorate' => true],
    ['name' => 'Special Allowance', 'code' => 'SPL', 'type' => 'earning', 'calculation_type' => 'fixed', 'esi_applicable' => true, 'taxable' => true, 'prorate' => true],
    ['name' => 'Salary Advance Recovery', 'code' => 'ADV', 'type' => 'deduction', 'calculation_type' => 'fixed', 'prorate' => false],
];

$statutory = [
    ['rule_type' => 'PF', 'config_json' => ['wage_ceiling' => 15000, 'employee_rate' => 12, 'employer_rate' => 12, 'eps_rate' => 8.33, 'edli_rate' => 0.5, 'admin_rate' => 0.5]],
    ['rule_type' => 'ESI', 'config_json' => ['wage_ceiling' => 21000, 'employee_rate' => 0.75, 'employer_rate' => 3.25]],
];

return [

    'automotive' => [
        'label' => 'Automotive — bike & car showrooms, service centres',
        'week_off_days' => [2], // showrooms trade weekends; Tuesday is the common weekly off
        'departments' => [
            'Sales' => [
                ['Sales Executive', 1], ['Senior Sales Executive', 2], ['Team Leader - Sales', 3],
                ['Sales Manager', 4], ['Showroom Manager', 5],
            ],
            'Service' => [
                ['Technician', 1], ['Senior Technician', 2], ['Service Advisor', 2],
                ['Floor Supervisor', 3], ['Service Manager', 4],
            ],
            'Spares' => [
                ['Spares Executive', 1], ['Spares Counter Supervisor', 2], ['Spares Manager', 3],
            ],
            'Admin' => [
                ['Customer Relationship Executive', 1], ['Admin Executive', 1], ['Accountant', 2],
                ['HR Executive', 2], ['Admin Manager', 3],
            ],
        ],
        'shifts' => [
            ['name' => 'Showroom', 'start_time' => '09:30:00', 'end_time' => '19:00:00', 'break_minutes' => 60, 'grace_minutes' => 10, 'ot_threshold_minutes' => 60, 'absent_threshold_minutes' => 120, 'color' => '#2563eb'],
            ['name' => 'Workshop', 'start_time' => '08:30:00', 'end_time' => '17:30:00', 'break_minutes' => 60, 'grace_minutes' => 10, 'ot_threshold_minutes' => 60, 'absent_threshold_minutes' => 120, 'color' => '#0d9488'],
            ['name' => 'Workshop Late', 'start_time' => '11:00:00', 'end_time' => '20:00:00', 'break_minutes' => 60, 'grace_minutes' => 10, 'ot_threshold_minutes' => 60, 'absent_threshold_minutes' => 120, 'color' => '#7c3aed'],
        ],
        'leave_types' => $standardLeave,
        'salary_components' => array_merge($standardComponents, [
            ['name' => 'Sales Incentive', 'code' => 'INC', 'type' => 'earning', 'calculation_type' => 'fixed', 'taxable' => true, 'prorate' => false, 'is_variable' => true],
            ['name' => 'Service Incentive', 'code' => 'SINC', 'type' => 'earning', 'calculation_type' => 'fixed', 'taxable' => true, 'prorate' => false, 'is_variable' => true],
        ]),
        'statutory' => $statutory,
    ],

    'healthcare' => [
        'label' => 'Healthcare — hospitals, clinics, diagnostics',
        'week_off_days' => [], // 24x7 operations: weekly offs are rostered per person
        'departments' => [
            'Medical' => [['Resident Medical Officer', 3], ['Consultant', 4], ['Medical Superintendent', 5]],
            'Nursing' => [['Nursing Assistant', 1], ['Staff Nurse', 2], ['Head Nurse', 3], ['Nursing Superintendent', 4]],
            'Diagnostics' => [['Lab Technician', 1], ['Radiographer', 2], ['Lab Incharge', 3]],
            'Pharmacy' => [['Pharmacist', 2], ['Chief Pharmacist', 3]],
            'Front Office' => [['Front Office Executive', 1], ['Billing Executive', 1], ['Front Office Manager', 3]],
            'Administration' => [['Housekeeping Staff', 1], ['Admin Executive', 1], ['HR Executive', 2], ['Hospital Administrator', 4]],
        ],
        'shifts' => [
            ['name' => 'Morning', 'start_time' => '07:00:00', 'end_time' => '15:00:00', 'break_minutes' => 30, 'grace_minutes' => 5, 'ot_threshold_minutes' => 60, 'absent_threshold_minutes' => 120, 'color' => '#ea580c'],
            ['name' => 'Evening', 'start_time' => '15:00:00', 'end_time' => '23:00:00', 'break_minutes' => 30, 'grace_minutes' => 5, 'ot_threshold_minutes' => 60, 'absent_threshold_minutes' => 120, 'color' => '#db2777'],
            ['name' => 'Night', 'start_time' => '23:00:00', 'end_time' => '07:00:00', 'break_minutes' => 30, 'grace_minutes' => 5, 'ot_threshold_minutes' => 60, 'absent_threshold_minutes' => 120, 'color' => '#0891b2'],
            ['name' => 'General', 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'break_minutes' => 30, 'grace_minutes' => 10, 'ot_threshold_minutes' => 60, 'absent_threshold_minutes' => 120, 'color' => '#65a30d'],
        ],
        'leave_types' => $standardLeave,
        'salary_components' => array_merge($standardComponents, [
            ['name' => 'Night Shift Allowance', 'code' => 'NSA', 'type' => 'earning', 'calculation_type' => 'fixed', 'taxable' => true, 'prorate' => false, 'is_variable' => true],
            ['name' => 'On-call Allowance', 'code' => 'ONCALL', 'type' => 'earning', 'calculation_type' => 'fixed', 'taxable' => true, 'prorate' => false, 'is_variable' => true],
        ]),
        'statutory' => $statutory,
    ],

    'finance' => [
        'label' => 'Finance — banking, NBFC, insurance, microfinance',
        'week_off_days' => [0],
        'departments' => [
            'Branch Operations' => [['Customer Service Officer', 1], ['Operations Executive', 2], ['Branch Operations Manager', 4]],
            'Sales' => [['Relationship Officer', 1], ['Relationship Manager', 2], ['Area Sales Manager', 4]],
            'Credit' => [['Credit Officer', 2], ['Credit Manager', 3]],
            'Collections' => [['Collection Executive', 1], ['Collection Manager', 3]],
            'Compliance & Admin' => [['Admin Executive', 1], ['Compliance Officer', 3], ['Branch Manager', 5]],
        ],
        'shifts' => [
            ['name' => 'General', 'start_time' => '09:30:00', 'end_time' => '18:00:00', 'break_minutes' => 45, 'grace_minutes' => 10, 'ot_threshold_minutes' => 60, 'absent_threshold_minutes' => 120, 'color' => '#ca8a04'],
            ['name' => 'Field', 'start_time' => '09:00:00', 'end_time' => '18:30:00', 'break_minutes' => 60, 'grace_minutes' => 15, 'ot_threshold_minutes' => 60, 'absent_threshold_minutes' => 120, 'color' => '#4f46e5'],
        ],
        'leave_types' => $standardLeave,
        'salary_components' => array_merge($standardComponents, [
            ['name' => 'Performance Incentive', 'code' => 'PINC', 'type' => 'earning', 'calculation_type' => 'fixed', 'taxable' => true, 'prorate' => false, 'is_variable' => true],
            ['name' => 'Field Travel Allowance', 'code' => 'FTA', 'type' => 'earning', 'calculation_type' => 'fixed', 'taxable' => true, 'prorate' => true],
        ]),
        'statutory' => $statutory,
    ],

    'general' => [
        'label' => 'General business',
        'week_off_days' => [0],
        'departments' => [
            'Operations' => [['Executive', 1], ['Senior Executive', 2], ['Team Lead', 3], ['Operations Manager', 4]],
            'Sales & Marketing' => [['Sales Executive', 1], ['Sales Manager', 4]],
            'Accounts' => [['Accounts Executive', 1], ['Accountant', 2], ['Finance Manager', 4]],
            'HR & Admin' => [['Admin Executive', 1], ['HR Executive', 2], ['HR Manager', 4]],
        ],
        'shifts' => [
            ['name' => 'General', 'start_time' => '09:00:00', 'end_time' => '18:00:00', 'break_minutes' => 60, 'grace_minutes' => 10, 'ot_threshold_minutes' => 60, 'absent_threshold_minutes' => 120, 'color' => '#dc2626'],
        ],
        'leave_types' => $standardLeave,
        'salary_components' => $standardComponents,
        'statutory' => $statutory,
    ],

];
