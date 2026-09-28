<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Within a tenant, confines branch-owned records to the branches the
 * signed-in user may access. Company-wide users (tenant/platform admins)
 * and unassigned users are not restricted; tenant isolation itself is
 * TenantScope's job, not this one's.
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        $branchIds = $user->accessibleBranchIds();

        if ($branchIds !== null) {
            $builder->whereIn($model->qualifyColumn('branch_id'), $branchIds);
        }
    }
}
