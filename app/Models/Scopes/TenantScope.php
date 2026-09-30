<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a tenant-owned model to the current company.
 * This is the isolation guarantee: it applies to route-model binding,
 * relations, whereHas subqueries and aggregates alike, so a controller that
 * forgets a filter still cannot read another tenant's rows.
 *
 * Note: withoutGlobalScopes() strips this too. Code that only means to skip
 * the branch filter must use withoutGlobalScope(BranchScope::class) -- a
 * test (TenantIsolationGuardTest) enforces that.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        $column = $model->qualifyColumn('company_id');

        if ($context->hasTenant()) {
            $builder->where($column, $context->id());

            return;
        }

        if ($context->isEnforced()) {
            // Authenticated request without a tenant: fail closed.
            $builder->whereRaw('1 = 0');
        }
    }
}
