<?php

namespace App\Services\Attendance;

use App\Models\Shift;
use Carbon\CarbonImmutable;

/**
 * What one employee was supposed to do on one calendar day: which shift (if
 * any), whether it was a weekly off / holiday / approved leave, and the time
 * window in which punches count towards this day.
 *
 * All instants are UTC; `date` is the branch-local calendar day.
 */
final class DaySchedule
{
    public ?CarbonImmutable $windowStart = null;

    public ?CarbonImmutable $windowEnd = null;

    public function __construct(
        public readonly string $date,
        public readonly string $timezone,
        public readonly ?Shift $shift,
        public readonly ?string $shiftSource,     // roster | assignment | branch_default | null
        public readonly bool $isWeeklyOff,
        public readonly ?string $holidayName,
        public readonly ?string $leaveStatus,     // 'full' | 'half' when on approved leave
        public readonly bool $leaveIsPaid = true,
        public readonly ?string $leaveSession = null, // first_half | second_half, for a half day
    ) {
    }

    /** Share of the day covered by approved leave: 0, 0.5 or 1. */
    public function leaveFraction(): float
    {
        return match ($this->leaveStatus) {
            'full' => 1.0,
            'half' => 0.5,
            default => 0.0,
        };
    }

    public function isHoliday(): bool
    {
        return $this->holidayName !== null;
    }

    public function isWorkingDay(): bool
    {
        return ! $this->isWeeklyOff && ! $this->isHoliday();
    }

    public function shiftStart(): ?CarbonImmutable
    {
        if (! $this->shift) {
            return null;
        }

        return CarbonImmutable::parse("{$this->date} {$this->shift->start_time}", $this->timezone)->utc();
    }

    public function shiftEnd(): ?CarbonImmutable
    {
        if (! $this->shift) {
            return null;
        }

        $end = CarbonImmutable::parse("{$this->date} {$this->shift->end_time}", $this->timezone)->utc();

        return $end->lte($this->shiftStart()) ? $end->addDay() : $end;
    }

    public function dayStart(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->date, $this->timezone)->startOfDay()->utc();
    }

    /**
     * Natural punch window before neighbours are considered: from
     * `punch_window_before` ahead of shift start to `punch_window_after`
     * past shift end; the plain calendar day when there is no shift.
     */
    public function naturalWindow(): array
    {
        if (! $this->shift) {
            return [$this->dayStart(), $this->dayStart()->addDay()];
        }

        return [
            $this->shiftStart()->subMinutes((int) ($this->shift->punch_window_before_minutes ?? 180)),
            $this->shiftEnd()->addMinutes((int) ($this->shift->punch_window_after_minutes ?? 360)),
        ];
    }

    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'shift' => $this->shift ? [
                'id' => $this->shift->id,
                'name' => $this->shift->name,
                'start_time' => substr((string) $this->shift->start_time, 0, 5),
                'end_time' => substr((string) $this->shift->end_time, 0, 5),
                'color' => $this->shift->color,
            ] : null,
            'shift_source' => $this->shiftSource,
            'is_weekly_off' => $this->isWeeklyOff,
            'holiday' => $this->holidayName,
            'leave' => $this->leaveStatus,
            'leave_session' => $this->leaveSession,
        ];
    }
}
