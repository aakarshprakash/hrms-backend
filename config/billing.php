<?php

/*
|--------------------------------------------------------------------------
| Subscriptions & billing
|--------------------------------------------------------------------------
|
| Plans here are the *starting* catalogue: PlanSeeder creates any plan that
| doesn't exist yet and never overwrites one the platform admin has edited.
| Prices are in INR, per month, before GST.
|
*/

return [

    'currency' => 'INR',

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    // New tenants start their trial on this plan.
    'trial_plan' => env('BILLING_TRIAL_PLAN', 'growth'),

    // Days after an invoice falls due before the subscription is past due.
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    // Yearly billing: 12 months for the price of this many.
    'yearly_months_charged' => 10,

    // Self-serve sign-up from the login page. Off unless enabled.
    'public_signup' => (bool) env('PUBLIC_SIGNUP', false),

    /*
    | Modules a tenant keeps without a live subscription (trial over, or
    | cancelled): people keep punching in and seeing their payslips.
    */
    'free_features' => ['core', 'self_service'],

    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'description' => 'One location: attendance, leave, shifts and self-service.',
            'base_price' => 999,
            'per_employee_price' => 49,
            'included_employees' => 20,
            'features' => ['core', 'self_service', 'biometric', 'shifts', 'certificates'],
            'limits' => ['branches' => 1, 'employees' => 50],
            'sort_order' => 1,
        ],
        'growth' => [
            'name' => 'Growth',
            'description' => 'Multi-branch payroll with PF / ESI / PT / TDS and SMS & WhatsApp alerts.',
            'base_price' => 1999,
            'per_employee_price' => 69,
            'included_employees' => 25,
            'features' => ['core', 'self_service', 'biometric', 'shifts', 'certificates', 'payroll', 'statutory',
                'notifications', 'audit_log', 'multi_branch'],
            'limits' => ['branches' => 10, 'employees' => 500],
            'sort_order' => 2,
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'description' => 'Everything, unlimited branches and people, AI insights.',
            'base_price' => 4999,
            'per_employee_price' => 89,
            'included_employees' => 50,
            'features' => ['core', 'self_service', 'biometric', 'shifts', 'certificates', 'payroll', 'statutory',
                'notifications', 'audit_log', 'multi_branch', 'insights'],
            'limits' => ['branches' => null, 'employees' => null],
            'sort_order' => 3,
        ],
        // Organisations that were on the product before billing existed.
        'legacy' => [
            'name' => 'Founding customer',
            'description' => 'Every module, no limits — for organisations from before plans existed.',
            'base_price' => 0,
            'per_employee_price' => 0,
            'included_employees' => 0,
            'features' => ['core', 'self_service', 'biometric', 'shifts', 'certificates', 'payroll', 'statutory',
                'notifications', 'audit_log', 'multi_branch', 'insights'],
            'limits' => ['branches' => null, 'employees' => null],
            'is_public' => false,
            'sort_order' => 99,
        ],
    ],

    /*
    | The seller on invoices (the platform operator), and GST.
    */
    'seller' => [
        'name' => env('BILLING_SELLER_NAME', 'Sysnac Technologies'),
        'address' => env('BILLING_SELLER_ADDRESS', ''),
        'state' => env('BILLING_SELLER_STATE', 'Kerala'),
        'gstin' => env('BILLING_SELLER_GSTIN'),
        'email' => env('BILLING_SELLER_EMAIL', env('MAIL_FROM_ADDRESS')),
        'bank_details' => env('BILLING_BANK_DETAILS'), // shown on invoices for bank transfers
    ],

    'gst_rate' => 18,
    'sac_code' => env('BILLING_SAC_CODE', '998314'),
    'invoice_prefix' => env('BILLING_INVOICE_PREFIX', 'PNX'),

    // Online payments (optional). Without keys, invoices are settled offline.
    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

];
