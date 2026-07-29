<?php

use App\Console\Commands\AccrueLeaveBalances;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule monthly leave balance accrual
Schedule::command('hrms:accrue-leave-balances')->monthlyOn(1, '00:00');

// Pull biometric device punches for every branch with sync enabled.
// Requires the Laravel scheduler to actually be running (e.g. `php artisan
// schedule:work`, or a cron/Task Scheduler entry for `schedule:run` every minute).
Schedule::command('biometric:sync')->hourly();
