<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CoverageAlert;
use App\Models\CoverageRule;
use App\Notifications\InAppNotification;
use App\Services\Attendance\CoverageService;
use App\Support\Notifications\NotificationMessage;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Once a rule's check time has passed (in its branch's timezone), tell HR
 * and attendance managers if the team has fewer people in than its
 * minimum. One alert per rule per day.
 */
class CheckTeamCoverage extends Command
{
    protected $signature = 'attendance:check-coverage';

    protected $description = 'Alert HR when a team is below its minimum staffing today';

    public function handle(TenantContext $context, CoverageService $coverage): int
    {
        $sent = 0;
        $companyIds = $context->withoutScoping(fn () => CoverageRule::where('is_active', true)->distinct()->pluck('company_id'));

        foreach ($companyIds as $companyId) {
            $context->runAs((int) $companyId, function () use ($coverage, &$sent) {
                $company = Company::find(app(TenantContext::class)->id());
                foreach (CoverageRule::with(['branch:id,name,timezone', 'department:id,name'])->where('is_active', true)->get() as $rule) {
                    $tz = $rule->branch?->timezone ?: config('app.timezone');
                    $today = CarbonImmutable::now($tz)->toDateString();
                    if (! $coverage->pastCheck($rule, $tz) || CoverageAlert::where('coverage_rule_id', $rule->id)->whereDate('date', $today)->exists()) {
                        continue;
                    }

                    $snap = $coverage->snapshot(['branch_id' => $rule->branch_id, 'department_id' => $rule->department_id, 'designation_id' => $rule->designation_id], $today, $rule);
                    if ($snap['off_day'] || $snap['status'] !== 'short') {
                        continue;
                    }

                    $recipients = $coverage->recipients($rule);
                    $missing = $rule->min_present - $snap['present'];
                    $body = "{$snap['present']} of the minimum {$rule->min_present} are in"
                        . ($snap['on_leave'] ? ", {$snap['on_leave']} on leave" : '')
                        . ($snap['not_in'] ? ", {$snap['not_in']} not punched in yet" : '') . '.';
                    foreach ($recipients as $user) {
                        $user->notify(new InAppNotification(new NotificationMessage(
                            'attendance.coverage_short',
                            "{$rule->name} is short by {$missing} today",
                            $body,
                            '/attendance/coverage',
                            companyName: $company?->name,
                        )));
                    }
                    CoverageAlert::create(['coverage_rule_id' => $rule->id, 'date' => $today, 'present' => $snap['present'],
                        'required' => $rule->min_present, 'on_leave' => $snap['on_leave'], 'notified' => $recipients->count()]);
                    $sent++;
                }
            });
        }

        $this->info("Coverage alerts sent: {$sent}");

        return self::SUCCESS;
    }
}
