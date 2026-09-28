<?php

namespace App\Http\Controllers\Api;

use App\Models\Scopes\BranchScope;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceRegularization;
use App\Models\Employee;
use App\Services\ApprovalWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

class RegularizationController extends Controller
{
    public function __construct(private ApprovalWorkflowService $approvalService) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'attendance_id' => 'nullable|exists:attendance_daily,id',
            'date' => 'required_without:attendance_id|nullable|date|before_or_equal:today',
            'reason' => 'required|string|max:1000',
            'requested_check_in' => 'required_without:requested_check_out|nullable|string|max:25',
            'requested_check_out' => 'nullable|string|max:25',
        ]);

        $employee = $this->actorEmployee();

        if (!$employee) {
            return response()->json(['message' => 'No employee profile found for this user.'], 422);
        }

        $attendance = ! empty($validated['attendance_id']) ? Attendance::find($validated['attendance_id']) : null;

        // Only your own attendance can be regularized.
        if ($attendance && $attendance->employee_id !== $employee->id) {
            return response()->json(['message' => 'This attendance record does not belong to you.'], 403);
        }

        $date = $attendance?->date->toDateString() ?? Carbon::parse($validated['date'])->toDateString();
        $attendance ??= Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->first();

        if ($attendance?->locked_at) {
            return response()->json(['message' => 'Payroll for this day has been finalized; it can no longer be regularized.'], 422);
        }

        if (AttendanceRegularization::where('employee_id', $employee->id)->whereDate('date', $date)->where('status', 'pending')->exists()) {
            return response()->json(['message' => 'You already have a pending regularization for this day.'], 422);
        }

        // Times are the branch's wall clock. A bare "HH:MM" belongs to the
        // requested day; an out-time earlier than the in-time is the next
        // morning (night shift).
        $tz = $employee->branch?->timezone ?: 'UTC';
        $in = $this->toInstant($validated['requested_check_in'] ?? null, $date, $tz);
        $out = $this->toInstant($validated['requested_check_out'] ?? null, $date, $tz);
        if ($in && $out && $out->lte($in)) {
            $out = $out->addDay();
        }

        $reg = AttendanceRegularization::create([
            'attendance_id' => $attendance?->id,
            'employee_id' => $employee->id,
            'date' => $date,
            'requested_check_in' => $in,
            'requested_check_out' => $out,
            'reason' => $validated['reason'],
        ]);

        $this->approvalService->submitForApproval($reg, 'regularization', $employee->branch_id);

        return response()->json(['data' => $reg->fresh(), 'message' => 'Regularization request submitted successfully.'], 201);
    }

    private function toInstant(?string $value, string $date, string $tz): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }

        try {
            $local = preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)
                ? CarbonImmutable::parse("{$date} {$value}", $tz)
                : CarbonImmutable::parse($value, $tz);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['requested_check_in' => 'Enter times as HH:MM.']);
        }

        return $local->utc();
    }

    public function approve(Request $request, AttendanceRegularization $regularization): JsonResponse
    {
        $this->approvalService->authorizeApprover($regularization, $request->user());
        $comments = $request->input('comments');
        $this->approvalService->approve($regularization, $request->user(), $comments);
        $regularization->refresh();

        return response()->json(['data' => $regularization, 'message' => 'Regularization approved successfully.']);
    }

    public function reject(Request $request, AttendanceRegularization $regularization): JsonResponse
    {
        $this->approvalService->authorizeApprover($regularization, $request->user());
        $comments = $request->input('comments');
        $this->approvalService->reject($regularization, $request->user(), $comments);
        $regularization->refresh();

        return response()->json(['data' => $regularization, 'message' => 'Regularization rejected successfully.']);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = AttendanceRegularization::with(['employee', 'attendance'])->visibleTo($user);

        if ($request->boolean('mine')) {
            $query->where('employee_id', $user->employee_id ?? 0);
        }

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
            'message' => 'Regularizations retrieved successfully.',
        ]);
    }
}
