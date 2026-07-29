<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Shift;
use App\Models\ShiftRoster;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    private function assertCanManage(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user->is_super_admin || $user->hasAnyRole(['super_admin', 'branch_admin', 'hr']) || $user->can('shifts.manage'),
            403,
            'You are not allowed to manage shift assignments.'
        );
    }

    /**
     * Ends any still-open assignment for this employee the day before the
     * new one starts, so shift history never has ambiguous overlaps.
     */
    private function closeOpenAssignment(int $employeeId, string $newEffectiveFrom): void
    {
        EmployeeShift::where('employee_id', $employeeId)
            ->whereNull('effective_to')
            ->where('effective_from', '<', $newEffectiveFrom)
            ->update(['effective_to' => Carbon::parse($newEffectiveFrom)->subDay()->toDateString()]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Shift::with('branch');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        return response()->json([
            'data'    => $query->orderBy('name')->get(),
            'message' => 'Shifts retrieved successfully.',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'name' => 'required|string|max:191',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'break_minutes' => 'integer|min:0',
            'grace_minutes' => 'integer|min:0',
        ]);

        $shift = Shift::create($validated);

        return response()->json(['data' => $shift, 'message' => 'Shift created successfully.'], 201);
    }

    public function show(Shift $shift): JsonResponse
    {
        $shift->load('branch');

        return response()->json(['data' => $shift, 'message' => 'Shift retrieved successfully.']);
    }

    public function update(Request $request, Shift $shift): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'sometimes|exists:branches,id',
            'name' => 'sometimes|string|max:191',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i',
            'break_minutes' => 'integer|min:0',
            'grace_minutes' => 'integer|min:0',
        ]);

        $shift->update($validated);

        return response()->json(['data' => $shift->fresh(), 'message' => 'Shift updated successfully.']);
    }

    public function destroy(Shift $shift): JsonResponse
    {
        $shift->delete();

        return response()->json(['data' => null, 'message' => 'Shift deleted successfully.']);
    }

    public function assignToEmployee(Request $request, Shift $shift): JsonResponse
    {
        $this->assertCanManage($request);

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
        ]);

        $this->closeOpenAssignment($validated['employee_id'], $validated['effective_from']);

        $employeeShift = EmployeeShift::create([
            'employee_id' => $validated['employee_id'],
            'shift_id' => $shift->id,
            'effective_from' => $validated['effective_from'],
            'effective_to' => $validated['effective_to'] ?? null,
        ]);

        return response()->json(['data' => $employeeShift->load('shift'), 'message' => 'Shift assigned to employee successfully.'], 201);
    }

    /**
     * Assign one shift to many employees at once — the practical way HR
     * actually rolls shifts out, rather than one employee at a time.
     */
    public function assignBulk(Request $request, Shift $shift): JsonResponse
    {
        $this->assertCanManage($request);

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        foreach ($validated['employee_ids'] as $employeeId) {
            $this->closeOpenAssignment($employeeId, $validated['effective_from']);

            EmployeeShift::create([
                'employee_id' => $employeeId,
                'shift_id' => $shift->id,
                'effective_from' => $validated['effective_from'],
                'effective_to' => $validated['effective_to'] ?? null,
            ]);
        }

        $count = count($validated['employee_ids']);

        return response()->json([
            'data' => null,
            'message' => "Shift assigned to {$count} employee" . ($count === 1 ? '' : 's') . '.',
        ], 201);
    }

    /**
     * Current + historical shift assignments for one employee, so the
     * profile can show "current shift" and let HR change it.
     */
    public function employeeAssignments(Request $request, Employee $employee): JsonResponse
    {
        $assignments = EmployeeShift::with('shift')
            ->where('employee_id', $employee->id)
            ->orderByDesc('effective_from')
            ->get();

        $today = Carbon::today()->toDateString();
        $current = $assignments->first(fn ($a) => $a->effective_from->toDateString() <= $today
            && (! $a->effective_to || $a->effective_to->toDateString() >= $today));

        return response()->json([
            'data' => [
                'current' => $current,
                'history' => $assignments->values(),
            ],
        ]);
    }

    public function rosterIndex(Request $request): JsonResponse
    {
        $query = ShiftRoster::with(['employee', 'shift', 'branch', 'department']);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('date')) {
            $query->where('date', $request->input('date'));
        }

        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('date', [$request->input('from'), $request->input('to')]);
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
            'message' => 'Shift rosters retrieved successfully.',
        ]);
    }

    public function rosterStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'department_id' => 'nullable|exists:departments,id',
            'employee_id' => 'required|exists:employees,id',
            'shift_id' => 'required|exists:shifts,id',
            'date' => 'required|date',
        ]);

        $roster = ShiftRoster::create($validated);

        return response()->json(['data' => $roster, 'message' => 'Shift roster created successfully.'], 201);
    }

    public function rosterShow(ShiftRoster $roster): JsonResponse
    {
        $roster->load(['employee', 'shift', 'branch', 'department']);

        return response()->json(['data' => $roster, 'message' => 'Shift roster retrieved successfully.']);
    }
}
