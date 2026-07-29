<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDesignationRequest;
use App\Http\Requests\UpdateDesignationRequest;
use App\Models\Designation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesignationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Designation::with(['branch', 'department']);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        return response()->json([
            'data' => $query->get(),
            'message' => 'Designations retrieved successfully.',
        ]);
    }

    public function store(StoreDesignationRequest $request): JsonResponse
    {
        $designation = Designation::create($request->validated());

        return response()->json([
            'data' => $designation->load(['branch', 'department']),
            'message' => 'Designation created successfully.',
        ], 201);
    }

    public function show(Designation $designation): JsonResponse
    {
        $designation->load(['branch', 'department']);

        return response()->json([
            'data' => $designation,
            'message' => 'Designation retrieved successfully.',
        ]);
    }

    public function update(UpdateDesignationRequest $request, Designation $designation): JsonResponse
    {
        $designation->update($request->validated());

        return response()->json([
            'data' => $designation->fresh(['branch', 'department']),
            'message' => 'Designation updated successfully.',
        ]);
    }

    public function destroy(Designation $designation): JsonResponse
    {
        $designation->delete();

        return response()->json([
            'data' => null,
            'message' => 'Designation deleted successfully.',
        ], 204);
    }
}
