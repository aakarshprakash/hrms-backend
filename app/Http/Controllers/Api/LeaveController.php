<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Scopes\BranchScope;
use App\Services\ApprovalWorkflowService;
use App\Services\Leave\LeaveRequestService;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveController extends Controller
{
    public function __construct(
        private ApprovalWorkflowService $approvalService,
        private LeaveRequestService $leaves,
    ) {
    }

    /** Live preview for the apply form: days, balance after, and any rule it breaks. */
    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'half_day_session' => 'nullable|in:first_half,second_half',
            'employee_id' => 'nullable|integer',
        ]);

        [$employee, $onBehalf] = $this->applicant($request);
        $type = LeaveType::withoutGlobalScope(BranchScope::class)->findOrFail($validated['leave_type_id']);

        return response()->json(['data' => $this->leaves->quote(
            $employee, $type, $validated['start_date'], $validated['end_date'], $validated['half_day_session'] ?? null,
            ['on_behalf' => $onBehalf],
        )]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'half_day_session' => 'nullable|in:first_half,second_half',
            'reason' => 'nullable|string|max:1000',
            'source_attendance_id' => 'nullable|integer|exists:attendance_daily,id',
            'employee_id' => 'nullable|integer',
            'approve_now' => 'nullable|boolean',
            'comments' => 'nullable|string|max:1000',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $user = $request->user();
        [$employee, $onBehalf] = $this->applicant($request);
        $type = LeaveType::withoutGlobalScope(BranchScope::class)->findOrFail($validated['leave_type_id']);

        if (! empty($validated['source_attendance_id'])) {
            $sourceAttendance = Attendance::find($validated['source_attendance_id']);

            if (! $sourceAttendance || $sourceAttendance->employee_id !== $employee->id) {
                return response()->json(['message' => 'This attendance record does not belong to you.'], 403);
            }
            if ($sourceAttendance->status !== 'absent') {
                return response()->json(['message' => 'Only a day marked Absent can be converted to leave.'], 422);
            }
            if (Leave::where('source_attendance_id', $sourceAttendance->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                return response()->json(['message' => 'A leave request already exists for this day.'], 422);
            }

            // "Convert this one specific day": whatever dates the client sent,
            // the request covers exactly the source day.
            $validated['start_date'] = $validated['end_date'] = $sourceAttendance->date->toDateString();
        }

        $approveNow = $onBehalf && $request->boolean('approve_now') && $user->can('leaves.approve');

        $leave = $this->leaves->submit($employee, $type, $validated, $user, $request->file('attachment'),
            $onBehalf, $approveNow, $validated['comments'] ?? null);

        return response()->json([
            'data' => $leave,
            'message' => match (true) {
                $leave->status === 'approved' && $approveNow => 'Leave recorded and approved.',
                $leave->status === 'approved' => 'Leave approved.',
                default => 'Leave request submitted for approval.',
            },
        ], 201);
    }

    public function approve(Request $request, Leave $leave): JsonResponse
    {
        $request->validate(['comments' => 'nullable|string|max:1000']);

        $this->approvalService->authorizeApprover($leave, $request->user());
        $this->leaves->assertApprovable($leave);
        $this->approvalService->approve($leave, $request->user(), $request->input('comments'));

        return response()->json([
            'data' => $leave->fresh(['employee', 'leaveType']),
            'message' => $leave->fresh()->status === 'approved' ? 'Leave approved.' : 'Approved — waiting for the next approver.',
        ]);
    }

    public function reject(Request $request, Leave $leave): JsonResponse
    {
        $request->validate(['comments' => 'nullable|string|max:1000']);

        $this->approvalService->authorizeApprover($leave, $request->user());
        $this->approvalService->reject($leave, $request->user(), $request->input('comments'));

        return response()->json(['data' => $leave->fresh(['employee', 'leaveType']), 'message' => 'Leave rejected.']);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Leave::with([
            'employee:id,first_name,last_name,employee_code,branch_id,department_id,designation_id',
            'employee.designation:id,title',
            'leaveType:id,name,code,color,paid',
        ])->visibleTo($user);

        // "mine" = only my own requests, even for approvers.
        if ($request->boolean('mine')) {
            $query->where('employee_id', $user->employee_id ?? 0);
        }

        if ($branchId = $this->requestedBranchId()) {
            $query->whereHas('employee', fn ($q) => $q->where('branch_id', $branchId));
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) explode(',', (string) $request->input('status')));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('leave_type_id')) {
            $query->where('leave_type_id', $request->integer('leave_type_id'));
        }

        if ($request->filled('from')) {
            $query->where('end_date', '>=', $request->date('from')->toDateString());
        }
        if ($request->filled('to')) {
            $query->where('start_date', '<=', $request->date('to')->toDateString());
        }

        if ($search = trim((string) $request->input('search'))) {
            $query->whereHas('employee', fn ($q) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('employee_code', 'like', "%{$search}%")));
        }

        $paginator = $query->orderByDesc('start_date')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

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

    public function show(Request $request, Leave $leave): JsonResponse
    {
        $this->authorizeEmployeeVisible($leave->employee_id);
        $leave->load(['employee.designation:id,title', 'employee.department:id,name', 'leaveType', 'recorder:id,name', 'canceller:id,name']);

        $user = $request->user();
        $own = $leave->employee_id === $user->employee_id;
        $approver = $user->can('leaves.approve') || $user->can('leaves.manage');
        $started = $leave->start_date->toDateString() <= $this->leaves->today($leave->employee);

        return response()->json([
            'data' => $leave,
            'timeline' => $this->approvalService->timeline($leave),
            'can_approve' => $user->can('leaves.approve') && $this->approvalService->canAct($leave, $user),
            // Approvers reject a pending request; withdrawing someone else's is for leave managers.
            'can_cancel' => match ($leave->status) {
                'pending' => $own || $user->can('leaves.manage'),
                'approved' => ($approver && ! $own) || $user->isTenantAdmin() || ($own && ! $started),
                default => false,
            },
            'message' => 'Leave retrieved successfully.',
        ]);
    }

    public function cancel(Request $request, Leave $leave): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        $user = $request->user();
        $own = $leave->employee_id === $user->employee_id;

        if (! $own) {
            // Someone else's pending request is rejected by approvers; only
            // leave managers withdraw it. Approved leave can be revoked by either.
            abort_unless($leave->status === 'pending' ? $user->can('leaves.manage')
                : ($user->can('leaves.approve') || $user->can('leaves.manage')), 403, 'Unauthorized.');
            $this->authorizeEmployeeVisible($leave->employee_id);
        }

        // Someone cancelling their own leave is the employee here, even if
        // they're HR -- except a tenant admin, who has no one above.
        $asApprover = ! $own || $user->isTenantAdmin();

        $leave = $this->leaves->cancel($leave, $user, $asApprover, $request->input('reason'));

        return response()->json(['data' => $leave, 'message' => 'Leave cancelled.']);
    }

    public function attachment(Leave $leave)
    {
        $this->authorizeEmployeeVisible($leave->employee_id);
        $path = $leave->getRawOriginal('attachment_path');

        abort_unless($path && Storage::disk('local')->exists($path), 404, 'No attachment on this leave.');

        return Storage::disk('local')->download($path, "leave-{$leave->id}." . pathinfo($path, PATHINFO_EXTENSION));
    }

    /** Who is away when: approved and pending leave plus holidays, for a team/branch calendar. */
    public function calendar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'branch_id' => 'nullable|integer',
            'department_id' => 'nullable|integer',
        ]);

        $from = CarbonImmutable::parse($validated['from']);
        $to = CarbonImmutable::parse($validated['to']);
        abort_if($from->diffInDays($to) > 62, 422, 'Pick a range of at most two months.');

        $branchId = $this->requestedBranchId();
        $user = $request->user();

        $leaves = Leave::with(['employee:id,first_name,last_name,employee_code,branch_id,department_id', 'leaveType:id,name,code,color'])
            ->visibleTo($user)
            ->whereIn('status', ['approved', 'pending'])
            ->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString())
            ->when($branchId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('branch_id', $branchId)))
            ->when($validated['department_id'] ?? null, fn ($q, $d) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $d)))
            ->orderBy('start_date')
            ->get(['id', 'employee_id', 'leave_type_id', 'start_date', 'end_date', 'days', 'half_day_session', 'status']);

        // Holidays are branch-scoped already: the user's own branches.
        $holidays = Holiday::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('date')
            ->get(['id', 'branch_id', 'name', 'date']);

        return response()->json(['data' => ['leaves' => $leaves, 'holidays' => $holidays]]);
    }

    /** Leave register (CSV): every leave in a period, for records and audits. */
    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'status' => 'nullable|in:pending,approved,rejected,cancelled',
        ]);

        $branchId = $this->requestedBranchId();

        $rows = Leave::with(['employee:id,first_name,last_name,employee_code,branch_id,department_id', 'employee.branch:id,name',
            'employee.department:id,name', 'leaveType:id,name,code,paid'])
            ->visibleTo($request->user())
            ->where('start_date', '<=', $validated['to'])
            ->where('end_date', '>=', $validated['from'])
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($branchId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('branch_id', $branchId)))
            ->orderBy('start_date')
            ->get();

        $filename = "leave-register-{$validated['from']}-to-{$validated['to']}.csv";

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            Csv::put($out, ['Employee Code', 'Name', 'Branch', 'Department', 'Leave Type', 'Paid', 'From', 'To', 'Days', 'Half Day', 'Status', 'Applied On', 'Reason']);
            foreach ($rows as $l) {
                Csv::put($out, [
                    $l->employee?->employee_code, trim(($l->employee?->first_name ?? '') . ' ' . ($l->employee?->last_name ?? '')),
                    $l->employee?->branch?->name, $l->employee?->department?->name,
                    $l->leaveType?->name, $l->leaveType?->paid ? 'Yes' : 'No',
                    $l->start_date->toDateString(), $l->end_date->toDateString(), (float) $l->days,
                    $l->half_day_session ? str_replace('_', ' ', $l->half_day_session) : '',
                    ucfirst($l->status), $l->created_at?->toDateString(), $l->reason,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Whose leave this is: the signed-in employee, or -- for HR / approvers
     * -- an employee in their scope ("apply on behalf").
     *
     * @return array{0: Employee, 1: bool}
     */
    private function applicant(Request $request): array
    {
        $user = $request->user();

        if ($request->filled('employee_id') && (int) $request->input('employee_id') !== (int) $user->employee_id) {
            abort_unless($user->can('leaves.manage') || $user->can('leaves.approve'), 403, 'You can only apply for your own leave.');
            $employee = Employee::query()->visibleTo($user)->find($request->integer('employee_id'));
            abort_unless($employee, 404, 'Employee not found.');

            return [$employee, true];
        }

        $employee = $this->actorEmployee();
        abort_unless($employee, 422, 'No employee profile found for this user.');

        return [$employee, false];
    }
}
