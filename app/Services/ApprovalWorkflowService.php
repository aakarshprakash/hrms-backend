<?php

namespace App\Services;

use App\Models\ApprovalAction;
use App\Models\ApprovalFlow;
use App\Models\Employee;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\Notifications\NotificationMessages;
use App\Services\Notifications\Notifier;
use App\Support\Access\Roles;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Multi-step approvals for leave, overtime and regularization requests.
 *
 * A branch configures, per module, an ordered list of steps. Each step names
 * who must act:
 *   - "manager": someone above the employee in their reporting line. HR and
 *     other branch/company-wide approvers can act on it on the manager's
 *     behalf (so an HR-run branch never stalls on an absent manager);
 *   - a role name ("hr", "branch_admin", a custom role): a holder of that
 *     role within scope; branch and tenant admins may always step in;
 *   - "any": anyone with approval rights over the employee (the behaviour
 *     before steps were enforced).
 *
 * The approver of every step is captured on the request when it is
 * submitted, so editing a flow later never re-routes requests in flight. A
 * manager step for someone without a manager is skipped (and a flow of only
 * that step goes to HR). Nobody approves their own request or more than one
 * step of the same request -- except a tenant admin, who has no one above.
 */
class ApprovalWorkflowService
{
    public const MANAGER = 'manager';

    public const ANY = 'any';

    /** Roles that may act on any step for requests within their scope. */
    private const OVERRIDE_ROLES = [Roles::TENANT_ADMIN, Roles::BRANCH_ADMIN];

    /** Default for a branch that has never configured a flow. */
    public const DEFAULT_STEPS = [['step' => 1, 'approver_type' => self::MANAGER]];

    public function submitForApproval(Model $requestable, string $module, int $branchId): void
    {
        $flow = $this->getOrCreateFlow($branchId, $module);
        $steps = $this->resolveSteps($flow->steps_json ?? [], $requestable);

        if (empty($steps)) {
            $requestable->status = 'approved';
            $requestable->save();
            if (method_exists($requestable, 'onApproved')) {
                $requestable->onApproved();
            }
            $this->notifyDecision($requestable, true, null);

            return;
        }

        foreach ($steps as $i => $type) {
            ApprovalAction::create([
                'flow_id' => $flow->id,
                'requestable_type' => get_class($requestable),
                'requestable_id' => $requestable->id,
                'step_number' => $i + 1,
                'approver_type' => $type,
                'status' => 'pending',
            ]);
        }

        $requestable->status = 'pending';
        $requestable->save();

        $this->notifyApprovers($requestable);
    }

    /**
     * Approve a request in one go, by someone entitled to (e.g. HR recording
     * leave on an employee's behalf). Leaves the same trail as a normal
     * approval.
     */
    public function approveDirectly(Model $requestable, string $module, int $branchId, User $approver, ?string $comments = null): void
    {
        $flow = $this->getOrCreateFlow($branchId, $module);

        ApprovalAction::create([
            'flow_id' => $flow->id,
            'requestable_type' => get_class($requestable),
            'requestable_id' => $requestable->id,
            'step_number' => 1,
            'approver_type' => self::ANY,
            'approver_id' => $approver->id,
            'status' => 'approved',
            'acted_at' => Carbon::now(),
            'comments' => $comments,
        ]);

        $this->finalizeApproval($requestable);
        $this->notifyDecision($requestable, true, $approver, $comments);
    }

    /**
     * Who may act on a request: it must still be pending, the requester must
     * be within the approver's data scope, and the approver must be who the
     * current step is waiting for. Aborts with the reason otherwise.
     */
    public function authorizeApprover(Model $requestable, User $approver): void
    {
        abort_unless($requestable->status === 'pending', 422, 'This request has already been processed.');

        $employeeId = $requestable->employee_id ?? null;
        abort_unless($employeeId, 422, 'This request is not linked to an employee.');

        $visible = Employee::query()->visibleTo($approver)->whereKey($employeeId)->exists();
        abort_unless($visible, 404, 'Request not found.');

        abort_if($approver->employee_id === $employeeId && ! $approver->isTenantAdmin(), 403,
            'You cannot approve or reject your own request.');

        $reason = $this->stepRefusal($requestable, $approver);
        abort_if($reason !== null, 403, $reason ?? '');
    }

    /** Non-aborting form of authorizeApprover(), for building inboxes. */
    public function canAct(Model $requestable, User $user, bool $visibilityChecked = false): bool
    {
        if ($requestable->status !== 'pending' || ! ($requestable->employee_id ?? null)) {
            return false;
        }
        if ($user->employee_id === $requestable->employee_id && ! $user->isTenantAdmin()) {
            return false;
        }
        if (! $visibilityChecked && ! $user->hasCompanyWideAccess()
            && ! Employee::query()->visibleTo($user)->whereKey($requestable->employee_id)->exists()) {
            return false;
        }

        return $this->stepRefusal($requestable, $user) === null;
    }

    /**
     * Requests of one model class that are waiting for this user's decision.
     *
     * @param  class-string<Model>  $class
     */
    public function pendingFor(User $user, string $class, array $with = []): Collection
    {
        return $class::query()
            ->with(array_merge(['employee.department', 'employee.designation', 'employee.branch', 'approvalActions'], $with))
            ->where('status', 'pending')
            ->visibleTo($user)
            ->latest()
            ->limit(500)
            ->get()
            ->filter(fn (Model $r) => $this->canAct($r, $user, true))
            ->values();
    }

    public function approve(Model $requestable, User $approver, ?string $comments = null): void
    {
        $action = $this->currentAction($requestable);

        if (! $action) {
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
            $this->notifyDecision($requestable, true, $approver, $comments);
        } else {
            $this->notifyApprovers($requestable);
        }
    }

    public function reject(Model $requestable, User $approver, ?string $comments = null): void
    {
        $action = $this->currentAction($requestable);

        if (! $action) {
            return;
        }

        $action->status = 'rejected';
        $action->approver_id = $approver->id;
        $action->acted_at = Carbon::now();
        $action->comments = $comments;
        $action->save();

        $this->finalizeRejection($requestable);
        $this->notifyDecision($requestable, false, $approver, $comments);
    }

    /**
     * The approval trail for display: every step, who it waits for / who
     * acted, when, and their comments.
     */
    public function timeline(Model $requestable): array
    {
        return ApprovalAction::with('approver:id,name')
            ->where('requestable_type', get_class($requestable))
            ->where('requestable_id', $requestable->id)
            ->orderBy('step_number')
            ->get()
            ->map(fn (ApprovalAction $a) => [
                'step' => $a->step_number,
                'approver_type' => $this->typeFor($a, $requestable),
                'waiting_for' => $this->describe($this->typeFor($a, $requestable)),
                'status' => $a->status,
                'approver' => $a->approver?->name,
                'acted_at' => $a->acted_at?->toIso8601String(),
                'comments' => $a->comments,
            ])
            ->all();
    }

    public function getNextApprover(Model $requestable): ?User
    {
        $action = $this->currentAction($requestable);

        if (! $action) {
            return null;
        }

        $type = $this->typeFor($action, $requestable);

        if ($type === self::MANAGER) {
            return $this->managerUserFor($requestable);
        }

        $branchId = $action->flow?->branch_id;
        $role = $type === self::ANY ? Roles::HR : $type;

        return User::where('branch_id', $branchId)
            ->where('is_active', true)
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

    /** Human label for an approver type ("Reporting manager", "HR", ...). */
    public function describe(?string $type): string
    {
        return match ($type) {
            self::MANAGER => 'Reporting manager',
            self::ANY, null => 'Any approver',
            default => Roles::SYSTEM[$type][0]
                ?? (\App\Models\Role::where('name', $type)->value('display_name') ?: ucwords(str_replace('_', ' ', $type))),
        };
    }

    /**
     * Normalised approver types for one request, in order.
     *
     * @return list<string>
     */
    public function resolveSteps(array $steps, Model $requestable): array
    {
        $types = [];

        foreach (collect($steps)->sortBy('step') as $step) {
            $type = $step['approver_type'] ?? $step['approver_role'] ?? null;
            if (! $type) {
                continue;
            }
            if ($type === Roles::SUPER_ADMIN) {
                $type = Roles::TENANT_ADMIN; // pre-SaaS flows
            }
            if ($type === self::MANAGER && ! $this->managerUserFor($requestable)) {
                continue;
            }
            if (end($types) === $type) {
                continue;
            }
            $types[] = $type;
        }

        // A flow that only asked the manager, for someone without one, goes to HR.
        if (empty($types) && ! empty($steps)) {
            $types = [Roles::HR];
        }

        return $types;
    }

    /** The user account of the employee's reporting manager, if any. */
    public function managerUserFor(Model $requestable): ?User
    {
        $managerId = Employee::withoutGlobalScope(BranchScope::class)
            ->whereKey($requestable->employee_id ?? 0)->value('reporting_manager_id');

        if (! $managerId) {
            return null;
        }

        return User::where('is_active', true)
            ->where(fn ($q) => $q->where('employee_id', $managerId)
                ->orWhereIn('id', Employee::withoutGlobalScope(BranchScope::class)->whereKey($managerId)->whereNotNull('user_id')->select('user_id')))
            ->first();
    }

    /** Why this user can't act on the current step, or null if they can. */
    private function stepRefusal(Model $requestable, User $user): ?string
    {
        if ($user->isTenantAdmin()) {
            return null;
        }

        $actions = $requestable->relationLoaded('approvalActions')
            ? $requestable->approvalActions->sortBy('step_number')->values()
            : ApprovalAction::where('requestable_type', get_class($requestable))
                ->where('requestable_id', $requestable->id)
                ->orderBy('step_number')
                ->get();

        $current = $actions->firstWhere('status', 'pending');
        if (! $current) {
            return null; // legacy request without steps: scope and permission already checked
        }

        if ($actions->where('status', 'approved')->contains('approver_id', $user->id)) {
            return 'You have already approved an earlier step of this request; the next step needs someone else.';
        }

        $type = $this->typeFor($current, $requestable);

        if ($type === self::ANY || $user->hasAnyRole(self::OVERRIDE_ROLES)) {
            return null;
        }

        if ($type === self::MANAGER) {
            $broad = in_array($user->dataScope(), [Roles::SCOPE_BRANCH, Roles::SCOPE_COMPANY], true);

            return $this->inReportingLine($user, $requestable) || $broad ? null : "This step is waiting for the employee's reporting manager.";
        }

        return $user->hasRole($type) ? null : 'This step is waiting for ' . $this->describe($type) . '.';
    }

    /**
     * Whether the current step names this user -- the manager above the
     * employee, or a holder of the step's role -- as opposed to someone who
     * may merely step in (HR for a manager, an admin for anyone). Inboxes
     * lead with these.
     */
    public function isDesignated(Model $requestable, User $user): bool
    {
        $current = $requestable->relationLoaded('approvalActions')
            ? $requestable->approvalActions->where('status', 'pending')->sortBy('step_number')->first()
            : $this->currentAction($requestable);

        if (! $current) {
            return true;
        }

        $type = $this->typeFor($current, $requestable);

        return match (true) {
            $type === self::ANY => true,
            $type === self::MANAGER => $this->inReportingLine($user, $requestable),
            default => $user->hasRole($type),
        };
    }

    private function inReportingLine(User $user, Model $requestable): bool
    {
        return $user->employee_id
            && $user->employee_id !== $requestable->employee_id
            && in_array($requestable->employee_id, $user->teamEmployeeIds(), true);
    }

    /**
     * The people the current step is waiting for: the reporting manager, or
     * holders of the step's role who can see the employee's branch ("any":
     * the branch's HR and admins). At most ten.
     *
     * @return Collection<int, User>
     */
    public function designatedApprovers(Model $requestable): Collection
    {
        $current = $this->currentAction($requestable);
        $employee = Employee::withoutGlobalScope(BranchScope::class)->find($requestable->employee_id ?? 0);

        if (! $current || ! $employee) {
            return collect();
        }

        $type = $this->typeFor($current, $requestable);

        if ($type === self::MANAGER) {
            return collect([$this->managerUserFor($requestable)])->filter()->values();
        }

        $roles = $type === self::ANY ? [Roles::HR, Roles::BRANCH_ADMIN] : [$type];

        return User::query()
            ->where('company_id', $employee->company_id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $roles))
            ->get()
            ->filter(fn (User $u) => $u->employee_id !== $employee->id && $u->canAccessBranch($employee->branch_id))
            ->take(10)
            ->values();
    }

    private function notifyApprovers(Model $requestable): void
    {
        try {
            $messages = app(NotificationMessages::class);
            foreach ($this->designatedApprovers($requestable) as $approver) {
                app(Notifier::class)->send($approver, $messages->approvalNeeded($requestable, $approver));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Tell the employee the outcome -- unless they decided it themselves. */
    private function notifyDecision(Model $requestable, bool $approved, ?User $by, ?string $comments = null): void
    {
        try {
            $user = User::query()->where('employee_id', $requestable->employee_id ?? 0)->where('is_active', true)->first();
            if ($user && $user->id !== $by?->id) {
                app(Notifier::class)->send($user, app(NotificationMessages::class)->decided($requestable, $approved, $by, $comments));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function currentAction(Model $requestable): ?ApprovalAction
    {
        return ApprovalAction::where('requestable_type', get_class($requestable))
            ->where('requestable_id', $requestable->id)
            ->where('status', 'pending')
            ->orderBy('step_number')
            ->first();
    }

    /**
     * Approver type of an action. Actions created before steps were enforced
     * carry none: anyone with approval rights over the employee could act
     * on them then, and still can.
     */
    private function typeFor(ApprovalAction $action, Model $requestable): string
    {
        return $action->approver_type ?: self::ANY;
    }

    private function getOrCreateFlow(int $branchId, string $module): ApprovalFlow
    {
        return ApprovalFlow::firstOrCreate(
            ['branch_id' => $branchId, 'module' => $module],
            ['steps_json' => self::DEFAULT_STEPS]
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
