<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceReportController extends Controller
{
    private function assertCanView(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user->is_super_admin || $user->hasAnyRole(['super_admin', 'branch_admin', 'hr', 'manager']) || $user->can('attendance.view'),
            403,
            'You are not allowed to view attendance reports.'
        );
    }

    /**
     * Per-employee monthly rollup: present/late/half-day/absent/leave/holiday
     * counts and total hours worked — the standard "attendance report" screen.
     */
    public function summary(Request $request)
    {
        $this->assertCanView($request);

        $validated = $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $rows = $this->buildSummary($validated);

        return response()->json(['data' => $rows]);
    }

    public function summaryExport(Request $request): StreamedResponse
    {
        $this->assertCanView($request);

        $validated = $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $rows = $this->buildSummary($validated);
        $filename = "attendance-summary-{$validated['year']}-{$validated['month']}.csv";

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Employee Code', 'Name', 'Branch', 'Department', 'Present', 'Late', 'Half Day', 'Absent', 'On Leave', 'Holidays', 'Worked Hours', 'Avg Late (min)']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['employee']['employee_code'], $r['employee']['name'], $r['employee']['branch'], $r['employee']['department'],
                    $r['present_days'], $r['late_days'], $r['half_days'], $r['absent_days'], $r['leave_days'], $r['holiday_days'],
                    $r['worked_hours'], $r['avg_late_minutes'],
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function buildSummary(array $filters): array
    {
        $month = $filters['month'];
        $year = $filters['year'];
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $employees = Employee::with(['branch:id,name', 'department:id,name'])
            ->where('status', 'active')
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['department_id']), fn ($q) => $q->where('department_id', $filters['department_id']))
            ->orderBy('first_name')
            ->get();

        if ($employees->isEmpty()) {
            return [];
        }

        $employeeIds = $employees->pluck('id');

        $statusCounts = Attendance::whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->select('employee_id', 'status', DB::raw('COUNT(*) as total'), DB::raw('SUM(worked_minutes) as total_worked'), DB::raw('AVG(late_by_minutes) as avg_late'))
            ->groupBy('employee_id', 'status')
            ->get()
            ->groupBy('employee_id');

        $leaveDays = Leave::whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $monthEnd)
            ->whereDate('end_date', '>=', $monthStart)
            ->get()
            ->groupBy('employee_id')
            ->map(function ($leaves) use ($monthStart, $monthEnd) {
                $days = 0;
                foreach ($leaves as $leave) {
                    $from = Carbon::parse($leave->start_date)->max($monthStart);
                    $to = Carbon::parse($leave->end_date)->min($monthEnd);
                    if ($from->lte($to)) {
                        $days += (int) $from->diffInDays($to) + 1;
                    }
                }
                return $days;
            });

        $branchIds = $employees->pluck('branch_id')->unique();
        $holidaysByBranch = Holiday::whereIn('branch_id', $branchIds)
            ->get()
            ->filter(function ($h) use ($monthStart, $monthEnd, $year) {
                $d = $h->recurring ? $h->date->copy()->setYear($year) : $h->date;
                return $d->between($monthStart, $monthEnd);
            })
            ->groupBy('branch_id')
            ->map->count();

        return $employees->map(function ($emp) use ($statusCounts, $leaveDays, $holidaysByBranch) {
            $counts = $statusCounts->get($emp->id, collect())->keyBy('status');

            return [
                'employee' => [
                    'id' => $emp->id,
                    'employee_code' => $emp->employee_code,
                    'name' => $emp->full_name,
                    'branch' => $emp->branch?->name,
                    'department' => $emp->department?->name,
                ],
                'present_days' => ($counts->get('present')->total ?? 0) + ($counts->get('late')->total ?? 0),
                'late_days' => $counts->get('late')->total ?? 0,
                'half_days' => $counts->get('half_day')->total ?? 0,
                'absent_days' => $counts->get('absent')->total ?? 0,
                'leave_days' => $leaveDays->get($emp->id, 0),
                'holiday_days' => $holidaysByBranch->get($emp->branch_id, 0),
                'worked_hours' => round((float) collect($counts)->sum('total_worked') / 60, 1),
                'avg_late_minutes' => $counts->get('late')?->avg_late ? round((float) $counts->get('late')->avg_late) : 0,
            ];
        })->values()->all();
    }

    /**
     * One row per employee per day, with actual check-in/check-out times --
     * the detail view behind the monthly summary's day counts.
     */
    public function daily(Request $request)
    {
        $this->assertCanView($request);

        $validated = $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $rows = $this->buildDaily($validated);

        return response()->json(['data' => $rows]);
    }

    public function dailyExport(Request $request): StreamedResponse
    {
        $this->assertCanView($request);

        $validated = $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $rows = $this->buildDaily($validated);
        $filename = "attendance-daily-{$validated['year']}-{$validated['month']}.csv";

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Employee Code', 'Name', 'Branch', 'Department', 'Date', 'Status', 'Check In', 'Check Out', 'Worked Hours', 'Late By (min)']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['employee']['employee_code'], $r['employee']['name'], $r['employee']['branch'], $r['employee']['department'],
                    $r['date'], $r['status'], $r['check_in'] ?? '', $r['check_out'] ?? '', $r['worked_hours'] ?? '', $r['late_by_minutes'] ?? '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function buildDaily(array $filters): array
    {
        $month = $filters['month'];
        $year = $filters['year'];
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $employees = Employee::with(['branch:id,name', 'department:id,name'])
            ->where('status', 'active')
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['department_id']), fn ($q) => $q->where('department_id', $filters['department_id']))
            ->when(!empty($filters['employee_id']), fn ($q) => $q->where('id', $filters['employee_id']))
            ->orderBy('first_name')
            ->get();

        if ($employees->isEmpty()) {
            return [];
        }

        $employeesById = $employees->keyBy('id');

        $attendances = Attendance::whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->orderBy('date')
            ->get();

        $rows = [];
        foreach ($attendances as $att) {
            $emp = $employeesById->get($att->employee_id);
            if (!$emp) {
                continue;
            }

            $rows[] = [
                'employee' => [
                    'id' => $emp->id,
                    'employee_code' => $emp->employee_code,
                    'name' => $emp->full_name,
                    'branch' => $emp->branch?->name,
                    'department' => $emp->department?->name,
                ],
                'date' => $att->date->toDateString(),
                'status' => $att->status,
                'check_in' => $att->check_in?->format('H:i'),
                'check_out' => $att->check_out?->format('H:i'),
                'worked_hours' => $att->worked_minutes ? round($att->worked_minutes / 60, 1) : null,
                'late_by_minutes' => $att->late_by_minutes,
            ];
        }

        usort($rows, fn ($a, $b) => [$a['employee']['name'], $a['date']] <=> [$b['employee']['name'], $b['date']]);

        return $rows;
    }

    /**
     * Classic employee × day grid (muster roll) — a statutory-style register
     * showing a single status letter per employee per day of the month.
     */
    public function musterRoll(Request $request)
    {
        $this->assertCanView($request);

        $validated = $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $month = $validated['month'];
        $year = $validated['year'];
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $today = Carbon::today();

        $employees = Employee::where('status', 'active')
            ->where('branch_id', $validated['branch_id'])
            ->when(!empty($validated['department_id']), fn ($q) => $q->where('department_id', $validated['department_id']))
            ->orderBy('first_name')
            ->get(['id', 'employee_code', 'first_name', 'last_name']);

        $days = [];
        foreach (CarbonPeriod::create($monthStart, $monthEnd) as $d) {
            $days[] = $d->day;
        }

        if ($employees->isEmpty()) {
            return response()->json(['data' => ['days' => $days, 'rows' => []]]);
        }

        $employeeIds = $employees->pluck('id');

        $attendanceByEmployeeDay = Attendance::whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->get()
            ->groupBy(fn ($a) => $a->employee_id . '-' . Carbon::parse($a->date)->day);

        $holidayDates = Holiday::where('branch_id', $validated['branch_id'])
            ->get()
            ->map(fn ($h) => ($h->recurring ? $h->date->copy()->setYear($year) : $h->date)->toDateString())
            ->filter(fn ($d) => $d >= $monthStart->toDateString() && $d <= $monthEnd->toDateString())
            ->flip();

        $leavesByEmployee = Leave::whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $monthEnd)
            ->whereDate('end_date', '>=', $monthStart)
            ->get()
            ->groupBy('employee_id');

        $statusCode = ['present' => 'P', 'late' => 'P', 'half_day' => 'HD', 'absent' => 'A', 'on_leave' => 'L'];
        $branch = Branch::find($validated['branch_id']);

        $rows = $employees->map(function ($emp) use ($days, $monthStart, $attendanceByEmployeeDay, $holidayDates, $leavesByEmployee, $statusCode, $today, $year, $month, $branch) {
            $cells = [];
            foreach ($days as $day) {
                $date = Carbon::create($year, $month, $day);
                $dateKey = $date->toDateString();

                $att = $attendanceByEmployeeDay->get($emp->id . '-' . $day)?->first();

                if ($att) {
                    $cells[$day] = ['code' => $statusCode[$att->status] ?? '?', 'late' => $att->status === 'late'];
                    continue;
                }

                if ($holidayDates->has($dateKey)) {
                    $cells[$day] = ['code' => 'H', 'late' => false];
                } elseif ($branch && !$branch->isWorkingDay($date)) {
                    $cells[$day] = ['code' => 'W', 'late' => false];
                } elseif (($leavesByEmployee->get($emp->id) ?? collect())->contains(fn ($l) => $dateKey >= $l->start_date->toDateString() && $dateKey <= $l->end_date->toDateString())) {
                    $cells[$day] = ['code' => 'L', 'late' => false];
                } elseif ($date->gt($today)) {
                    $cells[$day] = ['code' => '', 'late' => false];
                } else {
                    $cells[$day] = ['code' => '-', 'late' => false];
                }
            }

            return [
                'employee' => ['id' => $emp->id, 'employee_code' => $emp->employee_code, 'name' => $emp->full_name],
                'cells' => $cells,
            ];
        });

        return response()->json(['data' => ['days' => $days, 'rows' => $rows->values()]]);
    }
}
