<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRegularization;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\OvertimeRequest;
use App\Services\ApprovalWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One inbox for everything waiting on the signed-in approver: leave,
 * attendance regularization and overtime requests whose current step is
 * theirs to decide.
 */
class ApprovalController extends Controller
{
    public function __construct(private ApprovalWorkflowService $workflow)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $leave = $this->workflow->pendingFor($user, Leave::class, ['leaveType:id,name,code,color,paid']);
        $this->attachBalances($leave);

        return response()->json([
            'data' => [
                'leave' => $leave->map(fn (Leave $l) => $this->present($l, 'leave'))->values(),
                'regularization' => $this->workflow->pendingFor($user, AttendanceRegularization::class)
                    ->map(fn ($r) => $this->present($r, 'regularization'))->values(),
                'overtime' => $this->workflow->pendingFor($user, OvertimeRequest::class)
                    ->map(fn ($r) => $this->present($r, 'overtime'))->values(),
            ],
        ]);
    }

    /**
     * Counts for badges: requests whose current step names this user
     * (manager in the reporting line, holder of the step's role), plus
     * everything they could act on, stepping in for someone else.
     */
    public function count(Request $request): JsonResponse
    {
        $user = $request->user();
        $zero = ['total' => 0, 'leave' => 0, 'regularization' => 0, 'overtime' => 0, 'all' => 0];

        if (! $user->can('leaves.approve')) {
            return response()->json(['data' => $zero]);
        }

        $counts = [];
        $all = 0;
        foreach (['leave' => Leave::class, 'regularization' => AttendanceRegularization::class, 'overtime' => OvertimeRequest::class] as $key => $class) {
            $pending = $this->workflow->pendingFor($user, $class);
            $counts[$key] = $pending->filter(fn ($r) => $this->workflow->isDesignated($r, $user))->count();
            $all += $pending->count();
        }

        return response()->json(['data' => $counts + ['total' => array_sum($counts), 'all' => $all]]);
    }

    private function present($request, string $module): array
    {
        $employee = $request->employee;
        $current = $request->approvalActions->where('status', 'pending')->sortBy('step_number')->first();

        return array_merge($request->toArray(), [
            'module' => $module,
            'designated' => $this->workflow->isDesignated($request, auth()->user()),
            'employee' => $employee ? [
                'id' => $employee->id,
                'name' => trim("{$employee->first_name} {$employee->last_name}"),
                'employee_code' => $employee->employee_code,
                'designation' => $employee->designation?->title,
                'department' => $employee->department?->name,
                'branch' => $employee->branch?->name,
            ] : null,
            'step' => $current ? [
                'number' => $current->step_number,
                'of' => $request->approvalActions->count(),
                'waiting_for' => $this->workflow->describe($current->approver_type ?: ApprovalWorkflowService::ANY),
            ] : null,
            'approval_actions' => null,
        ]);
    }

    /** Current balance of the requested type, so approvers see what's left. */
    private function attachBalances($leaves): void
    {
        $balances = LeaveBalance::whereIn('employee_id', $leaves->pluck('employee_id'))
            ->whereIn('leave_type_id', $leaves->pluck('leave_type_id'))
            ->get()
            ->keyBy(fn ($b) => "{$b->employee_id}:{$b->leave_type_id}:{$b->year}");

        foreach ($leaves as $leave) {
            $b = $balances->get("{$leave->employee_id}:{$leave->leave_type_id}:{$leave->leave_year}");
            $leave->setAttribute('balance', $b ? (float) $b->balance : null);
        }
    }
}
