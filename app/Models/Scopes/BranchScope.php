<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        // Super admins bypass branch filtering
        if ($user->is_super_admin) {
            return;
        }

        // Users with HQ/all-branch access bypass filtering
        if ($user->hasRole('super_admin')) {
            return;
        }

        // Filter by the user's accessible branch(es)
        $branchIds = $user->accessible_branch_ids ?? [];

        if (! empty($branchIds)) {
            $builder->whereIn($model->getTable() . '.branch_id', $branchIds);
        } elseif ($user->branch_id) {
            $builder->where($model->getTable() . '.branch_id', $user->branch_id);
        }
    }
}
