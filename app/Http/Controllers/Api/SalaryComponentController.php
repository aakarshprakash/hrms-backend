<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pay components (Basic, HRA, incentives, recoveries...) and how each one
 * behaves in payroll: fixed or percentage, PF / ESI wage, taxable, pro-rated
 * by attendance, variable.
 */
class SalaryComponentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branchId = $this->requestedBranchId();

        $query = SalaryComponent::with('branch:id,name')
            ->withCount('structures')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('type')->orderBy('display_order')->orderBy('name');

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules($request, null));
        $this->authorizeBranch((int) $validated['branch_id']);

        $component = SalaryComponent::create($this->normalize($validated));

        return response()->json(['data' => $component, 'message' => 'Salary component created.'], 201);
    }

    public function show(SalaryComponent $salaryComponent): JsonResponse
    {
        return response()->json(['data' => $salaryComponent->load('branch')]);
    }

    public function update(Request $request, SalaryComponent $salaryComponent): JsonResponse
    {
        $this->authorizeBranch($salaryComponent->branch_id);
        $validated = $request->validate($this->rules($request, $salaryComponent));

        $salaryComponent->update($this->normalize($validated));

        return response()->json(['data' => $salaryComponent->fresh(), 'message' => 'Salary component updated.']);
    }

    public function destroy(SalaryComponent $salaryComponent): JsonResponse
    {
        $this->authorizeBranch($salaryComponent->branch_id);

        // Deleting would cascade to every employee's salary lines using it.
        if (SalaryStructure::where('component_id', $salaryComponent->id)->exists()) {
            return response()->json([
                'message' => 'This component is part of employee salary structures. Deactivate it instead of deleting.',
            ], 422);
        }

        $salaryComponent->delete();

        return response()->json(['message' => 'Salary component deleted.']);
    }

    private function rules(Request $request, ?SalaryComponent $existing): array
    {
        $creating = $existing === null;
        $branchId = $existing?->branch_id ?? $request->input('branch_id');

        return [
            'branch_id' => $creating ? ['required', 'integer', 'exists:branches,id'] : ['prohibited'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:191'],
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_\-]+$/',
                Rule::unique('salary_components', 'code')->where('branch_id', $branchId)->ignore($existing?->id)],
            'type' => [$creating ? 'required' : 'sometimes', Rule::in(['earning', 'deduction'])],
            'calculation_type' => [$creating ? 'required' : 'sometimes', Rule::in(['fixed', 'percentage'])],
            'percentage_of' => ['nullable', Rule::in(['basic', 'gross'])],
            'default_value' => ['nullable', 'numeric', 'min:0'],
            'is_basic' => ['sometimes', 'boolean'],
            'pf_applicable' => ['sometimes', 'boolean'],
            'esi_applicable' => ['sometimes', 'boolean'],
            'taxable' => ['sometimes', 'boolean'],
            'prorate' => ['sometimes', 'boolean'],
            'is_variable' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function normalize(array $validated): array
    {
        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }
        if (array_key_exists('percentage_of', $validated) && $validated['percentage_of'] === null) {
            $validated['percentage_of'] = 'basic';
        }

        return $validated;
    }
}
