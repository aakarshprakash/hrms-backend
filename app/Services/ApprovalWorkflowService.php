<?php

namespace App\Services;

use App\Models\ApprovalAction;
use App\Models\ApprovalFlow;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ApprovalWorkflowService
{
    public function submitForApproval(Model $requestable, string $module, int $branchId): void
    {
        $flow = $this->getOrCreateFlow($branchId, $module);
        $steps = $flow->steps_json ?? [];

        if (empty($steps)) {
            $requestable->status = 'approved';
            $requestable->save();
            if (method_exists($requestable, 'onApproved')) {
                $requestable->onApproved();
            }
            return;
        }

        foreach ($steps as $step) {
            ApprovalAction::create([
                'flow_id' => $flow->id,
                'requestable_type' => get_class($requestable),
                'requestable_id' => $requestable->id,
                'step_number' => $step['step'],
                'status' => 'pending',
            ]);
        }

        $requestable->status = 'pending';
        $requestable->save();
    }

    public function approve(Model $requestable, User $approver, ?string $comments = null): void
    {
        $action = ApprovalAction::where('requestable_type', get_class($requestable))
            ->where('requestable_id', $requestable->id)
            ->where('status', 'pending')
            ->orderBy('step_number')
            ->first();

        if (!$action) {
            return;
        }

        $action->status = 'approved';
        $action->approver_id = $approver->id;
        $action->acted_at = Carbon::now();
        $action->comments = $comments;
        $action->save();

        $pendingCount = ApprovalAction::where('requestable_type', get_class($requestable))
            ->where('requestable_id', $requestable->id)
            ->where('status', 'pending')
            ->count();

        if ($pendingCount === 0) {
            $this->finalizeApproval($requestable);
        }
    }

    public function reject(Model $requestable, User $approver, ?string $comments = null): void
    {
        $action = ApprovalAction::where('requestable_type', get_class($requestable))
            ->where('requestable_id', $requestable->id)
            ->where('status', 'pending')
            ->orderBy('step_number')
            ->first();

        if (!$action) {
            return;
        }

        $action->status = 'rejected';
        $action->approver_id = $approver->id;
        $action->acted_at = Carbon::now();
        $action->comments = $comments;
        $action->save();

        $this->finalizeRejection($requestable);
    }

    public function getNextApprover(Model $requestable): ?User
    {
        $action = ApprovalAction::where('requestable_type', get_class($requestable))
            ->where('requestable_id', $requestable->id)
            ->where('status', 'pending')
            ->orderBy('step_number')
            ->first();

        if (!$action) {
            return null;
        }

        $flow = $action->flow;
        $steps = $flow->steps_json ?? [];
        $stepConfig = collect($steps)->firstWhere('step', $action->step_number);

        if (!$stepConfig) {
            return null;
        }

        $role = $stepConfig['approver_role'];
        $branchId = $flow->branch_id;

        return User::where('branch_id', $branchId)
            ->whereHas('roles', fn ($q) => $q->where('name', $role))
            ->first();
    }

    public function escalateIfOverdue(int $hours = 48): void
    {
        $overdueActions = ApprovalAction::where('status', 'pending')
            ->where('created_at', '<', Carbon::now()->subHours($hours))
            ->get();

        foreach ($overdueActions as $action) {
            Log::warning('Approval action overdue', ['action_id' => $action->id]);
        }
    }

    private function getOrCreateFlow(int $branchId, string $module): ApprovalFlow
    {
        return ApprovalFlow::firstOrCreate(
            ['branch_id' => $branchId, 'module' => $module],
            ['steps_json' => [['step' => 1, 'approver_role' => 'hr']]]
        );
    }

    private function finalizeApproval(Model $requestable): void
    {
        $requestable->status = 'approved';
        $requestable->save();
        if (method_exists($requestable, 'onApproved')) {
            $requestable->onApproved();
        }
    }

    private function finalizeRejection(Model $requestable): void
    {
        $requestable->status = 'rejected';
        $requestable->save();
        if (method_exists($requestable, 'onRejected')) {
            $requestable->onRejected();
        }
    }
}
