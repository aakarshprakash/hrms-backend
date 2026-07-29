<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SalaryStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalaryStructureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SalaryStructure::with(['employee', 'component']);

        if ($request->filled('employee_id')) {
            // Scoped lookup: 404s for a branch admin/HR reaching across branches.
            $employee = Employee::findOrFail($request->integer('employee_id'));
            $this->authorize('viewSalary', $employee);
            $query->where('employee_id', $employee->id);
        } else {
            $user = $request->user();
            abort_unless(
                $user->is_super_admin || $user->hasAnyRole(['super_admin', 'branch_admin', 'hr']) || $user->can('payroll.view'),
                403
            );
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'component_id' => 'required|exists:salary_components,id',
            'amount' => 'required|numeric|min:0',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after:effective_from',
        ]);

        $employee = Employee::withoutGlobalScopes()->findOrFail($validated['employee_id']);
        $this->authorize('manageSalary', $employee);

        $structure = SalaryStructure::create($validated);

        return response()->json(['data' => $structure->load(['employee', 'component']), 'message' => 'Salary structure created.'], 201);
    }

    public function update(Request $request, SalaryStructure $structure): JsonResponse
    {
        $employee = Employee::withoutGlobalScopes()->findOrFail($structure->employee_id);
        $this->authorize('manageSalary', $employee);

        $validated = $request->validate([
            'amount' => 'sometimes|numeric|min:0',
            'effective_from' => 'sometimes|date',
            'effective_to' => 'nullable|date',
        ]);

        $structure->update($validated);

        return response()->json(['data' => $structure->fresh(['employee', 'component']), 'message' => 'Salary structure updated.']);
    }

    public function destroy(SalaryStructure $structure): JsonResponse
    {
        $employee = Employee::withoutGlobalScopes()->findOrFail($structure->employee_id);
        $this->authorize('manageSalary', $employee);

        $structure->delete();
        return response()->json(['message' => 'Salary structure deleted.']);
    }
}
