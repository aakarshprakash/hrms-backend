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

// Turn "no punch" into a real absent record. Hourly (not once-daily)
// because the command itself decides -- per branch, in that branch's own
// timezone -- whether "yesterday" has actually elapsed; a single fixed
// server-clock time can't safely cover branches in different timezones.
// Idempotent (skips anything already marked), so re-running hourly is
// harmless. Offset 20 minutes past biometric:sync's hourly tick so real
// punch data always gets first chance to land for that hour.
Schedule::command('attendance:mark-absentees')->hourlyAt(20);
