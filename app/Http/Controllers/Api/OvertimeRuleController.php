<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OvertimeRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OvertimeRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $rules = OvertimeRule::with('branch')->get();
        return response()->json(['data' => $rules]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'daily_threshold_hours' => 'nullable|numeric|min:0',
            'weekly_threshold_hours' => 'nullable|numeric|min:0',
            'rate_multiplier' => 'nullable|numeric|min:1',
        ]);

        $rule = OvertimeRule::create($validated);

        return response()->json(['data' => $rule, 'message' => 'Overtime rule created.'], 201);
    }

    public function show(OvertimeRule $overtimeRule): JsonResponse
    {
        return response()->json(['data' => $overtimeRule->load('branch')]);
    }

    public function update(Request $request, OvertimeRule $overtimeRule): JsonResponse
    {
        $validated = $request->validate([
            'daily_threshold_hours' => 'nullable|numeric|min:0',
            'weekly_threshold_hours' => 'nullable|numeric|min:0',
            'rate_multiplier' => 'nullable|numeric|min:1',
        ]);

        $overtimeRule->update($validated);

        return response()->json(['data' => $overtimeRule, 'message' => 'Overtime rule updated.']);
    }

    public function destroy(OvertimeRule $overtimeRule): JsonResponse
    {
        $overtimeRule->delete();
        return response()->json(['message' => 'Overtime rule deleted.']);
    }
}
