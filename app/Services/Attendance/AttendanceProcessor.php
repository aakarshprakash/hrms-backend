<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\RawPunch;
use App\Models\Scopes\BranchScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Derives attendance_daily from raw_punches and the day's schedule.
 *
 * For each employee-day:
 *   - punches inside the day's window count (the window follows the shift,
 *     so a 22:00-06:00 shift keeps its 05:58 out-punch on the right day);
 *   - first punch = in, last punch = out;
 *   - worked, late, early-exit and overtime minutes come from the shift's
 *     own rules (grace, half-day / absent thresholds, OT threshold);
 *   - no punches: holiday / weekly off / approved leave / absent (absent only
 *     once the day is actually over).
 *
 * Idempotent and safe to re-run at any time. It never overwrites a day HR
 * entered manually, nor a day locked by a finalized payroll run.
 */
class AttendanceProcessor
{
    public function __construct(private readonly ScheduleResolver $schedules)
    {
    }

    /**
     * @return array{processed: int, skipped: int, cleared: int}
     */
    public function processEmployees(Collection $employees, string $from, string $to): array
    {
        $totals = ['processed' => 0, 'skipped' => 0, 'cleared' => 0];

        // Resolve schedules one day beyond each end so windows can be clipped
        // against neighbouring days (back-to-back or night shifts).
        $pad = fn (string $d, int $days) => CarbonImmutable::parse($d)->addDays($days)->toDateString();

        foreach ($employees->chunk(100) as $chunk) {
            $schedules = $this->schedules->forEmployees($chunk, $pad($from, -1), $pad($to, 1));

            foreach ($chunk as $employee) {
                $stats = $this->processOne($employee, $schedules[$employee->id] ?? [], $from, $to);
                foreach ($stats as $k => $v) {
                    $totals[$k] += $v;
                }
            }
        }

        return $totals;
    }

    public function processEmployee(Employee $employee, string $from, string $to): array
    {
        return $this->processEmployees(collect([$employee]), $from, $to);
    }

    /** Reprocess whatever days these employee/date pairs touch (after a device sync, a regularization...). */
    public function processAffected(array $employeeDates): void
    {
        $employees = Employee::withoutGlobalScope(BranchScope::class)->whereIn('id', array_keys($employeeDates))->get()->keyBy('id');

        foreach ($employeeDates as $employeeId => $dates) {
            $employee = $employees->get($employeeId);
            if (! $employee || empty($dates)) {
                continue;
            }

            sort($dates);
            // The day before too: a punch after midnight may close a night shift.
            $from = CarbonImmutable::parse($dates[0])->subDay()->toDateString();
            $this->processEmployee($employee, $from, end($dates));
        }
    }

    /**
     * The attendance day an instant belongs to for this employee -- e.g. a
     * 05:58 check-out on a 22:00-06:00 shift belongs to the previous day.
     */
    public function dayFor(Employee $employee, CarbonImmutable $instant): string
    {
        $tz = $employee->branch?->timezone ?: 'UTC';
        $local = $instant->setTimezone($tz)->toDateString();
        $from = CarbonImmutable::parse($local)->subDays(2)->toDateString();
        $to = CarbonImmutable::parse($local)->addDay()->toDateString();

        $schedule = $this->schedules->forEmployee($employee, $from, $to);
        $this->assignWindows($schedule);

        foreach ($schedule as $date => $day) {
            if ($day->windowStart && $instant->gte($day->windowStart) && $instant->lt($day->windowEnd)) {
                return $date;
            }
        }

        return $local;
    }

    /** Employees whose attendance should be derived for a period. */
    public static function employeesQuery(?int $branchId = null): Builder
    {
        return Employee::withoutGlobalScope(BranchScope::class)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where(fn ($q) => $q->where('status', 'active')->orWhereNotNull('date_of_leaving'));
    }

    // ── internals ─────────────────────────────────────────────────────────

    /**
     * @param  array<string, DaySchedule>  $schedule
     */
    private function processOne(Employee $employee, array $schedule, string $from, string $to): array
    {
        $stats = ['processed' => 0, 'skipped' => 0, 'cleared' => 0];
        $days = array_map(fn ($d) => $d->toDateString(), iterator_to_array(CarbonPeriod::create($from, $to)));

        $this->assignWindows($schedule);

        $windowStart = collect($days)->map(fn ($d) => $schedule[$d]->windowStart ?? null)->filter()->min();
        $windowEnd = collect($days)->map(fn ($d) => $schedule[$d]->windowEnd ?? null)->filter()->max();

        $punches = $windowStart
            ? RawPunch::where('employee_id', $employee->id)
                ->where('punched_at', '>=', $windowStart)
                ->where('punched_at', '<', $windowEnd)
                ->orderBy('punched_at')
                ->get(['id', 'punched_at', 'source', 'latitude', 'longitude'])
            : collect();

        $existing = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$from, $to])
            ->get()
            ->keyBy(fn ($a) => $a->date->toDateString());

        $joined = $employee->date_of_joining?->toDateString();
        $left = $employee->date_of_leaving?->toDateString();

        foreach ($days as $date) {
            $day = $schedule[$date] ?? null;
            $current = $existing->get($date);

            if (! $day) {
                continue;
            }

            if ($current && (in_array($current->source, Attendance::PROTECTED_SOURCES, true) || $current->locked_at)) {
                $stats['skipped']++;
                continue;
            }

            // Outside employment: never create anything, and only ever remove
            // rows the processor itself generated.
            if (($joined && $date < $joined) || ($left && $date > $left)) {
                $stats['cleared'] += $this->clearGenerated($current);
                continue;
            }

            $dayPunches = $punches->filter(fn ($p) => $p->punched_at->gte($day->windowStart) && $p->punched_at->lt($day->windowEnd))->values();
            $values = $this->evaluate($day, $dayPunches);

            if ($values === null) {
                // Day not over yet and nobody punched: no record (yet).
                $stats['cleared'] += $this->clearGenerated($current);
                continue;
            }

            DB::transaction(function () use ($employee, $date, $values, $dayPunches) {
                Attendance::updateOrCreate(['employee_id' => $employee->id, 'date' => $date], $values);

                if ($dayPunches->isNotEmpty()) {
                    RawPunch::whereIn('id', $dayPunches->pluck('id'))->update(['attendance_date' => $date, 'processed_at' => now()]);
                }
            });

            $stats['processed']++;
        }

        return $stats;
    }

    /**
     * Each day's window is its natural window clipped so it never overlaps
     * the next day's -- a punch belongs to exactly one day.
     *
     * @param  array<string, DaySchedule>  $schedule
     */
    private function assignWindows(array $schedule): void
    {
        $dates = array_keys($schedule);
        sort($dates);

        foreach ($dates as $i => $date) {
            [$start, $end] = $schedule[$date]->naturalWindow();

            $next = isset($dates[$i + 1]) ? $schedule[$dates[$i + 1]] : null;
            if ($next) {
                [$nextStart] = $next->naturalWindow();
                if ($nextStart->lt($end)) {
                    // Split the overlap evenly between the two days.
                    $end = $nextStart->addSeconds((int) (($end->getTimestamp() - $nextStart->getTimestamp()) / 2));
                }
            }

            $prev = $i > 0 ? $schedule[$dates[$i - 1]] : null;
            if ($prev && $prev->windowEnd && $prev->windowEnd->gt($start)) {
                $start = $prev->windowEnd;
            }

            $schedule[$date]->windowStart = $start;
            $schedule[$date]->windowEnd = $end;
        }
    }

    /**
     * @param  Collection<int, RawPunch>  $punches
     * @return array<string, mixed>|null  attendance_daily attributes, or null for "no record yet"
     */
    private function evaluate(DaySchedule $day, Collection $punches): ?array
    {
        $base = [
            'shift_id' => $day->shift?->id,
            'is_weekly_off' => $day->isWeeklyOff,
            'is_holiday' => $day->isHoliday(),
            'processed_at' => now(),
            'check_in' => null,
            'check_out' => null,
            'punch_count' => $punches->count(),
            'late_by_minutes' => null,
            'early_by_minutes' => null,
            'worked_minutes' => null,
            'overtime_minutes' => null,
            'anomaly' => null,
            'remarks' => null,
            'latitude' => null,
            'longitude' => null,
        ];

        if ($punches->isEmpty()) {
            $status = match (true) {
                $day->leaveStatus === 'full' => 'on_leave',
                $day->isHoliday() => 'holiday',
                $day->isWeeklyOff => 'weekly_off',
                default => 'absent',
            };

            // Only call someone absent once their day is actually over.
            if ($status === 'absent' && ! $this->dayIsOver($day)) {
                return null;
            }

            return array_merge($base, [
                'status' => $status,
                'source' => 'system',
                'remarks' => $day->holidayName
                    ?? ($day->leaveStatus === 'half' ? $this->halfDayLeaveNote($day) . '; absent for the other half' : null),
            ]);
        }

        $first = CarbonImmutable::instance($punches->first()->punched_at);
        $last = $punches->count() > 1 ? CarbonImmutable::instance($punches->last()->punched_at) : null;
        if ($last && $first->diffInMinutes($last, true) < 2) {
            $last = null; // double tap on the device, not an out-punch
        }

        $sources = $punches->pluck('source')->unique();
        $source = $sources->contains('biometric') ? 'api' : $punches->first()->source;

        $values = array_merge($base, [
            'check_in' => $first,
            'check_out' => $last,
            'source' => $source,
            'latitude' => $punches->first()->latitude,
            'longitude' => $punches->first()->longitude,
        ]);

        $shift = $day->shift;
        $breakMinutes = (int) ($shift?->break_minutes ?? 0);
        $span = $last ? (int) round($first->diffInMinutes($last, true)) : null;
        $worked = $span !== null ? max(0, $span > $breakMinutes ? $span - $breakMinutes : $span) : null;
        $values['worked_minutes'] = $worked;

        if (! $last) {
            $values['anomaly'] = $this->dayIsOver($day) ? 'missing_out_punch' : null;
        }

        if (! $shift) {
            $values['status'] = 'present';
            $values['remarks'] = $day->isWeeklyOff ? 'Worked on weekly off' : ($day->holidayName ? "Worked on holiday: {$day->holidayName}" : null);

            return $values;
        }

        $shiftStart = $day->shiftStart();
        $shiftEnd = $day->shiftEnd();
        $netShift = $shift->netMinutes();

        // Half-day leave: the leave half is excused -- arriving at mid-day
        // after a first-half leave isn't late, leaving at mid-day before a
        // second-half leave isn't early.
        $halfLeave = $day->leaveStatus === 'half';

        $lateBy = max(0, (int) round($shiftStart->diffInMinutes($first, false)));
        $isLate = $lateBy > (int) $shift->grace_minutes && ! ($halfLeave && $day->leaveSession === 'first_half');
        $values['late_by_minutes'] = $isLate ? $lateBy : null;

        if ($last && ! ($halfLeave && $day->leaveSession === 'second_half')) {
            $earlyBy = max(0, (int) round($last->diffInMinutes($shiftEnd, false)));
            $values['early_by_minutes'] = $earlyBy > (int) ($shift->early_exit_grace_minutes ?? 0) ? $earlyBy : null;
        }

        $halfDayThreshold = (int) ($shift->half_day_threshold_minutes ?? intdiv($netShift, 2));
        $absentThreshold = $shift->absent_threshold_minutes;

        if ($halfLeave) {
            // Only the other half has to be worked: what earns half a day on
            // a normal day covers it.
            $minimum = $absentThreshold ?? intdiv($halfDayThreshold, 2);
            $status = match (true) {
                $worked !== null && $worked < $minimum => 'absent',
                $isLate => 'late',
                default => 'present',
            };
            $values['remarks'] = $this->halfDayLeaveNote($day);
        } else {
            $status = match (true) {
                $worked !== null && $absentThreshold !== null && $worked < $absentThreshold => 'absent',
                $worked !== null && $worked < $halfDayThreshold => 'half_day',
                $isLate => 'late',
                default => 'present',
            };
            if ($day->leaveStatus === 'full') {
                $values['remarks'] = 'Punched in on a day of approved leave';
            }
        }

        if ($status === 'absent') {
            $values['anomaly'] = 'insufficient_hours';
        }

        if ($worked !== null && $shift->ot_threshold_minutes !== null) {
            $extra = $worked - $netShift;
            $values['overtime_minutes'] = $extra >= (int) $shift->ot_threshold_minutes ? $extra : 0;
        }

        // Working on a day off/holiday is recorded as presence (comp-off / OT evidence).
        if (! $day->isWorkingDay()) {
            $status = $status === 'absent' ? ($day->isHoliday() ? 'holiday' : 'weekly_off') : 'present';
            $values['late_by_minutes'] = null;
            $values['remarks'] = $day->isWeeklyOff ? 'Worked on weekly off' : "Worked on holiday: {$day->holidayName}";
            if ($worked !== null) {
                $values['overtime_minutes'] = $worked;
            }
        }

        $values['status'] = $status;

        return $values;
    }

    private function halfDayLeaveNote(DaySchedule $day): string
    {
        return match ($day->leaveSession) {
            'first_half' => 'Half-day leave (first half)',
            'second_half' => 'Half-day leave (second half)',
            default => 'Half-day leave',
        };
    }

    /** Deletes a row only if processing generated it (never punched / manual data). */
    private function clearGenerated(?Attendance $row): int
    {
        if ($row && $row->source === 'system' && $row->check_in === null) {
            $row->delete();

            return 1;
        }

        return 0;
    }

    /** Past the end of the shift (plus an hour), or past midnight when no shift. */
    private function dayIsOver(DaySchedule $day): bool
    {
        $end = $day->shiftEnd()?->addHour() ?? $day->dayStart()->addDay();

        return CarbonImmutable::now('UTC')->gte($end);
    }
}
