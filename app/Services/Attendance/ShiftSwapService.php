<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\ShiftRoster;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Approving a swap exchanges the two people's schedules on the swap dates
 * ("I cover your Monday, you cover my Sunday"; or, on one date, simply
 * trading shifts): each takes the other's shift -- or day off -- as a
 * roster entry, so attendance is judged against the swapped shifts.
 */
class ShiftSwapService
{
    public function __construct(private ScheduleResolver $schedules, private AttendanceProcessor $processor)
    {
    }

    /** Checks a new request; returns an error message, or null when it's fine. */
    public function problem(Employee $requester, Employee $target, string $myDate, string $theirDate): ?string
    {
        $today = CarbonImmutable::now($requester->branch?->timezone ?: config('app.timezone'))->toDateString();

        if ($requester->id === $target->id) {
            return 'Pick a colleague to swap with.';
        }
        if ($target->branch_id !== $requester->branch_id || $target->status !== 'active') {
            return 'You can only swap with an active colleague in your branch.';
        }
        if ($myDate < $today || $theirDate < $today) {
            return 'Swaps are for today or later.';
        }

        $mine = $this->schedules->forEmployee($requester, $myDate, $myDate)[$myDate] ?? null;
        if (! $mine || ! $mine->shift || $mine->isWeeklyOff || $mine->isHoliday()) {
            return "You aren't scheduled to work on " . CarbonImmutable::parse($myDate)->format('j M') . ' — there is no shift to swap.';
        }

        return $this->leaveClash($requester, $target, [$myDate, $theirDate]);
    }

    public function approve(ShiftSwapRequest $swap, User $by, ?string $note = null): void
    {
        $a = Employee::find($swap->requester_id);
        $b = Employee::find($swap->target_employee_id);
        $dates = array_values(array_unique([$swap->my_date->toDateString(), $swap->their_date->toDateString()]));
        sort($dates);

        abort_if($clash = $this->leaveClash($a, $b, $dates), 422, $clash ?? '');
        abort_if(Attendance::whereIn('employee_id', [$a->id, $b->id])->whereIn('date', $dates)->whereNotNull('locked_at')->exists(),
            422, 'Those days are locked by a finalized payroll run.');

        $scheduleA = $this->schedules->forEmployee($a, $dates[0], end($dates));
        $scheduleB = $this->schedules->forEmployee($b, $dates[0], end($dates));

        DB::transaction(function () use ($swap, $by, $note, $a, $b, $dates, $scheduleA, $scheduleB) {
            foreach ($dates as $date) {
                $this->assign($a, $date, $scheduleB[$date] ?? null, $swap);
                $this->assign($b, $date, $scheduleA[$date] ?? null, $swap);
            }

            $swap->update(['status' => 'approved', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note]);
        });

        // Today's (or an already-worked) day is re-judged against the new shifts.
        $today = CarbonImmutable::now($a->branch?->timezone ?: config('app.timezone'))->toDateString();
        foreach ([$a, $b] as $employee) {
            if ($dates[0] <= $today) {
                $this->processor->processEmployee($employee, $dates[0], min(end($dates), $today));
            }
        }
    }

    private function assign(Employee $employee, string $date, ?DaySchedule $source, ShiftSwapRequest $swap): void
    {
        $off = ! $source || ! $source->shift || $source->isWeeklyOff;

        ShiftRoster::updateOrCreate(['employee_id' => $employee->id, 'date' => $date], [
            'branch_id' => $employee->branch_id,
            'department_id' => $employee->department_id,
            'shift_id' => $off ? null : $source->shift->id,
            'is_off' => $off,
            'note' => "Shift swap #{$swap->id}",
        ]);
    }

    private function leaveClash(Employee $a, Employee $b, array $dates): ?string
    {
        foreach ([$a, $b] as $employee) {
            foreach (array_unique($dates) as $date) {
                $onLeave = Leave::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
                    ->where('start_date', '<=', $date)->where('end_date', '>=', $date)->exists();
                if ($onLeave) {
                    return "{$employee->first_name} has leave on " . CarbonImmutable::parse($date)->format('j M') . '.';
                }
            }
        }

        return null;
    }
}
