<?php

use App\Console\Commands\AccrueLeaveBalances;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// All scheduled commands log their output and skip overlapping runs --
// a prior cron entry silently invoked schedule:run at a minute offset
// (*/15) that never lined up with hourlyAt(20) below, so mark-absentees
// went months without ever firing and nothing recorded that fact.
// appendOutputTo gives this a paper trail; withoutOverlapping is cheap
// insurance now that schedule:run itself is expected to run every minute.
$logPath = storage_path('logs/scheduler.log');

// Leave accrual by policy + year-end carry forward. Daily, because it is
// idempotent (one credit per balance per period): a missed run is simply
// caught up by the next one, and new joiners get balances the next day.
Schedule::command('hrms:accrue-leave-balances')
    ->dailyAt('00:30')
    ->withoutOverlapping()
    ->appendOutputTo($logPath);

// Subscriptions: trials ending, renewals with invoices, overdue payments.
Schedule::command('billing:run')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->appendOutputTo($logPath);

// Notification outbox: email / SMS / WhatsApp that wasn't sent right after
// the request (provider down, process killed) is retried with backoff.
Schedule::command('notifications:dispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo($logPath);

// Pull biometric device punches for every branch with sync enabled.
// Requires the Laravel scheduler to actually be running (e.g. `php artisan
// schedule:work`, or a cron/Task Scheduler entry for `schedule:run` every minute).
Schedule::command('biometric:sync')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo($logPath);

// Turn "no punch" into a real absent record. Hourly (not once-daily)
// because the command itself decides -- per branch, in that branch's own
// timezone -- whether "yesterday" has actually elapsed; a single fixed
// server-clock time can't safely cover branches in different timezones.
// Idempotent (skips anything already marked), so re-running hourly is
// harmless. Offset 20 minutes past biometric:sync's hourly tick so real
// punch data always gets first chance to land for that hour.
Schedule::command('attendance:mark-absentees')
    ->hourlyAt(20)
    ->withoutOverlapping()
    ->appendOutputTo($logPath);
