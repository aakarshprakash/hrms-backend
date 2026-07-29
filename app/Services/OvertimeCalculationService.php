<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\OvertimeRule;
use App\Models\SalaryStructure;
use Carbon\Carbon;

class OvertimeCalculationService
{
    /**
     * Returns overtime hours for a given employee on a given date.
     */
    public function calculateForDate(Employee $employee, Carbon $date): float
    {
        // Get attendance record for that date
        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->first();

        if (!$attendance || !$attendance->check_in || !$attendance->check_out) {
            return 0.0;
        }

        // Get OT rule for employee's branch
        $rule = OvertimeRule::withoutGlobalScopes()
            ->where('branch_id', $employee->branch_id)
            ->first();

        if (!$rule) {
            return 0.0;
        }

        // Get shift for that date: first from shift_rosters, then fall back to employee_shifts
        $shift = $this->getShiftForDate($employee, $date);

        $actualWorkedHours = $attendance->check_in->diffInMinutes($attendance->check_out) / 60;

        $thresholdHours = (float) $rule->daily_threshold_hours;

        if ($shift) {
            // Use shift's configured hours as threshold if it differs
            $breakMinutes = $shift->break_minutes ?? 0;
            $shiftStart = Carbon::parse($shift->start_time);
            $shiftEnd = Carbon::parse($shift->end_time);
            if ($shiftEnd->lessThan($shiftStart)) {
                $shiftEnd->addDay();
            }
            $shiftHours = ($shiftStart->diffInMinutes($shiftEnd) - $breakMinutes) / 60;
            // Use the greater of shift hours and rule threshold as the baseline
            $thresholdHours = max($shiftHours, $thresholdHours);
        }

        $otHours = max(0.0, $actualWorkedHours - $thresholdHours);

        return round($otHours, 2);
    }

    /**
     * Returns OT pay for given hours using the rule's rate_multiplier and employee's basic pay.
     */
    public function calculateOtPay(Employee $employee, float $otHours, OvertimeRule $rule): float
    {
        if ($otHours <= 0) {
            return 0.0;
        }

        // Get basic pay from salary structures (look for component named 'basic' or first earning)
        $basicPay = $this->getBasicPay($employee);

        if ($basicPay <= 0) {
            return 0.0;
        }

        // Hourly rate: assuming monthly salary, ~26 working days, 8 hours/day
        $hourlyRate = $basicPay / (26 * 8);
        $otPay = $hourlyRate * $otHours * (float) $rule->rate_multiplier;

        return round($otPay, 2);
    }

    private function getShiftForDate(Employee $employee, Carbon $date)
    {
        // First try shift_rosters for specific date assignment
        $roster = \App\Models\ShiftRoster::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->with('shift')
            ->first();

        if ($roster && $roster->shift) {
            return $roster->shift;
        }

        // Fall back to employee_shifts for the date range
        $employeeShift = \App\Models\EmployeeShift::where('employee_id', $employee->id)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            })
            ->with('shift')
            ->latest('effective_from')
            ->first();

        return $employeeShift?->shift;
    }

    private function getBasicPay(Employee $employee): float
    {
        // Look for a component named 'Basic' or 'basic' of type earning
        $structure = SalaryStructure::where('employee_id', $employee->id)
            ->whereHas('component', function ($q) {
                $q->where('type', 'earning')
                    ->whereRaw('LOWER(name) = ?', ['basic']);
            })
            ->whereNull('effective_to')
            ->latest('effective_from')
            ->first();

        if ($structure) {
            return (float) $structure->amount;
        }

        // Fall back to first active earning component
        $structure = SalaryStructure::where('employee_id', $employee->id)
            ->whereHas('component', fn ($q) => $q->where('type', 'earning'))
            ->whereNull('effective_to')
            ->latest('effective_from')
            ->first();

        return $structure ? (float) $structure->amount : 0.0;
    }
}
