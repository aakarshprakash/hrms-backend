<?php

namespace App\Http\Controllers\Api;

use App\Models\Scopes\BranchScope;
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
            $employee = $this->authorizeEmployeeVisible($request->integer('employee_id'));
            $this->authorize('viewSalary', $employee);
            $query->where('employee_id', $employee->id);
        } else {
            $user = $request->user();
            abort_unless($user->can('payroll.view') || $user->can('payroll.manage'), 403);
            $query->visibleTo($user);
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

        $employee = Employee::withoutGlobalScope(BranchScope::class)->findOrFail($validated['employee_id']);
        $this->authorize('manageSalary', $employee);

        $componentBranch = \App\Models\SalaryComponent::withoutGlobalScope(BranchScope::class)
            ->whereKey($validated['component_id'])->value('branch_id');
        abort_unless($componentBranch === $employee->branch_id, 422, 'That pay component belongs to a different branch than the employee.');

        $structure = SalaryStructure::create($validated);

        return response()->json(['data' => $structure->load(['employee', 'component']), 'message' => 'Salary structure created.'], 201);
    }

    public function update(Request $request, SalaryStructure $structure): JsonResponse
    {
        $employee = Employee::withoutGlobalScope(BranchScope::class)->findOrFail($structure->employee_id);
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
        $employee = Employee::withoutGlobalScope(BranchScope::class)->findOrFail($structure->employee_id);
        $this->authorize('manageSalary', $employee);

        $structure->delete();
        return response()->json(['message' => 'Salary structure deleted.']);
    }

    /**
     * POST /employees/{employee}/salary/from-gross -- restructure pay from a
     * monthly gross: Basic x%, HRA y% of basic, conveyance, and a special
     * allowance for the remainder. Current lines end the day before;
     * history is kept.
     */
    public function fromGross(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('manageSalary', $employee);

        $validated = $request->validate([
            'monthly_gross' => ['required', 'numeric', 'min:1000', 'max:10000000'],
            'effective_from' => ['required', 'date'],
            'basic_percent' => ['nullable', 'numeric', 'min:10', 'max:100'],
            'hra_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'conveyance' => ['nullable', 'numeric', 'min:0'],
        ]);

        $codes = \App\Models\SalaryComponent::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $employee->branch_id)->where('is_active', true)
            ->whereIn('code', ['BASIC', 'HRA', 'CONV', 'SPL'])
            ->pluck('id', 'code');

        abort_unless($codes->has('BASIC') && $codes->has('SPL'), 422,
            'This branch needs pay components with codes BASIC and SPL (and optionally HRA, CONV). Apply the industry template or add them in pay components.');

        $gross = (float) $validated['monthly_gross'];
        $basic = round($gross * (float) ($validated['basic_percent'] ?? 50) / 100 / 100) * 100;
        $hraPercent = $codes->has('HRA') ? (float) ($validated['hra_percent'] ?? 40) : 0.0;
        $hra = round($basic * $hraPercent / 100, 2);
        $conveyance = $codes->has('CONV') ? min((float) ($validated['conveyance'] ?? 1600), max(0, $gross - $basic - $hra)) : 0.0;
        $special = round($gross - $basic - $hra - $conveyance, 2);

        abort_if($special < 0, 422, 'Basic and HRA exceed the monthly gross -- lower the percentages.');

        $from = \Carbon\CarbonImmutable::parse($validated['effective_from']);

        \Illuminate\Support\Facades\DB::transaction(function () use ($employee, $from, $codes, $basic, $hraPercent, $conveyance, $special) {
            // Close whatever is open; drop lines that would start on/after the new date.
            SalaryStructure::where('employee_id', $employee->id)->where('effective_from', '>=', $from->toDateString())->delete();
            SalaryStructure::where('employee_id', $employee->id)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()))
                ->update(['effective_to' => $from->subDay()->toDateString()]);

            foreach (['BASIC' => $basic, 'HRA' => $hraPercent, 'CONV' => $conveyance, 'SPL' => $special] as $code => $amount) {
                if ($amount > 0 && $codes->has($code)) {
                    SalaryStructure::create([
                        'employee_id' => $employee->id, 'component_id' => $codes[$code],
                        'amount' => $amount, 'effective_from' => $from->toDateString(),
                    ]);
                }
            }
        });

        // Bulk updates above bypass model events; record the change explicitly.
        activity('payroll')->performedOn($employee)->event('salary_restructured')
            ->withProperties(['attributes' => ['monthly_gross' => $gross, 'basic' => $basic, 'hra_percent' => $hraPercent,
                'conveyance' => $conveyance, 'special' => $special, 'effective_from' => $from->toDateString()]])
            ->log('Salary restructured');

        return response()->json([
            'data' => SalaryStructure::with('component')->where('employee_id', $employee->id)->orderByDesc('effective_from')->get(),
            'message' => 'Salary restructured from ' . $from->format('d M Y') . '.',
        ]);
    }
}
