<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Designation;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\SalaryComponent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuickSetupController extends Controller
{
    /**
     * POST /api/quick-setup
     *
     * Seeds showroom-specific defaults for a branch.
     * Uses firstOrCreate so it is idempotent — safe to run multiple times.
     */
    public function seed(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $validated['branch_id'];

        $counts = DB::transaction(function () use ($branchId) {
            $created = [
                'departments'       => 0,
                'designations'      => 0,
                'leave_types'       => 0,
                'shifts'            => 0,
                'salary_components' => 0,
            ];
            $skipped = [
                'departments'       => 0,
                'designations'      => 0,
                'leave_types'       => 0,
                'shifts'            => 0,
                'salary_components' => 0,
            ];

            // Helper: find-or-create bypassing global BranchScope so the
            // existence check is not filtered by the current user's branch access.
            $upsert = function (string $model, array $search, array $attrs) {
                /** @var \Illuminate\Database\Eloquent\Model $model */
                $existing = $model::withoutGlobalScopes()->where($search)->first();
                if ($existing) {
                    return [$existing, false];
                }
                $instance = new $model(array_merge($search, $attrs));
                $instance->save();
                return [$instance, true];
            };

            // ── Departments ──────────────────────────────────────────────────
            $departmentNames = [
                'Sales',
                'Vehicle Service',
                'Parts & Accessories',
                'Finance & Insurance',
                'Administration',
                'Customer Relations',
            ];

            $deptIdMap = [];

            foreach ($departmentNames as $name) {
                [$dept, $wasCreated] = $upsert(
                    Department::class,
                    ['branch_id' => $branchId, 'name' => $name],
                    []
                );

                $wasCreated ? $created['departments']++ : $skipped['departments']++;
                $deptIdMap[$name] = $dept->id;
            }

            // ── Designations ─────────────────────────────────────────────────
            $designationMap = [
                'Sales'               => ['Sales Executive', 'Senior Sales Executive', 'Sales Manager', 'Sales Head'],
                'Vehicle Service'     => ['Technician', 'Senior Technician', 'Service Advisor', 'Service Manager', 'Workshop Manager'],
                'Parts & Accessories' => ['Parts Executive', 'Parts Manager'],
                'Finance & Insurance' => ['Finance Executive', 'Finance Manager'],
                'Administration'      => ['Receptionist', 'HR Executive', 'Admin Executive', 'Admin Manager'],
                'Customer Relations'  => ['CRM Executive', 'CRM Manager'],
            ];

            foreach ($designationMap as $deptName => $titles) {
                $departmentId = $deptIdMap[$deptName]
                    ?? Department::withoutGlobalScopes()
                        ->where('branch_id', $branchId)
                        ->where('name', $deptName)
                        ->value('id');

                if (! $departmentId) {
                    continue;
                }

                foreach ($titles as $title) {
                    [, $wasCreated] = $upsert(
                        Designation::class,
                        ['branch_id' => $branchId, 'department_id' => $departmentId, 'title' => $title],
                        []
                    );
                    $wasCreated ? $created['designations']++ : $skipped['designations']++;
                }
            }

            // ── Leave Types ───────────────────────────────────────────────────
            $leaveTypes = [
                ['name' => 'Casual Leave',    'days_per_year' => 12, 'paid' => true,  'carry_forward' => false],
                ['name' => 'Sick Leave',       'days_per_year' => 10, 'paid' => true,  'carry_forward' => false],
                ['name' => 'Earned Leave',     'days_per_year' => 15, 'paid' => true,  'carry_forward' => true],
                ['name' => 'Maternity Leave',  'days_per_year' => 84, 'paid' => true,  'carry_forward' => false],
                ['name' => 'Paternity Leave',  'days_per_year' => 15, 'paid' => true,  'carry_forward' => false],
                ['name' => 'Compensatory Off', 'days_per_year' => 0,  'paid' => true,  'carry_forward' => false],
            ];

            foreach ($leaveTypes as $lt) {
                [, $wasCreated] = $upsert(
                    LeaveType::class,
                    ['branch_id' => $branchId, 'name' => $lt['name']],
                    ['days_per_year' => $lt['days_per_year'], 'paid' => $lt['paid'], 'carry_forward' => $lt['carry_forward']]
                );
                $wasCreated ? $created['leave_types']++ : $skipped['leave_types']++;
            }

            // ── Shifts ────────────────────────────────────────────────────────
            $shifts = [
                ['name' => 'General Shift', 'start_time' => '09:00:00', 'end_time' => '18:00:00', 'break_minutes' => 30, 'grace_minutes' => 10],
                ['name' => 'Morning Shift', 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 30, 'grace_minutes' => 10],
                ['name' => 'Service Shift', 'start_time' => '08:30:00', 'end_time' => '17:30:00', 'break_minutes' => 30, 'grace_minutes' => 10],
            ];

            foreach ($shifts as $s) {
                [, $wasCreated] = $upsert(
                    Shift::class,
                    ['branch_id' => $branchId, 'name' => $s['name']],
                    ['start_time' => $s['start_time'], 'end_time' => $s['end_time'], 'break_minutes' => $s['break_minutes'], 'grace_minutes' => $s['grace_minutes']]
                );
                $wasCreated ? $created['shifts']++ : $skipped['shifts']++;
            }

            // ── Salary Components ─────────────────────────────────────────────
            $components = [
                ['name' => 'Basic Salary',         'type' => 'earning',   'calculation_type' => 'fixed'],
                ['name' => 'HRA',                  'type' => 'earning',   'calculation_type' => 'percentage'],
                ['name' => 'Conveyance Allowance', 'type' => 'earning',   'calculation_type' => 'fixed'],
                ['name' => 'Special Allowance',    'type' => 'earning',   'calculation_type' => 'fixed'],
                ['name' => 'Sales Incentive',      'type' => 'earning',   'calculation_type' => 'fixed'],
                ['name' => 'PF Employee',          'type' => 'deduction', 'calculation_type' => 'percentage'],
                ['name' => 'ESI Employee',         'type' => 'deduction', 'calculation_type' => 'percentage'],
                ['name' => 'TDS',                  'type' => 'deduction', 'calculation_type' => 'percentage'],
            ];

            foreach ($components as $c) {
                [, $wasCreated] = $upsert(
                    SalaryComponent::class,
                    ['branch_id' => $branchId, 'name' => $c['name']],
                    ['type' => $c['type'], 'calculation_type' => $c['calculation_type']]
                );
                $wasCreated ? $created['salary_components']++ : $skipped['salary_components']++;
            }

            return compact('created', 'skipped');
        });

        return response()->json([
            'data' => $counts,
        ]);
    }
}
