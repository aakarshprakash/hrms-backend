<?php

namespace App\Services\Attendance;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Scopes\BranchScope;
use App\Models\ShiftRoster;
use App\Services\Leave\LeaveDays;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Works out each employee's schedule for a date range with a fixed, small
 * number of queries (rosters, assignments, holidays and leave are loaded in
 * bulk), so processing a month for a whole branch stays cheap.
 *
 * Precedence for the shift of a day:
 *   1. a roster entry for that exact date (which may also mark it a day off),
 *   2. the employee's shift assignment in effect on that date,
 *   3. the branch's default shift.
 * Weekly off: roster day-off, else the employee's own weekly-off pattern,
 * else the branch's.
 */
class ScheduleResolver
{
    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array<string, DaySchedule>> employee id => date => schedule
     */
    public function forEmployees(Collection $employees, string $from, string $to): array
    {
        if ($employees->isEmpty()) {
            return [];
        }

        $ids = $employees->pluck('id')->all();
        $branchIds = $employees->pluck('branch_id')->unique()->all();

        $branches = Branch::with('defaultShift')->whereIn('id', $branchIds)->get()->keyBy('id');

        $rosters = ShiftRoster::withoutGlobalScope(BranchScope::class)
            ->with('shift')
            ->whereIn('employee_id', $ids)
            ->whereBetween('date', [$from, $to])
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($rows) => $rows->keyBy(fn ($r) => $r->date->toDateString()));

        $assignments = EmployeeShift::with('shift')
            ->whereIn('employee_id', $ids)
            ->where('effective_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('employee_id');

        $holidays = Holiday::withoutGlobalScope(BranchScope::class)
            ->whereIn('branch_id', $branchIds)
            ->get()
            ->groupBy('branch_id');

        $leaves = Leave::with('leaveType:id,paid,sandwich_rule')
            ->whereIn('employee_id', $ids)
            ->where('status', 'approved')
            ->where('start_date', '<=', $to)
            ->where('end_date', '>=', $from)
            ->get()
            ->groupBy('employee_id');

        $result = [];

        foreach ($employees as $employee) {
            $branch = $branches->get($employee->branch_id);
            $tz = $branch?->timezone ?: 'UTC';
            $weeklyOff = $employee->weekly_off_days ?? $branch?->week_off_days ?? [0];
            $holidayMap = $this->holidayMap($holidays->get($employee->branch_id, collect()), $from, $to);

            foreach (CarbonPeriod::create($from, $to) as $day) {
                $date = $day->toDateString();
                $roster = $rosters->get($employee->id)?->get($date);

                [$shift, $source] = match (true) {
                    $roster !== null && $roster->is_off => [null, 'roster'],
                    $roster?->shift !== null => [$roster->shift, 'roster'],
                    default => $this->assignedShift($assignments->get($employee->id), $date)
                        ?? ($branch?->defaultShift ? [$branch->defaultShift, 'branch_default'] : [null, null]),
                };

                $isOff = $roster !== null
                    ? (bool) $roster->is_off
                    : in_array($day->dayOfWeek, array_map('intval', (array) $weeklyOff), true);

                [$leaveStatus, $leaveSession, $leavePaid] = $this->leaveOn(
                    $leaves->get($employee->id), $date, $isOff || isset($holidayMap[$date])
                );

                $result[$employee->id][$date] = new DaySchedule(
                    date: $date,
                    timezone: $tz,
                    shift: $shift,
                    shiftSource: $source,
                    isWeeklyOff: $isOff,
                    holidayName: $holidayMap[$date] ?? null,
                    leaveStatus: $leaveStatus,
                    leaveIsPaid: $leavePaid,
                    leaveSession: $leaveSession,
                );
            }
        }

        return $result;
    }

    /**
     * Approved leave on one date: 'full' / 'half' (with the half), and
     * whether it's paid. A weekly off or holiday inside a leave only counts
     * under the sandwich rule (LeaveDays), exactly as the leave was charged.
     *
     * @return array{0: ?string, 1: ?string, 2: bool}
     */
    private function leaveOn(?Collection $leaves, string $date, bool $isOff): array
    {
        $fraction = 0.0;
        $session = null;
        $paid = true;

        foreach ($leaves ?? [] as $leave) {
            $share = LeaveDays::fractionOn($date, $leave->start_date->toDateString(), $leave->end_date->toDateString(),
                $isOff, (bool) $leave->leaveType?->sandwich_rule, $leave->half_day_session);

            if ($share <= 0) {
                continue;
            }

            $fraction += $share;
            $session ??= $leave->half_day_session;
            $paid = $paid && (bool) ($leave->leaveType?->paid ?? true);
        }

        return match (true) {
            $fraction >= 1 => ['full', null, $paid],
            $fraction > 0 => ['half', $session, $paid],
            default => [null, null, true],
        };
    }

    public function forEmployee(Employee $employee, string $from, string $to): array
    {
        return $this->forEmployees(collect([$employee]), $from, $to)[$employee->id] ?? [];
    }

    /** @return array{0: \App\Models\Shift, 1: string}|null */
    private function assignedShift(?Collection $assignments, string $date): ?array
    {
        $match = $assignments?->first(fn ($a) => $a->effective_from->toDateString() <= $date
            && ($a->effective_to === null || $a->effective_to->toDateString() >= $date));

        return $match?->shift ? [$match->shift, 'assignment'] : null;
    }

    /** date => holiday name, with recurring holidays projected onto each year in range. */
    private function holidayMap(Collection $holidays, string $from, string $to): array
    {
        $map = [];
        $years = range((int) substr($from, 0, 4), (int) substr($to, 0, 4));

        foreach ($holidays as $holiday) {
            $dates = $holiday->recurring
                ? array_map(fn ($y) => $holiday->date->copy()->setYear($y)->toDateString(), $years)
                : [$holiday->date->toDateString()];

            foreach ($dates as $d) {
                if ($d >= $from && $d <= $to) {
                    $map[$d] = $holiday->name;
                }
            }
        }

        return $map;
    }
}
