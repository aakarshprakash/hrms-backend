<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rule-based intelligence layer: analyses live HR data and surfaces
 * prioritised, human-readable insights and workforce analytics.
 */
class AiInsightsController extends Controller
{
    public function insights(Request $request): JsonResponse
    {
        $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();

        // Qualified with the table name (not just 'branch_id') because this
        // scope also gets applied to queries joined with other
        // branch_id-having tables (e.g. departments in $byDepartment below),
        // where an unqualified column reference is ambiguous to MySQL.
        $scope = fn ($q) => $branchId ? $q->where('employees.branch_id', $branchId) : $q;
        $empScope = fn ($q) => $branchId
            ? $q->whereHas('employee', fn ($e) => $e->where('branch_id', $branchId))
            : $q;

        $insights = [];

        // --- Attendance intelligence (this month) ---
        $activeCount = $scope(Employee::where('status', 'active'))->count();

        $lateByEmployee = $empScope(Attendance::where('status', 'late')
            ->whereDate('date', '>=', $monthStart))
            ->select('employee_id', DB::raw('COUNT(*) as late_count'))
            ->groupBy('employee_id')
            ->having('late_count', '>=', 3)
            ->with('employee:id,first_name,last_name,employee_code')
            ->orderByDesc('late_count')
            ->limit(5)
            ->get();

        foreach ($lateByEmployee as $row) {
            if (! $row->employee) {
                continue;
            }
            $insights[] = [
                'severity' => 'warning',
                'category' => 'attendance',
                'title' => "Frequent late arrivals: {$row->employee->first_name} {$row->employee->last_name}",
                'detail' => "{$row->late_count} late check-ins this month. Consider a conversation or a shift adjustment.",
                'link' => "/employees/{$row->employee->id}",
            ];
        }

        $absentByEmployee = $empScope(Attendance::where('status', 'absent')
            ->whereDate('date', '>=', $monthStart))
            ->select('employee_id', DB::raw('COUNT(*) as absent_count'))
            ->groupBy('employee_id')
            ->having('absent_count', '>=', 3)
            ->with('employee:id,first_name,last_name')
            ->orderByDesc('absent_count')
            ->limit(5)
            ->get();

        foreach ($absentByEmployee as $row) {
            if (! $row->employee) {
                continue;
            }
            $insights[] = [
                'severity' => 'critical',
                'category' => 'attendance',
                'title' => "High absenteeism: {$row->employee->first_name} {$row->employee->last_name}",
                'detail' => "{$row->absent_count} unexplained absences this month — a possible attrition or wellbeing signal.",
                'link' => "/employees/{$row->employee->id}",
            ];
        }

        // --- Approvals pile-up ---
        $pendingLeaves = $empScope(Leave::where('status', 'pending'))->count();
        if ($pendingLeaves > 0) {
            $insights[] = [
                'severity' => $pendingLeaves >= 5 ? 'warning' : 'info',
                'category' => 'approvals',
                'title' => "{$pendingLeaves} leave request" . ($pendingLeaves > 1 ? 's' : '') . ' awaiting approval',
                'detail' => 'Delayed approvals hurt planning — review and clear the queue.',
                'link' => '/leaves',
            ];
        }

        // --- Probation endings in the next 30 days ---
        $probation = $scope(Employee::where('status', 'active')
            ->whereNotNull('probation_end_date')
            ->whereBetween('probation_end_date', [$today, $today->copy()->addDays(30)]))
            ->orderBy('probation_end_date')
            ->get(['id', 'first_name', 'last_name', 'probation_end_date']);

        foreach ($probation as $emp) {
            $insights[] = [
                'severity' => 'info',
                'category' => 'lifecycle',
                'title' => "Probation ends soon: {$emp->first_name} {$emp->last_name}",
                'detail' => 'Ends on ' . $emp->probation_end_date->format('d M Y') . ' — schedule the confirmation review.',
                'link' => "/employees/{$emp->id}",
            ];
        }

        // --- Upcoming birthdays & work anniversaries (next 14 days) ---
        $celebrations = $scope(Employee::where('status', 'active'))
            ->whereNotNull('date_of_birth')
            ->get(['id', 'first_name', 'last_name', 'date_of_birth', 'date_of_joining'])
            ->filter(function ($emp) use ($today) {
                $bday = $emp->date_of_birth->setYear($today->year);
                if ($bday->lt($today)) {
                    $bday = $bday->addYear();
                }

                return $bday->between($today, $today->copy()->addDays(14));
            })
            ->take(5);

        foreach ($celebrations as $emp) {
            $bday = $emp->date_of_birth->setYear($today->year);
            if ($bday->lt($today)) {
                $bday = $bday->addYear();
            }
            $insights[] = [
                'severity' => 'positive',
                'category' => 'engagement',
                'title' => "Birthday coming up: {$emp->first_name} {$emp->last_name}",
                'detail' => $bday->format('d M') . ' — a small gesture goes a long way for engagement.',
                'link' => "/employees/{$emp->id}",
            ];
        }

        // --- Data hygiene: incomplete employee profiles ---
        $incomplete = $scope(Employee::where('status', 'active'))
            ->where(function ($q) {
                $q->whereNull('phone')
                  ->orWhereNull('date_of_birth')
                  ->orWhereNull('emergency_contact_phone')
                  ->orWhereNull('bank_account_number');
            })
            ->count();

        if ($incomplete > 0) {
            $insights[] = [
                'severity' => 'info',
                'category' => 'data',
                'title' => "{$incomplete} employee profile" . ($incomplete > 1 ? 's are' : ' is') . ' incomplete',
                'detail' => 'Missing phone, emergency contact or bank details block payroll and safety readiness.',
                'link' => '/employees',
            ];
        }

        // --- Attrition trend (terminated in last 90 days) ---
        $attrition = $scope(Employee::where('status', 'terminated')
            ->where('updated_at', '>=', $today->copy()->subDays(90)))
            ->count();

        if ($activeCount > 0 && $attrition > 0) {
            $rate = round($attrition / max($activeCount + $attrition, 1) * 100, 1);
            $insights[] = [
                'severity' => $rate >= 10 ? 'critical' : 'info',
                'category' => 'attrition',
                'title' => "Attrition: {$attrition} exit" . ($attrition > 1 ? 's' : '') . " in the last 90 days ({$rate}%)",
                'detail' => $rate >= 10
                    ? 'Above the healthy threshold — investigate exit reasons by department.'
                    : 'Within a normal range. Keep monitoring.',
                'link' => '/employees?status=terminated',
            ];
        }

        // --- Analytics blocks for charts ---
        $byBranch = Employee::where('status', 'active')
            ->join('branches', 'branches.id', '=', 'employees.branch_id')
            ->select('branches.name as label', DB::raw('COUNT(employees.id) as value'))
            ->groupBy('branches.name')
            ->orderByDesc('value')
            ->get();

        $byDepartment = $scope(Employee::where('status', 'active'))
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->select(DB::raw("COALESCE(departments.name, 'Unassigned') as label"), DB::raw('COUNT(employees.id) as value'))
            ->groupBy('departments.name')
            ->orderByDesc('value')
            ->get();

        $byType = $scope(Employee::where('status', 'active'))
            ->select('employment_type as label', DB::raw('COUNT(*) as value'))
            ->groupBy('employment_type')
            ->get();

        // Attendance trend: last 14 days present/late/absent
        $trend = $empScope(Attendance::whereDate('date', '>=', $today->copy()->subDays(13)))
            ->select(
                DB::raw('DATE(date) as day'),
                DB::raw("SUM(status IN ('present','late')) as present"),
                DB::raw("SUM(status = 'late') as late"),
                DB::raw("SUM(status = 'absent') as absent")
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $severityRank = ['critical' => 0, 'warning' => 1, 'info' => 2, 'positive' => 3];
        usort($insights, fn ($a, $b) => ($severityRank[$a['severity']] ?? 9) <=> ($severityRank[$b['severity']] ?? 9));

        return response()->json([
            'data' => [
                'generated_at' => now()->toIso8601String(),
                'insights' => $insights,
                'analytics' => [
                    'headcount_by_branch' => $byBranch,
                    'headcount_by_department' => $byDepartment,
                    'headcount_by_type' => $byType,
                    'attendance_trend' => $trend,
                ],
            ],
        ]);
    }
}
