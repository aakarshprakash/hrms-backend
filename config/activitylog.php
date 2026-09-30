<?php

return [

    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    // Payroll and HR records are audited for statutory periods; keep ~8 years.
    'delete_records_older_than_days' => 2920,

    'default_log_name' => 'default',

    'default_auth_driver' => null,

    'subject_returns_soft_deleted_models' => false,

    // Tenant-scoped: each organisation only ever sees its own audit trail.
    'activity_model' => \App\Models\Activity::class,

    'table_name' => env('ACTIVITY_LOGGER_TABLE_NAME', 'activity_log'),

    'database_connection' => env('ACTIVITY_LOGGER_DB_CONNECTION'),
];
