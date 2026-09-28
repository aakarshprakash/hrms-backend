<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

    protected function actor(): User
    {
        return request()->user();
    }

    /** 403 unless the signed-in user may act in $branchId. */
    protected function authorizeBranch(?int $branchId, string $message = 'You do not have access to this branch.'): void
    {
        abort_unless($this->actor()->canAccessBranch($branchId), 403, $message);
    }

    /**
     * Resolve the branch filter for a listing: an explicit branch_id the
     * user may access, or null (= all branches the scopes already allow).
     */
    protected function requestedBranchId(string $key = 'branch_id'): ?int
    {
        $branchId = request()->integer($key) ?: null;

        if ($branchId !== null) {
            $this->authorizeBranch($branchId);
        }

        return $branchId;
    }

    /** The signed-in user's own employee record (tenant-scoped), or null. */
    protected function actorEmployee(): ?Employee
    {
        $user = $this->actor();

        return $user->employee_id
            ? Employee::withoutGlobalScope(BranchScope::class)->find($user->employee_id)
            : Employee::withoutGlobalScope(BranchScope::class)->where('user_id', $user->id)->first();
    }

    /** 404 (not 403) when the employee exists but is outside the user's data scope. */
    protected function authorizeEmployeeVisible(int|Employee $employee): Employee
    {
        $model = $employee instanceof Employee ? $employee : Employee::find($employee);

        abort_if(! $model || ! $model->isVisibleTo($this->actor()), 404, 'Employee not found.');

        return $model;
    }
}
