<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Notifications\OvertimeApproved;
use App\Notifications\OvertimeRejected;
use App\Notifications\OvertimeSubmitted;
use App\Services\ApprovalWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class OvertimeRequestController extends Controller
{
    public function __construct(private ApprovalWorkflowService $approvalService) {}

    public function index(Request $request): JsonResponse
    {
        $query = OvertimeRequest::with(['employee', 'approver']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->input('date_to'));
        }

        $paginator = $query->orderByDesc('date')->paginate(20);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'hours' => 'required|numeric|min:0.5|max:12',
            'reason' => 'nullable|string|max:1000',
        ]);

        $employee = Employee::withoutGlobalScopes()->findOrFail($validated['employee_id']);
        $branchId = $employee->branch_id;

        $overtimeRequest = OvertimeRequest::create($validated);

        $this->approvalService->submitForApproval($overtimeRequest, 'overtime', $branchId);

        // Notify HR/manager
        try {
            Notification::route('mail', config('mail.from.address'))
                ->notify(new OvertimeSubmitted($overtimeRequest));
        } catch (\Throwable $e) {
            // Notification failure should not block request
        }

        return response()->json([
            'data' => $overtimeRequest->fresh(['employee', 'approver']),
            'message' => 'Overtime request submitted successfully.',
        ], 201);
    }

    public function show(OvertimeRequest $request): JsonResponse
    {
        return response()->json(['data' => $request->load(['employee', 'approver'])]);
    }

    public function approve(Request $httpRequest, OvertimeRequest $request): JsonResponse
    {
        $comments = $httpRequest->input('comments');
        $this->approvalService->approve($request, $httpRequest->user(), $comments);
        $request->refresh();

        // Notify employee
        try {
            if ($request->employee?->user) {
                $request->employee->user->notify(new OvertimeApproved($request));
            }
        } catch (\Throwable $e) {
            // Notification failure should not block response
        }

        return response()->json(['data' => $request, 'message' => 'Overtime request approved.']);
    }

    public function reject(Request $httpRequest, OvertimeRequest $request): JsonResponse
    {
        $comments = $httpRequest->input('comments');
        $this->approvalService->reject($request, $httpRequest->user(), $comments);
        $request->refresh();

        try {
            if ($request->employee?->user) {
                $request->employee->user->notify(new OvertimeRejected($request));
            }
        } catch (\Throwable $e) {
            // Notification failure should not block response
        }

        return response()->json(['data' => $request, 'message' => 'Overtime request rejected.']);
    }
}
