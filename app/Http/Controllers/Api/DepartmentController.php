<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Department::with(['branch', 'parentDepartment']);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        return response()->json([
            'data' => $query->get(),
            'message' => 'Departments retrieved successfully.',
        ]);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = Department::create($request->validated());

        return response()->json([
            'data' => $department->load('branch'),
            'message' => 'Department created successfully.',
        ], 201);
    }

    public function show(Department $department): JsonResponse
    {
        $department->load(['branch', 'parentDepartment', 'children', 'designations']);

        return response()->json([
            'data' => $department,
            'message' => 'Department retrieved successfully.',
        ]);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $department->update($request->validated());

        return response()->json([
            'data' => $department->fresh('branch'),
            'message' => 'Department updated successfully.',
        ]);
    }

    public function destroy(Department $department): JsonResponse
    {
        $department->delete();

        return response()->json([
            'data' => null,
            'message' => 'Department deleted successfully.',
        ], 204);
    }
}
