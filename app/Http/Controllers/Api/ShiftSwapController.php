<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ShiftSwapRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftSwapController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'with_employee_id' => 'required|exists:employees,id',
            'my_date'          => 'required|date',
            'their_date'       => 'required|date',
            'reason'           => 'nullable|string',
        ]);

        $requester = Employee::withoutGlobalScopes()
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$requester) {
            return response()->json(['message' => 'No employee profile found for this user.'], 422);
        }

        if ((int) $validated['with_employee_id'] === $requester->id) {
            return response()->json(['message' => 'Cannot swap shift with yourself.'], 422);
        }

        $swap = ShiftSwapRequest::create([
            'requester_id'      => $requester->id,
            'target_employee_id'=> $validated['with_employee_id'],
            'my_date'           => $validated['my_date'],
            'their_date'        => $validated['their_date'],
            'reason'            => $validated['reason'] ?? null,
            'status'            => 'pending',
        ]);

        return response()->json(['data' => $swap, 'message' => 'Shift swap request created successfully.'], 201);
    }

    public function update(Request $request, ShiftSwapRequest $swap): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);

        $swap->update($validated);

        return response()->json(['data' => $swap->fresh(), 'message' => 'Shift swap request updated successfully.']);
    }
}
