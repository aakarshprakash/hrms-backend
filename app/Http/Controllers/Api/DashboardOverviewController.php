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
use App\Support\Billing\Features;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The organisation dashboard in one call, for the people the viewer may see
 * (optionally one branch): headcount and movement, today's attendance by
 * each person's own schedule, a two-week trend, who is out, what is waiting
 * for approval, where people work, payroll cost and what's coming up.
 */
class DashboardOverviewController extends Controller
{
    public function __construct(
        private ScheduleResolver $schedules,
        private ApprovalWorkflowService $workflow,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $this->requestedBranchId();
        $company = app(TenantContext::class)->company();
        $tz = $company?->timezone ?: config('app.timezone');
        $now = CarbonImmutable::now($tz);
        $today = $now->toDateString();

        $people = Employee::query()->visibleTo($user)
            ->where('status', 'active')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $ids = (clone $people)->pluck('id');

        // ── Headcount & movement ──
        $monthStart = $now->startOfMonth()->toDateString();
        $joiners = (clone $people)->whereBetween('date_of_joining', [$monthStart, $today])->count();
        $exits = Employee::query()->visibleTo($user)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('date_of_leaving', [$monthStart, $now->endOfMonth()->toDateString()])->count();

        // ── Today, by each person's own schedule ──
        $employees = Employee::withoutGlobalScope(BranchScope::class)->whereIn('id', $ids)
            ->get(['id', 'branch_id', 'weekly_off_days', 'first_name', 'last_name']);
        $schedule = $this->schedules->forEmployees($employees, $today, $today);
        $rows = Attendance::whereIn('employee_id', $ids)->whereDate('date', $today)
            ->get(['employee_id', 'status', 'check_in', 'late_by_minutes'])->keyBy('employee_id');

        $counts = ['on_time' => 0, 'late' => 0, 'not_in' => 0, 'on_leave' => 0, 'off' => 0];
        foreach ($employees as $e) {
            $day = $schedule[$e->id][$today] ?? null;
            $row = $rows->get($e->id);
            if ($day && $day->leaveStatus === 'full') {
                $counts['on_leave']++;
            } elseif ($row && $row->check_in) {
                $row->status === 'late' || $row->late_by_minutes ? $counts['late']++ : $counts['on_time']++;
            } elseif ($day && ! $day->isWorkingDay()) {
                $counts['off']++;
            } else {
                $counts['not_in']++;
            }
        }
        $scheduled = $counts['on_time'] + $counts['late'] + $counts['not_in'];

        // ── Two-week trend (completed days only; today is still in progress) ──
        $from = $now->subDays(14)->toDateString();
        $to = $now->subDay()->toDateString();
        $trendRows = Attendance::whereIn('employee_id', $ids)->whereBetween('date', [$from, $to])
            ->select('date', 'status', DB::raw('COUNT(*) as n'))->groupBy('date', 'status')->get();
        $trend = collect(CarbonPeriod::create($from, $to))->map(function ($d) use ($trendRows) {
            $day = $trendRows->filter(fn ($r) => CarbonImmutable::parse($r->date)->toDateString() === $d->toDateString());
            $n = fn (array $s) => (int) $day->whereIn('status', $s)->sum('n');
            $present = $n(['present', 'late']);
            $working = $present + $n(['absent', 'half_day']) + $n(['on_leave']);

            return [
                'date' => $d->toDateString(),
                'present' => $present,
                'late' => $n(['late']),
                'absent' => $n(['absent']),
                'on_leave' => $n(['on_leave']),
                'rate' => $working > 0 ? round($present / $working * 100, 1) : null,
            ];
        })->values();

        // ── Who is out today ──
        $out = Leave::with(['employee:id,first_name,last_name,designation_id', 'employee.designation:id,title', 'leaveType:id,name,color'])
            ->whereIn('employee_id', $ids)->where('status', 'approved')
            ->where('start_date', '<=', $today)->where('end_date', '>=', $today)
            ->orderBy('end_date')->limit(8)->get()
            ->map(fn (Leave $l) => [
                'id' => $l->id,
                'name' => trim("{$l->employee?->first_name} {$l->employee?->last_name}"),
                'designation' => $l->employee?->designation?->title,
                'type' => $l->leaveType?->name,
                'color' => $l->leaveType?->color,
                'half_day_session' => $l->half_day_session,
                'back_on' => CarbonImmutable::parse($l->end_date)->addDay()->toDateString(),
            ]);

        // ── Waiting for approval ──
        $approvals = null;
        if ($user->can('leaves.approve')) {
            $approvals = ['leave' => 0, 'regularization' => 0, 'overtime' => 0];
            foreach (['leave' => Leave::class, 'regularization' => AttendanceRegularization::class, 'overtime' => OvertimeRequest::class] as $key => $class) {
                $approvals[$key] = $this->workflow->pendingFor($user, $class)->filter(fn ($r) => $this->workflow->isDesignated($r, $user))->count();
            }
            $approvals['total'] = array_sum($approvals);
            $approvals['all_pending'] = Leave::whereIn('employee_id', $ids)->where('status', 'pending')->count()
                + AttendanceRegularization::whereIn('employee_id', $ids)->where('status', 'pending')->count()
                + OvertimeRequest::whereIn('employee_id', $ids)->where('status', 'pending')->count();
        }

        // ── Where people work ──
        $byDepartment = Employee::withoutGlobalScope(BranchScope::class)->whereIn('employees.id', $ids)
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->select(DB::raw("COALESCE(departments.name, 'Unassigned') as name"), DB::raw('COUNT(*) as value'))
            ->groupBy('name')->orderByDesc('value')->limit(8)->get()->map(fn ($r) => ['name' => $r->name, 'value' => (int) $r->value])->values();
        $byBranch = Employee::withoutGlobalScope(BranchScope::class)->whereIn('employees.id', $ids)
            ->join('branches', 'branches.id', '=', 'employees.branch_id')
            ->select('branches.name', DB::raw('COUNT(*) as value'))
            ->groupBy('branches.name')->orderByDesc('value')->get()->map(fn ($r) => ['name' => $r->name, 'value' => (int) $r->value])->values();
        $gender = Employee::withoutGlobalScope(BranchScope::class)->whereIn('id', $ids)
            ->select(DB::raw("COALESCE(gender, 'unspecified') as name"), DB::raw('COUNT(*) as value'))
            ->groupBy('name')->get()->map(fn ($r) => ['name' => $r->name, 'value' => (int) $r->value])->values();

        // ── Payroll cost, last six months ──
        $payroll = null;
        if (($user->can('payroll.view') || $user->can('payroll.manage')) && $company && Features::has($company, 'payroll')) {
            $since = $now->startOfMonth()->subMonths(5);
            $months = Payslip::query()
                ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
                ->whereIn('payroll_runs.status', ['processed', 'finalized', 'paid'])
                ->when($branchId, fn ($q) => $q->where('payroll_runs.branch_id', $branchId))
                ->whereIn('payslips.employee_id', $ids)
                ->where(fn ($q) => $q->where('payroll_runs.year', '>', $since->year)
                    ->orWhere(fn ($w) => $w->where('payroll_runs.year', $since->year)->where('payroll_runs.month', '>=', $since->month)))
                ->groupBy('payroll_runs.year', 'payroll_runs.month')
                ->orderBy('payroll_runs.year')->orderBy('payroll_runs.month')
                ->select('payroll_runs.year', 'payroll_runs.month',
                    DB::raw('SUM(payslips.gross_pay) as gross'), DB::raw('SUM(payslips.net_pay) as net'),
                    DB::raw('SUM(payslips.employer_cost) as cost'), DB::raw('COUNT(*) as employees'))
                ->get()
                ->map(fn ($m) => [
                    'label' => CarbonImmutable::create($m->year, $m->month, 1)->format('M'),
                    'year' => (int) $m->year, 'month' => (int) $m->month,
                    'gross' => round((float) $m->gross, 2), 'net' => round((float) $m->net, 2),
                    'cost' => round((float) $m->cost, 2), 'employees' => (int) $m->employees,
                ])->values();

            $payroll = ['months' => $months, 'latest' => $months->last(), 'previous' => $months->count() > 1 ? $months[$months->count() - 2] : null];
        }

        // ── Coming up ──
        $horizon = $now->addDays(30);
        $holidays = Holiday::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('date', [$today, $horizon->toDateString()])
            ->orderBy('date')->get(['name', 'date'])
            ->unique(fn ($h) => $h->name . $h->date)->take(4)->values()
            ->map(fn ($h) => ['name' => $h->name, 'date' => CarbonImmutable::parse($h->date)->toDateString()]);

        $celebrations = collect();
        foreach (Employee::withoutGlobalScope(BranchScope::class)->whereIn('id', $ids)->get(['id', 'first_name', 'last_name', 'date_of_birth', 'date_of_joining']) as $e) {
            foreach (['birthday' => $e->date_of_birth, 'anniversary' => $e->date_of_joining] as $kind => $date) {
                if (! $date) {
                    continue;
                }
                $next = CarbonImmutable::parse($date)->setYear($now->year);
                if ($next->lt($now->startOfDay())) {
                    $next = $next->addYear();
                }
                $years = $next->year - CarbonImmutable::parse($date)->year;
                $days = (int) $now->startOfDay()->diffInDays($next);
                if ($days <= 14 && ($kind === 'birthday' || $years > 0)) {
                    $celebrations->push(['kind' => $kind, 'name' => trim("{$e->first_name} {$e->last_name}"),
                        'date' => $next->toDateString(), 'days_away' => $days, 'years' => $kind === 'anniversary' ? $years : null]);
                }
            }
        }

        $recentJoiners = (clone $people)->with('designation:id,title', 'department:id,name')
            ->orderByDesc('date_of_joining')->limit(5)
            ->get(['id', 'first_name', 'last_name', 'date_of_joining', 'designation_id', 'department_id'])
            ->map(fn ($e) => [
                'id' => $e->id, 'name' => trim("{$e->first_name} {$e->last_name}"),
                'designation' => $e->designation?->title, 'department' => $e->department?->name,
                'date_of_joining' => $e->date_of_joining?->toDateString(),
            ]);

        return response()->json(['data' => [
            'date' => $today,
            'headcount' => ['active' => $ids->count(), 'joiners' => $joiners, 'exits' => $exits],
            'today' => $counts + ['scheduled' => $scheduled, 'rate' => $scheduled > 0 ? round(($counts['on_time'] + $counts['late']) / $scheduled * 100, 1) : null],
            'trend' => $trend,
            'out_today' => $out,
            'approvals' => $approvals,
            'by_department' => $byDepartment,
            'by_branch' => $byBranch,
            'gender' => $gender,
            'payroll' => $payroll,
            'holidays' => $holidays,
            'celebrations' => $celebrations->sortBy('days_away')->take(6)->values(),
            'recent_joiners' => $recentJoiners,
        ]]);
    }
}
