<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleBranchIds = $user->accessible_branch_ids;

        $branches = empty($accessibleBranchIds)
            ? Branch::with('company')->get()
            : Branch::with('company')->whereIn('id', $accessibleBranchIds)->get();

        return response()->json([
            'data'    => $branches,
            'message' => 'Branches retrieved successfully.',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:191'],
            'address'       => ['nullable', 'string', 'max:500'],
            'city'          => ['nullable', 'string', 'max:191'],
            'country'       => ['nullable', 'string', 'max:191'],
            'timezone'      => ['nullable', 'string', 'max:100'],
            'currency_code' => ['nullable', 'string', 'max:10'],
            'company_id'    => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        // Default to the first company, creating one if this is a fresh
        // install with none yet, rather than assuming id 1 exists.
        $validated['company_id'] = $validated['company_id']
            ?? Company::first()?->id
            ?? Company::create(['name' => 'My Company'])->id;

        $branch = Branch::create($validated);

        return response()->json([
            'data'    => $branch->load('company'),
            'message' => 'Branch created successfully.',
        ], 201);
    }

    public function show(Branch $branch): JsonResponse
    {
        return response()->json([
            'data'    => $branch->load('company'),
            'message' => 'Branch retrieved successfully.',
        ]);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $validated = $request->validate([
            'name'          => ['sometimes', 'string', 'max:191'],
            'address'       => ['nullable', 'string', 'max:500'],
            'city'          => ['nullable', 'string', 'max:191'],
            'country'       => ['nullable', 'string', 'max:191'],
            'timezone'      => ['nullable', 'string', 'max:100'],
            'currency_code' => ['nullable', 'string', 'max:10'],
        ]);

        $branch->update($validated);

        return response()->json([
            'data'    => $branch->fresh('company'),
            'message' => 'Branch updated successfully.',
        ]);
    }

    public function destroy(Branch $branch): JsonResponse
    {
        // Soft-check: don't delete if employees exist
        if ($branch->employees()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a branch that has employees. Reassign employees first.',
            ], 422);
        }

        $branch->delete();

        return response()->json(['message' => 'Branch deleted successfully.']);
    }

    public function company(Request $request): JsonResponse
    {
        $company = Company::first();

        if ($request->isMethod('put')) {
            $validated = $request->validate([
                'name'     => ['required', 'string', 'max:191'],
                'timezone' => ['nullable', 'string', 'max:100'],
            ]);

            // No company exists yet on a fresh install -- create the first
            // one from this same form instead of assuming one is already there.
            $company = $company
                ? tap($company)->update($validated)
                : Company::create($validated);
        }

        return response()->json(['data' => $company]);
    }
}
