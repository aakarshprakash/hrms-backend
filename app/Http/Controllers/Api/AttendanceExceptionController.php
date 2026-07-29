<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceExceptionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(
            $user->is_super_admin || $user->hasAnyRole(['super_admin', 'branch_admin', 'hr', 'manager']) || $user->can('attendance.view'),
            403,
            'You are not allowed to view attendance exceptions.'
        );

        $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;
        $lookbackDays = $request->integer('days') ?: 14;
        $today = Carbon::today();
        $windowStart = $today->copy()->subDays($lookbackDays);

        $scopeBranch = fn ($q) => $branchId
            ? $q->whereHas('employee', fn ($e) => $e->where('branch_id', $branchId))
            : $q;

        // Missed checkouts: checked in on a past day but never checked out.
        $missedCheckouts = $scopeBranch(Attendance::whereNotNull('check_in')
            ->whereNull('check_out')
            ->whereDate('date', '<', $today)
            ->whereDate('date', '>=', $windowStart))
            ->with('employee:id,first_name,last_name,employee_code')
            ->orderByDesc('date')
            ->get()
            ->filter(fn ($a) => $a->employee)
            ->map(fn ($a) => [
                'attendance_id' => $a->id,
                'employee' => ['id' => $a->employee->id, 'name' => $a->employee->full_name, 'employee_code' => $a->employee->employee_code],
                'date' => $a->date->toDateString(),
                'check_in' => $a->check_in,
            ])
            ->values();

        // Short days marked "present" despite low worked hours — either a
        // manual override or no shift assigned to catch it automatically.
        $shortDays = $scopeBranch(Attendance::where('status', 'present')
            ->whereNotNull('worked_minutes')
            ->where('worked_minutes', '<', 240)
            ->whereDate('date', '>=', $windowStart)
            ->whereDate('date', '<=', $today))
            ->with('employee:id,first_name,last_name,employee_code')
            ->orderByDesc('date')
            ->get()
            ->filter(fn ($a) => $a->employee)
            ->map(fn ($a) => [
                'attendance_id' => $a->id,
                'employee' => ['id' => $a->employee->id, 'name' => $a->employee->full_name, 'employee_code' => $a->employee->employee_code],
                'date' => $a->date->toDateString(),
                'worked_minutes' => $a->worked_minutes,
            ])
            ->values();

        // Consecutive absences: 3+ unbroken "absent" days per employee within the window.
        $absences = $scopeBranch(Attendance::where('status', 'absent')
            ->whereDate('date', '>=', $windowStart)
            ->whereDate('date', '<=', $today))
            ->with('employee:id,first_name,last_name,employee_code')
            ->orderBy('employee_id')
            ->orderBy('date')
            ->get()
            ->filter(fn ($a) => $a->employee);

        $consecutiveAbsences = [];
        $run = [];
        $flushRun = function () use (&$run, &$consecutiveAbsences) {
            if (count($run) >= 3) {
                $consecutiveAbsences[] = [
                    'employee' => ['id' => $run[0]->employee->id, 'name' => $run[0]->employee->full_name, 'employee_code' => $run[0]->employee->employee_code],
                    'start_date' => $run[0]->date->toDateString(),
                    'end_date' => end($run)->date->toDateString(),
                    'days' => count($run),
                ];
            }
            $run = [];
        };

        $prev = null;
        foreach ($absences as $a) {
            if ($prev && $prev->employee_id === $a->employee_id && (int) $prev->date->diffInDays($a->date) === 1) {
                $run[] = $a;
            } else {
                $flushRun();
                $run = [$a];
            }
            $prev = $a;
        }
        $flushRun();

        return response()->json([
            'data' => [
                'missed_checkouts' => $missedCheckouts,
                'short_days' => $shortDays,
                'consecutive_absences' => $consecutiveAbsences,
            ],
        ]);
    }
}
