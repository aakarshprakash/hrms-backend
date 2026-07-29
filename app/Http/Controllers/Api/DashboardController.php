<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $branchId = $request->query('branch_id');
        $today = Carbon::today()->toDateString();

        $employeeQuery = Employee::query()->where('status', 'active');
        if ($branchId) $employeeQuery->where('branch_id', $branchId);
        $employeesCount = $employeeQuery->count();

        $presentQuery = Attendance::whereDate('date', $today)
            ->whereIn('status', ['present', 'late']);
        if ($branchId) {
            $presentQuery->whereHas('employee', fn($q) => $q->where('branch_id', $branchId));
        }
        $presentToday = $presentQuery->count();

        $pendingLeavesQuery = Leave::where('status', 'pending');
        if ($branchId) {
            $pendingLeavesQuery->whereHas('employee', fn($q) => $q->where('branch_id', $branchId));
        }
        $pendingLeaves = $pendingLeavesQuery->count();

        $onLeaveQuery = Leave::where('status', 'approved')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today);
        if ($branchId) {
            $onLeaveQuery->whereHas('employee', fn($q) => $q->where('branch_id', $branchId));
        }
        $onLeaveToday = $onLeaveQuery->count();

        return response()->json([
            'data' => [
                'employees_count'  => $employeesCount,
                'present_today'    => $presentToday,
                'pending_leaves'   => $pendingLeaves,
                'on_leave_today'   => $onLeaveToday,
            ],
        ]);
    }
}
