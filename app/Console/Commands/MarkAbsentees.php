<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Services\AttendanceStatusResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turns "no punch recorded" into a real, persisted absent Attendance row.
 * Nothing else in the app proactively does this -- every report/roster view
 * otherwise invents its own private "no data" bucket instead of treating a
 * missing day as an absence. Idempotent: safe to re-run for any date, since
 * it only ever creates a row where one doesn't already exist.
 */
class MarkAbsentees extends Command
{
    protected $signature = 'attendance:mark-absentees {--date=} {--from=} {--to=} {--branch_id=}';

    protected $description = 'Create absent Attendance rows for active employees with no punch on working days';

    public function handle(AttendanceStatusResolver $resolver): int
    {
        $branches = Branch::withoutGlobalScopes()
            ->when($this->option('branch_id'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        if ($branches->isEmpty()) {
            $this->error('No matching branch found.');
            return self::FAILURE;
        }

        $totalCreated = 0;

        foreach ($branches as $branch) {
            $dates = $this->resolveDatesForBranch($branch);

            foreach ($dates as $date) {
                $totalCreated += $this->processBranchDate($branch, $date, $resolver);
            }
        }

        $this->info("Created {$totalCreated} absent record(s).");
        return self::SUCCESS;
    }

    /**
     * @return array<int, Carbon>
     */
    private function resolveDatesForBranch(Branch $branch): array
    {
        if ($this->option('from') && $this->option('to')) {
            $dates = [];
            $cursor = Carbon::parse($this->option('from'));
            $end = Carbon::parse($this->option('to'));
            while ($cursor->lte($end)) {
                $dates[] = $cursor->copy();
                $cursor->addDay();
            }
            return $dates;
        }

        if ($this->option('date')) {
            return [Carbon::parse($this->option('date'))];
        }

        // Default, unattended mode: "yesterday" in this branch's own
        // timezone -- a single fixed server-clock time can't safely be
        // "past midnight" for every branch at once.
        return [Carbon::now($branch->timezone ?: 'UTC')->subDay()->startOfDay()];
    }

    private function processBranchDate(Branch $branch, Carbon $date, AttendanceStatusResolver $resolver): int
    {
        if (!$branch->isWorkingDay($date)) {
            return 0;
        }

        $isHoliday = Holiday::withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->get()
            ->contains(function ($h) use ($date) {
                $holidayDate = $h->recurring ? $h->date->copy()->setYear($date->year) : $h->date;
                return $holidayDate->isSameDay($date);
            });

        if ($isHoliday) {
            return 0;
        }

        $employees = Employee::withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->where('status', 'active')
            ->get();

        if ($employees->isEmpty()) {
            return 0;
        }

        $existingEmployeeIds = Attendance::whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('date', $date->toDateString())
            ->pluck('employee_id')
            ->all();

        $onApprovedLeaveEmployeeIds = Leave::whereIn('employee_id', $employees->pluck('id'))
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->pluck('employee_id')
            ->all();

        $skip = array_flip(array_merge($existingEmployeeIds, $onApprovedLeaveEmployeeIds));

        $created = 0;

        DB::transaction(function () use ($employees, $skip, $date, $resolver, &$created) {
            foreach ($employees as $employee) {
                if (isset($skip[$employee->id])) {
                    continue;
                }
                if ($employee->date_of_joining && $employee->date_of_joining->gt($date)) {
                    continue;
                }
                if ($employee->date_of_leaving && $employee->date_of_leaving->lt($date)) {
                    continue;
                }

                $resolved = $resolver->resolve($employee, $date->toDateString(), null, null);

                Attendance::create([
                    'employee_id' => $employee->id,
                    'shift_id' => $resolved['shift_id'],
                    'date' => $date->toDateString(),
                    'status' => 'absent',
                    'source' => 'system',
                ]);

                $created++;
            }
        });

        return $created;
    }
}
