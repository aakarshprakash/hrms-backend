<?php

namespace App\Http\Controllers\Api;

use App\Models\Scopes\BranchScope;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\PayrollRunAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollAdjustmentController extends Controller
{
    private function assertCanManageBranch(int $branchId): void
    {
        // payroll.manage itself is enforced on the route.
        $this->authorizeBranch($branchId, 'You are not allowed to manage payroll for this branch.');
    }

    private function assertEditable(PayrollRun $run): void
    {
        abort_unless($run->isEditable(), 422, 'Adjustments can only change before the payroll run is finalized.');
    }

    /** A processed run with changed inputs must be processed again before it can be finalized. */
    private function markStale(PayrollRun $run): void
    {
        if ($run->status === 'processed') {
            $run->forceFill(['status' => 'draft'])->save();
        }
    }

    private function assertComponentInBranch(int $componentId, PayrollRun $run): void
    {
        $branch = \App\Models\SalaryComponent::withoutGlobalScope(BranchScope::class)->whereKey($componentId)->value('branch_id');
        abort_unless($branch === $run->branch_id, 422, "That pay component belongs to a different branch than this payroll run.");
    }

    public function index(PayrollRun $run): JsonResponse
    {
        $this->assertCanManageBranch($run->branch_id);

        $adjustments = PayrollRunAdjustment::where('payroll_run_id', $run->id)
            ->with(['employee', 'component'])
            ->get();

        return response()->json(['data' => $adjustments]);
    }

    public function store(Request $request, PayrollRun $run): JsonResponse
    {
        $this->assertCanManageBranch($run->branch_id);
        $this->assertEditable($run);

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'component_id' => 'required|exists:salary_components,id',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
        ]);

        $employee = Employee::withoutGlobalScope(BranchScope::class)->findOrFail($validated['employee_id']);
        abort_unless($employee->branch_id === $run->branch_id, 422, "Employee does not belong to this payroll run's branch.");
        $this->assertComponentInBranch((int) $validated['component_id'], $run);

        $adjustment = PayrollRunAdjustment::create([
            ...$validated,
            'payroll_run_id' => $run->id,
            'created_by' => $request->user()->id,
        ]);
        $this->markStale($run);

        return response()->json([
            'data' => $adjustment->load(['employee', 'component']),
            'message' => 'Adjustment added.',
        ], 201);
    }

    /**
     * Apply the same one-off allowance/deduction to several employees at
     * once (e.g. a festival bonus for the whole branch) instead of adding
     * them one at a time.
     */
    public function bulkStore(Request $request, PayrollRun $run): JsonResponse
    {
        $this->assertCanManageBranch($run->branch_id);
        $this->assertEditable($run);

        $validated = $request->validate([
            'component_id' => 'required|exists:salary_components,id',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
            'apply_to_all' => 'sometimes|boolean',
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'integer|exists:employees,id',
        ]);

        if ($validated['apply_to_all'] ?? false) {
            $employeeIds = Employee::withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $run->branch_id)
                ->where('status', 'active')
                ->pluck('id');
        } else {
            $employeeIds = collect($validated['employee_ids'] ?? []);
            $outOfBranch = Employee::withoutGlobalScope(BranchScope::class)
                ->whereIn('id', $employeeIds)
                ->where('branch_id', '!=', $run->branch_id)
                ->exists();
            abort_if($outOfBranch, 422, "One or more selected employees don't belong to this payroll run's branch.");
        }

        abort_if($employeeIds->isEmpty(), 422, 'No employees selected.');
        $this->assertComponentInBranch((int) $validated['component_id'], $run);

        $createdCount = DB::transaction(function () use ($employeeIds, $validated, $run, $request) {
            foreach ($employeeIds as $employeeId) {
                PayrollRunAdjustment::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employeeId,
                    'component_id' => $validated['component_id'],
                    'amount' => $validated['amount'],
                    'note' => $validated['note'] ?? null,
                    'created_by' => $request->user()->id,
                ]);
            }
            return $employeeIds->count();
        });
        $this->markStale($run);

        return response()->json([
            'message' => "Adjustment added for {$createdCount} employee" . ($createdCount === 1 ? '' : 's') . '.',
        ], 201);
    }

    public function update(Request $request, PayrollRunAdjustment $adjustment): JsonResponse
    {
        $run = $adjustment->payrollRun;
        $this->assertCanManageBranch($run->branch_id);
        $this->assertEditable($run);

        $validated = $request->validate([
            'component_id' => 'required|exists:salary_components,id',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
        ]);

        $this->assertComponentInBranch((int) $validated['component_id'], $run);
        $adjustment->update($validated);
        $this->markStale($run);

        return response()->json([
            'data' => $adjustment->fresh(['employee', 'component']),
            'message' => 'Adjustment updated.',
        ]);
    }

    public function destroy(PayrollRunAdjustment $adjustment): JsonResponse
    {
        $run = $adjustment->payrollRun;
        $this->assertCanManageBranch($run->branch_id);
        $this->assertEditable($run);

        $adjustment->delete();
        $this->markStale($run);

        return response()->json(['message' => 'Adjustment removed.']);
    }
}
