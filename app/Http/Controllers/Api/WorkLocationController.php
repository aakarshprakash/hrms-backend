<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\WorkLocation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The work-location master used on employee records. */
class WorkLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = WorkLocation::with('branch:id,name')
            ->withCount(['employees' => fn ($q) => $q->where('status', 'active')])
            ->when($request->boolean('active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $location = WorkLocation::create($this->validated($request));

        return response()->json(['data' => $location->load('branch:id,name'), 'message' => 'Work location added.'], 201);
    }

    public function update(Request $request, WorkLocation $workLocation): JsonResponse
    {
        $workLocation->update($this->validated($request, $workLocation));
        // Keep the name shown on employee records in step.
        Employee::where('work_location_id', $workLocation->id)->update(['work_location' => $workLocation->name]);

        return response()->json(['data' => $workLocation->fresh('branch:id,name'), 'message' => 'Work location updated.']);
    }

    public function destroy(WorkLocation $workLocation): JsonResponse
    {
        $people = Employee::where('work_location_id', $workLocation->id)->where('status', 'active')->count();
        abort_if($people > 0, 422, "{$people} active " . ($people === 1 ? 'employee works' : 'employees work')
            . ' here. Move them first, or mark the location inactive instead.');
        Employee::where('work_location_id', $workLocation->id)->update(['work_location_id' => null]);
        $workLocation->delete();

        return response()->json(['data' => null, 'message' => 'Work location deleted.']);
    }

    private function validated(Request $request, ?WorkLocation $existing = null): array
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:191',
                Rule::unique('work_locations', 'name')->where('company_id', app(TenantContext::class)->id())->ignore($existing?->id)],
            'code' => ['nullable', 'string', 'max:50'],
            'type' => ['required', Rule::in(WorkLocation::TYPES)],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['name.unique' => 'A work location with this name already exists.']);
        $v['name'] = trim($v['name']);

        return $v;
    }
}
