<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = LeaveType::with('branch');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        return response()->json(['data' => $query->orderBy('name')->get(), 'message' => 'Leave types retrieved successfully.']);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'name' => 'required|string|max:191',
            'days_per_year' => 'integer|min:0',
            'carry_forward' => 'boolean',
            'paid' => 'boolean',
        ]);

        $leaveType = LeaveType::create($validated);

        return response()->json(['data' => $leaveType, 'message' => 'Leave type created successfully.'], 201);
    }

    public function show(LeaveType $leaveType): JsonResponse
    {
        return response()->json(['data' => $leaveType, 'message' => 'Leave type retrieved successfully.']);
    }

    public function update(Request $request, LeaveType $leaveType): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'sometimes|exists:branches,id',
            'name' => 'sometimes|string|max:191',
            'days_per_year' => 'integer|min:0',
            'carry_forward' => 'boolean',
            'paid' => 'boolean',
        ]);

        $leaveType->update($validated);

        return response()->json(['data' => $leaveType->fresh(), 'message' => 'Leave type updated successfully.']);
    }

    public function destroy(LeaveType $leaveType): JsonResponse
    {
        $leaveType->delete();

        return response()->json(['data' => null, 'message' => 'Leave type deleted successfully.']);
    }
}
