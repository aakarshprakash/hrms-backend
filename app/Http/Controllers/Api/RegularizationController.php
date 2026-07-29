<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRegularization;
use App\Models\Employee;
use App\Services\ApprovalWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegularizationController extends Controller
{
    public function __construct(private ApprovalWorkflowService $approvalService) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'attendance_id' => 'nullable|exists:attendances,id',
            'reason' => 'required|string',
            'requested_check_in' => 'nullable|date',
            'requested_check_out' => 'nullable|date',
        ]);

        $employee = Employee::withoutGlobalScopes()->where('user_id', $request->user()->id)->first();

        if (!$employee) {
            return response()->json(['message' => 'No employee profile found for this user.'], 422);
        }

        $reg = AttendanceRegularization::create([
            'attendance_id' => $validated['attendance_id'] ?? null,
            'employee_id' => $employee->id,
            'requested_check_in' => $validated['requested_check_in'] ?? null,
            'requested_check_out' => $validated['requested_check_out'] ?? null,
            'reason' => $validated['reason'],
        ]);

        $branchId = $employee->branch_id;
        $this->approvalService->submitForApproval($reg, 'regularization', $branchId);

        return response()->json(['data' => $reg->fresh(), 'message' => 'Regularization request submitted successfully.'], 201);
    }

    public function approve(Request $request, AttendanceRegularization $regularization): JsonResponse
    {
        $comments = $request->input('comments');
        $this->approvalService->approve($regularization, $request->user(), $comments);
        $regularization->refresh();

        return response()->json(['data' => $regularization, 'message' => 'Regularization approved successfully.']);
    }

    public function reject(Request $request, AttendanceRegularization $regularization): JsonResponse
    {
        $comments = $request->input('comments');
        $this->approvalService->reject($regularization, $request->user(), $comments);
        $regularization->refresh();

        return response()->json(['data' => $regularization, 'message' => 'Regularization rejected successfully.']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = AttendanceRegularization::with(['employee', 'attendance']);

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
