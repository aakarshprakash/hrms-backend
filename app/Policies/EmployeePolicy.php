<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Employee access = permission (what) x data scope (whose).
 *
 * Tenant admins pass every check via Gate::before; tenant isolation is
 * guaranteed underneath by the tenant scope, so a policy only ever sees
 * employees of the acting organisation.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        // Everyone may list; the listing itself is narrowed to their data scope.
        return true;
    }

    public function view(User $user, Employee $employee): bool
    {
        if ($user->employee_id === $employee->id) {
            return true;
        }

        return $user->can('employees.view') && $this->inScope($user, $employee);
    }

    public function create(User $user): bool
    {
        return $user->can('employees.manage');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->can('employees.manage') && $this->inScope($user, $employee);
    }

    /** Own avatar is always editable; anyone else's needs employees.manage. */
    public function updateAvatar(User $user, Employee $employee): bool
    {
        return $user->employee_id === $employee->id || $this->update($user, $employee);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->employee_id !== $employee->id
            && $user->can('employees.manage')
            && $this->inScope($user, $employee);
    }

    /**
     * Bank, PAN, Aadhaar and similar. Employees always see their own;
     * otherwise it takes the dedicated employees.sensitive permission.
     */
    public function viewSensitive(User $user, Employee $employee): bool
    {
        if ($user->employee_id === $employee->id) {
            return true;
        }

        return $user->can('employees.sensitive') && $this->inScope($user, $employee);
    }

    /**
     * Salary is sensitive: payroll viewers can see it within their scope,
     * employees can always see their own -- a manager or coworker viewing
     * someone else's profile cannot.
     */
    public function viewSalary(User $user, Employee $employee): bool
    {
        if ($user->employee_id === $employee->id) {
            return true;
        }

        return ($user->can('payroll.view') || $user->can('payroll.manage')) && $this->inScope($user, $employee);
    }

    public function manageSalary(User $user, Employee $employee): bool
    {
        return $user->can('payroll.manage')
            && $user->employee_id !== $employee->id
            && $this->inScope($user, $employee);
    }

    private function inScope(User $user, Employee $employee): bool
    {
        return $user->canAccessBranch($employee->branch_id) && $employee->isVisibleTo($user);
    }
}
