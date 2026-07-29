<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\PayrollRunAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollAdjustmentController extends Controller
{
    private function assertCanManageBranch(int $branchId): void
    {
        $user = request()->user();
        if ($user->is_super_admin || $user->hasRole('super_admin')) {
            return;
        }
        abort_unless(
            ($user->hasAnyRole(['branch_admin', 'hr']) || $user->can('payroll.manage'))
                && ($user->branch_id === null || $user->branch_id === $branchId),
            403,
            'You are not allowed to manage payroll for this branch.'
        );
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
        abort_unless($run->status === 'draft', 422, 'Adjustments can only be added while the payroll run is in draft.');

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'component_id' => 'required|exists:salary_components,id',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
        ]);

        $employee = Employee::withoutGlobalScopes()->findOrFail($validated['employee_id']);
        abort_unless($employee->branch_id === $run->branch_id, 422, "Employee does not belong to this payroll run's branch.");

        $adjustment = PayrollRunAdjustment::create([
            ...$validated,
            'payroll_run_id' => $run->id,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => $adjustment->load(['employee', 'component']),
            'message' => 'Adjustment added.',
        ], 201);
    }

    public function destroy(PayrollRunAdjustment $adjustment): JsonResponse
    {
        $run = $adjustment->payrollRun;
        $this->assertCanManageBranch($run->branch_id);
        abort_unless($run->status === 'draft', 422, 'Adjustments can only be removed while the payroll run is in draft.');

        $adjustment->delete();

        return response()->json(['message' => 'Adjustment removed.']);
    }
}
