<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Shift;
use Carbon\Carbon;

/**
 * Derives an attendance day's status (present / late / half_day / absent)
 * from the employee's assigned shift instead of it being hardcoded or
 * hand-picked. Used by self check-in/out, manual entry, and biometric sync
 * alike so "late" and "half_day" mean the same thing everywhere.
 */
class AttendanceStatusResolver
{
    public function resolve(Employee $employee, string $date, ?Carbon $checkIn, ?Carbon $checkOut): array
    {
        $shift = $this->activeShiftFor($employee, $date);

        if (! $checkIn) {
            return [
                'shift_id' => $shift?->id,
                'status' => 'absent',
                'late_by_minutes' => null,
                'early_by_minutes' => null,
                'worked_minutes' => null,
            ];
        }

        if (! $shift) {
            // No shift assigned — we can tell they showed up, but not
            // whether they were "late" against a schedule that doesn't exist.
            return [
                'shift_id' => null,
                'status' => 'present',
                'late_by_minutes' => null,
                'early_by_minutes' => null,
                'worked_minutes' => $checkOut ? max(0, $checkIn->diffInMinutes($checkOut, true)) : null,
            ];
        }

        // Shift times are wall-clock in the branch's own timezone (a "9:00 AM"
        // shift means 9 AM there, not in the app's UTC) — parse them as such
        // so they land on the correct UTC instant for comparison and storage.
        $timezone = $shift->branch?->timezone ?: 'UTC';
        $shiftStart = Carbon::parse("{$date} {$shift->start_time}", $timezone);
        $shiftEnd = Carbon::parse("{$date} {$shift->end_time}", $timezone);
        if ($shiftEnd->lte($shiftStart)) {
            $shiftEnd->addDay(); // overnight shift (e.g. 22:00 → 06:00)
        }

        // Signed: positive when checkIn is after shiftStart (arrived late).
        $rawLateMinutes = max(0, $shiftStart->diffInMinutes($checkIn, false));
        $isLate = $rawLateMinutes > $shift->grace_minutes;

        $workedMinutes = null;
        $earlyByMinutes = null;
        $isHalfDay = false;

        if ($checkOut) {
            // Carbon 3's diffInMinutes is signed by default (b - a); pass
            // absolute:true here since we just want a plain duration.
            $workedMinutes = max(0, $checkIn->diffInMinutes($checkOut, true) - $shift->break_minutes);

            $fullShiftMinutes = max(1, $shiftStart->diffInMinutes($shiftEnd, true) - $shift->break_minutes);
            $halfDayThreshold = $shift->half_day_threshold_minutes ?? intdiv($fullShiftMinutes, 2);
            $isHalfDay = $workedMinutes < $halfDayThreshold;

            // Signed: positive when checkOut is before shiftEnd (left early).
            $rawEarlyMinutes = $checkOut->diffInMinutes($shiftEnd, false);
            $earlyByMinutes = max(0, $rawEarlyMinutes);
        }

        $status = match (true) {
            $isHalfDay => 'half_day',
            $isLate => 'late',
            default => 'present',
        };

        return [
            'shift_id' => $shift->id,
            'status' => $status,
            'late_by_minutes' => $isLate ? $rawLateMinutes : null,
            'early_by_minutes' => $earlyByMinutes,
            'worked_minutes' => $workedMinutes,
        ];
    }

    private function activeShiftFor(Employee $employee, string $date): ?Shift
    {
        return EmployeeShift::where('employee_id', $employee->id)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->first()
            ?->shift;
    }
}
