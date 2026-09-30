<?php

namespace App\Models\Concerns;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * For records that belong to an employee (leave, attendance, payslip, ...):
 * `->visibleTo($user)` limits them to employees the user may see, per their
 * data scope (company / branch / team / self).
 */
trait VisibleThroughEmployee
{
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasCompanyWideAccess()) {
            return $query;
        }

        return $query->whereIn(
            $this->qualifyColumn('employee_id'),
            Employee::query()->visibleTo($user)->select('employees.id')
        );
    }
}
