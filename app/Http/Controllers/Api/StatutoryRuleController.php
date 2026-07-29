<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StatutoryRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatutoryRuleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => StatutoryRule::with('branch')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'country' => 'nullable|string|max:10',
            'rule_type' => 'required|in:PF,ESI,TAX',
            'config_json' => 'required|array',
            'is_active' => 'nullable|boolean',
        ]);

        $rule = StatutoryRule::create($validated);

        return response()->json(['data' => $rule, 'message' => 'Statutory rule created.'], 201);
    }

    public function show(StatutoryRule $statutoryRule): JsonResponse
    {
        return response()->json(['data' => $statutoryRule->load('branch')]);
    }

    public function update(Request $request, StatutoryRule $statutoryRule): JsonResponse
    {
        $validated = $request->validate([
            'country' => 'nullable|string|max:10',
            'rule_type' => 'sometimes|in:PF,ESI,TAX',
            'config_json' => 'sometimes|array',
            'is_active' => 'nullable|boolean',
        ]);

        $statutoryRule->update($validated);

        return response()->json(['data' => $statutoryRule, 'message' => 'Statutory rule updated.']);
    }

    public function destroy(StatutoryRule $statutoryRule): JsonResponse
    {
        $statutoryRule->delete();
        return response()->json(['message' => 'Statutory rule deleted.']);
    }
}
