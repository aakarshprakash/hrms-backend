<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'branch_admin', 'hr', 'manager', 'employee'])
            || $user->can('employees.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        if ($user->is_super_admin || $user->hasRole('super_admin')) {
            return true;
        }

        if ($user->hasAnyRole(['branch_admin', 'hr'])) {
            return $user->branch_id === $employee->branch_id;
        }

        if ($user->hasRole('manager')) {
            // Manager can see their own employee record and direct reports
            if ($user->employee_id === $employee->id) {
                return true;
            }
            return $employee->reporting_manager_id === $user->employee_id;
        }

        if ($user->hasRole('employee')) {
            return $user->employee_id === $employee->id;
        }

        // Custom roles: branch-scoped view permission
        if ($user->can('employees.view')) {
            return $user->branch_id === null || $user->branch_id === $employee->branch_id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'branch_admin', 'hr'])
            || $user->can('employees.manage');
    }

    public function update(User $user, Employee $employee): bool
    {
        if ($user->is_super_admin || $user->hasRole('super_admin')) {
            return true;
        }

        if ($user->hasAnyRole(['branch_admin', 'hr'])) {
            return $user->branch_id === $employee->branch_id;
        }

        if ($user->hasRole('manager')) {
            return $employee->reporting_manager_id === $user->employee_id;
        }

        // Custom roles: branch-scoped manage permission
        if ($user->can('employees.manage')) {
            return $user->branch_id === null || $user->branch_id === $employee->branch_id;
        }

        return false;
    }

    public function delete(User $user, Employee $employee): bool
    {
        if ($user->is_super_admin || $user->hasRole('super_admin')) {
            return true;
        }

        return $user->hasRole('branch_admin') && $user->branch_id === $employee->branch_id;
    }

    /**
     * Salary is sensitive: HR/branch admin can see it for their own branch,
     * and employees can always see their own — but a manager or coworker
     * viewing someone else's profile should not.
     */
    public function viewSalary(User $user, Employee $employee): bool
    {
        if ($user->is_super_admin || $user->hasRole('super_admin')) {
            return true;
        }

        if ($user->hasAnyRole(['branch_admin', 'hr']) || $user->can('payroll.view')) {
            return $user->branch_id === null || $user->branch_id === $employee->branch_id;
        }

        return $user->employee_id === $employee->id;
    }

    public function manageSalary(User $user, Employee $employee): bool
    {
        if ($user->is_super_admin || $user->hasRole('super_admin')) {
            return true;
        }

        if ($user->hasAnyRole(['branch_admin', 'hr']) || $user->can('payroll.manage')) {
            return $user->branch_id === null || $user->branch_id === $employee->branch_id;
        }

        return false;
    }
}
