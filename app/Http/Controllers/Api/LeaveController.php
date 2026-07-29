<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Services\ApprovalWorkflowService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function __construct(private ApprovalWorkflowService $approvalService) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string',
            'source_attendance_id' => 'nullable|integer|exists:attendances,id',
        ]);

        $employee = Employee::withoutGlobalScopes()
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$employee) {
            return response()->json(['message' => 'No employee profile found for this user.'], 422);
        }

        if (!empty($validated['source_attendance_id'])) {
            $sourceAttendance = Attendance::find($validated['source_attendance_id']);

            if (!$sourceAttendance || $sourceAttendance->employee_id !== $employee->id) {
                return response()->json(['message' => 'This attendance record does not belong to you.'], 403);
            }
            if ($sourceAttendance->status !== 'absent') {
                return response()->json(['message' => 'Only a day marked Absent can be converted to leave.'], 422);
            }
            if (Leave::where('source_attendance_id', $sourceAttendance->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                return response()->json(['message' => 'A leave request already exists for this day.'], 422);
            }

            // This request type means "convert this one specific day" --
            // ignore whatever start/end date the client sent and force it
            // server-side to the source day.
            $validated['start_date'] = $validated['end_date'] = $sourceAttendance->date->toDateString();
        }

        $branchId = $employee->branch_id;
        $startDate = Carbon::parse($validated['start_date']);
        $endDate = Carbon::parse($validated['end_date']);

        // Get holidays in range
        $holidayDates = Holiday::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->toArray();

        // Calculate working days (exclude weekends and holidays)
        $period = CarbonPeriod::create($startDate, $endDate);
        $days = 0;
        foreach ($period as $date) {
            if ($date->isSaturday() || $date->isSunday()) {
                continue;
            }
            if (in_array($date->format('Y-m-d'), $holidayDates)) {
                continue;
            }
            $days++;
        }

        if ($days <= 0) {
            return response()->json(['message' => 'No working days in the selected date range.'], 422);
        }

        // Check balance
        $balance = LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $validated['leave_type_id'])
            ->where('year', $startDate->year)
            ->first();

        if (!$balance || (float) $balance->balance < $days) {
            return response()->json(['message' => 'Insufficient leave balance.'], 422);
        }

        $leave = Leave::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $validated['leave_type_id'],
            'source_attendance_id' => $validated['source_attendance_id'] ?? null,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'days' => $days,
            'reason' => $validated['reason'] ?? null,
        ]);

        $this->approvalService->submitForApproval($leave, 'leave', $branchId);

        return response()->json(['data' => $leave->fresh(['employee', 'leaveType']), 'message' => 'Leave request submitted successfully.'], 201);
    }

    public function approve(Request $request, Leave $leave): JsonResponse
    {
        $comments = $request->input('comments');
        $this->approvalService->approve($leave, $request->user(), $comments);
        $leave->refresh();

        return response()->json(['data' => $leave, 'message' => 'Leave approved successfully.']);
    }

    public function reject(Request $request, Leave $leave): JsonResponse
    {
        $comments = $request->input('comments');
        $this->approvalService->reject($leave, $request->user(), $comments);
        $leave->refresh();

        return response()->json(['data' => $leave, 'message' => 'Leave rejected successfully.']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Leave::with(['employee', 'leaveType']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            'message' => 'Leaves retrieved successfully.',
        ]);
    }

    public function show(Leave $leave): JsonResponse
    {
        $leave->load(['employee', 'leaveType', 'approvalActions.approver']);

        return response()->json(['data' => $leave, 'message' => 'Leave retrieved successfully.']);
    }

    public function cancel(Request $request, Leave $leave): JsonResponse
    {
        $user = $request->user();
        $employee = Employee::withoutGlobalScopes()->where('user_id', $user->id)->first();

        if (!$employee || $leave->employee_id !== $employee->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($leave->status !== 'pending') {
            return response()->json(['message' => 'Only pending leaves can be cancelled.'], 422);
        }

        $leave->update(['status' => 'cancelled']);

        return response()->json(['data' => $leave->fresh(), 'message' => 'Leave cancelled successfully.']);
    }
}
