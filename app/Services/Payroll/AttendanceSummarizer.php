<?php

namespace App\Services\Payroll;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\OvertimeRequest;
use App\Services\Leave\LeaveDays;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Turns a pay period's daily attendance into the figures payroll needs.
 *
 *   basis days    the branch's pay-day basis (30, 26, ... or 0 = calendar days)
 *   employed days calendar days within the period the person was employed
 *   LOP days      per working day, whatever wasn't worked or covered by paid
 *                 leave: absent = 1, half day = 0.5, unpaid leave = its share,
 *                 absent for the other half of a half-day leave = 0.5; plus
 *                 an optional late-mark penalty (every N lates = 0.5 day)
 *   payable days  basis x employed/calendar - LOP  (never below zero)
 *
 * Pro-rated earnings are paid at payable / basis.
 */
class AttendanceSummarizer
{
    public function summarize(Employee $employee, Branch $branch, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $calendarDays = $start->daysInMonth;
        $basis = (int) ($branch->payroll_days_in_month ?: 0) ?: $calendarDays;

        $joined = $employee->date_of_joining ? CarbonImmutable::parse($employee->date_of_joining)->startOfDay() : null;
        $left = $employee->date_of_leaving ? CarbonImmutable::parse($employee->date_of_leaving)->startOfDay() : null;
        $employedFrom = $joined && $joined->gt($start) ? $joined : $start;
        $employedTo = $left && $left->lt($end) ? $left : $end;
        $employedDays = $employedFrom->gt($employedTo) ? 0 : (int) $employedFrom->diffInDays($employedTo) + 1;

        $rows = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get(['date', 'status', 'late_by_minutes', 'overtime_minutes', 'is_weekly_off', 'is_holiday']);

        $count = fn (string $status) => $rows->where('status', $status)->count();
        $absent = $count('absent');
        $halfDays = $count('half_day');
        $late = $count('late');

        // Approved leave per date, split paid / unpaid, on the days each
        // leave actually took (same rule as when it was charged).
        $rowsByDate = $rows->keyBy(fn ($r) => $r->date->toDateString());
        $isOff = fn (string $date) => ($row = $rowsByDate->get($date))
            ? ($row->is_weekly_off || $row->is_holiday)
            : ! $branch->isWorkingDay(CarbonImmutable::parse($date));

        $cover = [];
        $leaves = Leave::with('leaveType:id,paid,sandwich_rule')
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->get(['id', 'leave_type_id', 'start_date', 'end_date', 'days', 'half_day_session']);

        foreach ($leaves as $leave) {
            $from = CarbonImmutable::parse($leave->start_date)->max($start);
            $to = CarbonImmutable::parse($leave->end_date)->min($end);
            foreach (CarbonPeriod::create($from, $to) as $d) {
                $date = $d->toDateString();
                $share = LeaveDays::fractionOn($date, $leave->start_date->toDateString(), $leave->end_date->toDateString(),
                    $isOff($date), (bool) $leave->leaveType?->sandwich_rule, $leave->half_day_session);
                if ($share > 0) {
                    $key = ($leave->leaveType?->paid ?? true) ? 'paid' : 'unpaid';
                    $cover[$date][$key] = min(1.0, ($cover[$date][$key] ?? 0) + $share);
                }
            }
        }

        $credit = ['present' => 1.0, 'late' => 1.0, 'half_day' => 0.5, 'absent' => 0.0, 'on_leave' => 0.0];
        $lop = 0.0;
        $unpaidLeaveDays = 0.0;
        $paidLeaveDays = 0.0;

        foreach (array_unique(array_merge($rowsByDate->keys()->all(), array_keys($cover))) as $date) {
            $row = $rowsByDate->get($date);
            $paid = $cover[$date]['paid'] ?? 0.0;
            $unpaid = $cover[$date]['unpaid'] ?? 0.0;
            $unpaidLeaveDays += $unpaid;
            $paidLeaveDays += $paid;

            if ($isOff($date) || ! $row || ! array_key_exists($row->status, $credit)) {
                // Off days and days with no record only lose pay to unpaid leave
                // (a sandwiched off day, or leave on a day never processed).
                $lop += $unpaid;

                continue;
            }

            // A day marked on leave without a matching leave record (entered
            // by hand) counts as paid leave, as it always has.
            if ($row->status === 'on_leave' && $paid + $unpaid <= 0) {
                $paid = 1.0;
                $paidLeaveDays += 1.0;
            }

            $worked = min($credit[$row->status], max(0.0, 1 - $paid - $unpaid));
            $lop += max(0.0, 1 - $worked - $paid);
        }

        $latePenalty = 0.0;
        $latesPerHalfDay = (int) $branch->attendanceSetting('late_marks_per_half_day', 0);
        if ($latesPerHalfDay > 0) {
            $latePenalty = intdiv($late, $latesPerHalfDay) * 0.5;
        }

        $lopDays = $lop + $latePenalty;
        $basisForEmployment = $basis * $employedDays / max(1, $calendarDays);
        $payable = max(0.0, round($basisForEmployment - $lopDays, 2));

        $otMinutesApproved = (float) OvertimeRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->sum('hours') * 60;

        return [
            'calendar_days' => $calendarDays,
            'basis_days' => $basis,
            'employed_days' => $employedDays,
            'present_days' => $count('present') + $late,
            'absent_days' => $absent,
            'half_days' => $halfDays,
            'late_marks' => $late,
            'late_penalty_days' => $latePenalty,
            'unpaid_leave_days' => round($unpaidLeaveDays, 2),
            'paid_leave_days' => round($paidLeaveDays, 2),
            'weekly_offs' => $count('weekly_off'),
            'holidays' => $count('holiday'),
            'lop_days' => round($lopDays, 2),
            'payable_days' => $payable,
            'proration' => $basis > 0 ? min(1.0, $payable / $basis) : 0.0,
            'ot_hours_approved' => round($otMinutesApproved / 60, 2),
            'attendance_ot_minutes' => (int) $rows->sum('overtime_minutes'),
        ];
    }
}
