<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalaryComponent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalaryComponentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SalaryComponent::with('branch');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'name' => 'required|string|max:191',
            'type' => 'required|in:earning,deduction',
            'calculation_type' => 'required|in:fixed,percentage',
        ]);

        $component = SalaryComponent::create($validated);

        return response()->json(['data' => $component, 'message' => 'Salary component created.'], 201);
    }

    public function show(SalaryComponent $salaryComponent): JsonResponse
    {
        return response()->json(['data' => $salaryComponent->load('branch')]);
    }

    public function update(Request $request, SalaryComponent $salaryComponent): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:191',
            'type' => 'sometimes|in:earning,deduction',
            'calculation_type' => 'sometimes|in:fixed,percentage',
        ]);

        $salaryComponent->update($validated);

        return response()->json(['data' => $salaryComponent, 'message' => 'Salary component updated.']);
    }

    public function destroy(SalaryComponent $salaryComponent): JsonResponse
    {
        $salaryComponent->delete();
        return response()->json(['message' => 'Salary component deleted.']);
    }
}
