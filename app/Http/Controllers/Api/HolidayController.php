<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Holiday::query();

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        if ($request->filled('year')) {
            $query->whereYear('date', $request->integer('year'));
        }

        $paginator = $query->orderBy('date')->paginate(50);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            'message' => 'Holidays retrieved successfully.',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'name' => 'required|string|max:191',
            'date' => 'required|date',
            'recurring' => 'boolean',
        ]);

        $holiday = Holiday::create($validated);

        return response()->json(['data' => $holiday, 'message' => 'Holiday created successfully.'], 201);
    }

    public function show(Holiday $holiday): JsonResponse
    {
        return response()->json(['data' => $holiday, 'message' => 'Holiday retrieved successfully.']);
    }

    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'sometimes|exists:branches,id',
            'name' => 'sometimes|string|max:191',
            'date' => 'sometimes|date',
            'recurring' => 'boolean',
        ]);

        $holiday->update($validated);

        return response()->json(['data' => $holiday->fresh(), 'message' => 'Holiday updated successfully.']);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $holiday->delete();

        return response()->json(['data' => null, 'message' => 'Holiday deleted successfully.']);
    }
}
