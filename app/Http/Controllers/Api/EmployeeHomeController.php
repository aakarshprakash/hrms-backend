<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceRegularization;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\OvertimeRequest;
use App\Models\Payslip;
use App\Models\Scopes\BranchScope;
use App\Services\ApprovalWorkflowService;
use App\Services\Attendance\ScheduleResolver;
use App\Services\Leave\LeaveRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything an employee checks first, in one call: today's shift and
 * punches, the week ahead, leave balances and upcoming leave, the latest
 * payslip, open requests, holidays -- and, for managers, their team today.
 */
class EmployeeHomeController extends Controller
{
    public function __construct(
        private ScheduleResolver $schedules,
        private LeaveRequestService $leaves,
        private ApprovalWorkflowService $workflow,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $this->actorEmployee();

        if (! $employee) {
            return response()->json(['data' => null]);
        }

        $employee->load(['designation:id,title', 'department:id,name', 'branch:id,name,timezone,city',
            'reportingManager' => fn ($q) => $q->withoutGlobalScope(BranchScope::class)->select('id', 'first_name', 'last_name', 'phone', 'email')]);

        $tz = $employee->branch?->timezone ?: config('app.timezone');
        $now = CarbonImmutable::now($tz);
        $today = $now->toDateString();

        // The week ahead from the employee's own schedule (roster, shift, offs, holidays, leave).
        $week = collect($this->schedules->forEmployee($employee, $today, $now->addDays(6)->toDateString()))
            ->map(fn ($day) => [
                'date' => $day->date,
                'shift' => $day->shift ? [
                    'name' => $day->shift->name,
                    'start' => substr((string) $day->shift->start_time, 0, 5),
                    'end' => substr((string) $day->shift->end_time, 0, 5),
                    'color' => $day->shift->color,
                ] : null,
                'weekly_off' => $day->isWeeklyOff,
                'holiday' => $day->holidayName,
                'leave' => $day->leaveStatus,
                'leave_session' => $day->leaveSession,
            ])
            ->values();

        $attendanceToday = Attendance::where('employee_id', $employee->id)->whereDate('date', $today)
            ->first(['id', 'status', 'check_in', 'check_out', 'late_by_minutes', 'worked_minutes', 'anomaly']);

        $monthRows = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$now->startOfMonth()->toDateString(), $today])
            ->pluck('status');

        $payslip = Payslip::published()->with('payrollRun:id,year,month')
            ->where('employee_id', $employee->id)
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->orderByDesc('payroll_runs.year')->orderByDesc('payroll_runs.month')
            ->select('payslips.*')
            ->first();

        $upcomingLeave = Leave::with('leaveType:id,name,code,color')
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where('end_date', '>=', $today)
            ->orderBy('start_date')
            ->limit(4)
            ->get(['id', 'leave_type_id', 'start_date', 'end_date', 'days', 'half_day_session', 'status']);

        $balances = collect($this->leaves->summary($employee))
            ->filter(fn ($row) => $row['leave_type']['is_active'] && ($row['balance_id'] || $row['unlimited']))
            ->values();

        $holidays = Holiday::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $employee->branch_id)
            ->where('date', '>=', $today)
            ->orderBy('date')->limit(4)
            ->get(['id', 'name', 'date']);

        return response()->json(['data' => [
            'employee' => [
                'id' => $employee->id,
                'name' => trim("{$employee->first_name} {$employee->last_name}"),
                'first_name' => $employee->first_name,
                'employee_code' => $employee->employee_code,
                'designation' => $employee->designation?->title,
                'department' => $employee->department?->name,
                'branch' => $employee->branch?->name,
                'avatar_url' => $employee->avatar_url,
                'date_of_joining' => $employee->date_of_joining?->toDateString(),
                'manager' => $employee->reportingManager ? [
                    'name' => trim("{$employee->reportingManager->first_name} {$employee->reportingManager->last_name}"),
                    'phone' => $employee->reportingManager->phone,
                ] : null,
            ],
            'today' => [
                'date' => $today,
                'schedule' => $week->first(),
                'attendance' => $attendanceToday,
            ],
            'week' => $week,
            'month' => [
                'present' => $monthRows->filter(fn ($s) => in_array($s, ['present', 'late'], true))->count(),
                'late' => $monthRows->filter(fn ($s) => $s === 'late')->count(),
                'absent' => $monthRows->filter(fn ($s) => $s === 'absent')->count(),
                'half_day' => $monthRows->filter(fn ($s) => $s === 'half_day')->count(),
                'on_leave' => $monthRows->filter(fn ($s) => $s === 'on_leave')->count(),
            ],
            'leave' => ['balances' => $balances, 'upcoming' => $upcomingLeave],
            'payslip' => $payslip ? [
                'id' => $payslip->id,
                'year' => $payslip->payrollRun?->year,
                'month' => $payslip->payrollRun?->month,
                'net_pay' => (float) $payslip->net_pay,
                'gross_pay' => (float) $payslip->gross_pay,
                'total_deductions' => (float) $payslip->total_deductions,
            ] : null,
            'requests' => [
                'leave' => Leave::where('employee_id', $employee->id)->where('status', 'pending')->count(),
                'regularization' => AttendanceRegularization::where('employee_id', $employee->id)->where('status', 'pending')->count(),
                'overtime' => OvertimeRequest::where('employee_id', $employee->id)->where('status', 'pending')->count(),
            ],
            'holidays' => $holidays,
            'team' => $this->team($user, $employee, $today),
        ]]);
    }

    /** A manager's team today: who's away, and what's waiting for them. */
    private function team($user, Employee $employee, string $today): ?array
    {
        $reports = Employee::withoutGlobalScope(BranchScope::class)
            ->where('reporting_manager_id', $employee->id)->where('status', 'active')
            ->get(['id', 'first_name', 'last_name']);

        if ($reports->isEmpty()) {
            return null;
        }

        $away = Leave::with('leaveType:id,name,color')
            ->whereIn('employee_id', $reports->pluck('id'))
            ->where('status', 'approved')
            ->where('start_date', '<=', $today)->where('end_date', '>=', $today)
            ->get(['id', 'employee_id', 'leave_type_id', 'half_day_session']);

        $presentIds = Attendance::whereIn('employee_id', $reports->pluck('id'))->whereDate('date', $today)
            ->whereNotNull('check_in')->pluck('employee_id');

        $waiting = 0;
        if ($user->can('leaves.approve')) {
            foreach ([Leave::class, AttendanceRegularization::class, OvertimeRequest::class] as $class) {
                $waiting += $this->workflow->pendingFor($user, $class)->filter(fn ($r) => $this->workflow->isDesignated($r, $user))->count();
            }
        }

        $names = $reports->keyBy('id');

        return [
            'size' => $reports->count(),
            'checked_in' => $presentIds->unique()->count(),
            'on_leave' => $away->map(fn ($l) => [
                'name' => trim(($names[$l->employee_id]->first_name ?? '') . ' ' . ($names[$l->employee_id]->last_name ?? '')),
                'type' => $l->leaveType?->name,
                'color' => $l->leaveType?->color,
                'half_day_session' => $l->half_day_session,
            ])->values(),
            'approvals_waiting' => $waiting,
        ];
    }
}
