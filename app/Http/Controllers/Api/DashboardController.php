<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** Headline counts, limited to what the viewer's data scope covers. */
    public function stats(Request $request)
    {
        $user = $request->user();
        $branchId = $this->requestedBranchId();
        $today = Carbon::today()->toDateString();

        $inBranch = fn ($q) => $branchId ? $q->whereHas('employee', fn ($e) => $e->where('branch_id', $branchId)) : $q;

        $employeesCount = Employee::visibleTo($user)
            ->where('status', 'active')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->count();

        $presentToday = $inBranch(Attendance::visibleTo($user)->whereDate('date', $today)
            ->whereIn('status', ['present', 'late']))->count();

        $pendingLeaves = $inBranch(Leave::visibleTo($user)->where('status', 'pending'))->count();

        $onLeaveToday = $inBranch(Leave::visibleTo($user)->where('status', 'approved')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today))->count();

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
