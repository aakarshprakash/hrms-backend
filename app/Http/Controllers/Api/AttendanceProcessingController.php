<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\RawPunch;
use App\Services\Attendance\AttendanceProcessor;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The raw-punch side of attendance: what devices and apps actually sent,
 * codes nobody has mapped yet, and reprocessing days after rules change.
 */
class AttendanceProcessingController extends Controller
{
    /** POST /attendance/reprocess -- re-derive days from punches (no device re-fetch). */
    public function reprocess(Request $request, AttendanceProcessor $processor): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from', 'before_or_equal:today'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        abort_if(Carbon::parse($validated['from'])->diffInDays(Carbon::parse($validated['to'])) > 62, 422,
            'Reprocess at most two months at a time.');

        $branchId = $this->requestedBranchId();

        $user = $request->user();
        $allowed = $user->accessibleBranchIds();

        // employeesQuery() lifts the branch scope (it serves console jobs too),
        // so confine branch-bound users explicitly.
        $employees = AttendanceProcessor::employeesQuery($branchId)
            ->visibleTo($user)
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed))
            ->when($validated['employee_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->get();

        $stats = $processor->processEmployees($employees, $validated['from'], $validated['to']);

        return response()->json([
            'data' => $stats + ['employees' => $employees->count()],
            'message' => "Reprocessed {$stats['processed']} employee-day(s)"
                . ($stats['skipped'] ? "; {$stats['skipped']} manual/locked day(s) left unchanged." : '.'),
        ]);
    }

    /** GET /raw-punches -- the punch log, for audit and troubleshooting. */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'employee_id' => ['nullable', 'integer'],
            'source' => ['nullable', 'string', 'max:20'],
        ]);

        $user = $request->user();
        $branchId = $this->requestedBranchId();

        $query = RawPunch::with(['employee:id,first_name,last_name,employee_code', 'branch:id,name'])
            ->when($request->boolean('unmatched'),
                fn ($q) => $q->whereNull('employee_id'),
                fn ($q) => $q->visibleTo($user))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($user->accessibleBranchIds() !== null, fn ($q) => $q->whereIn('branch_id', $user->accessibleBranchIds()))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->when($request->filled('from'), fn ($q) => $q->where('punched_at_local', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('punched_at_local', '<=', $request->date('to')->endOfDay()))
            ->orderByDesc('punched_at');

        $page = $query->paginate(min(max($request->integer('per_page', 50), 1), 200));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'total' => $page->total(), 'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
            ],
        ]);
    }

    /** GET /raw-punches/unmatched-codes -- device codes with no employee mapped. */
    public function unmatchedCodes(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $this->requestedBranchId();

        $rows = RawPunch::query()
            ->whereNull('employee_id')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($user->accessibleBranchIds() !== null, fn ($q) => $q->whereIn('branch_id', $user->accessibleBranchIds()))
            ->select('branch_id', 'device_emp_code', DB::raw('COUNT(*) as punches'), DB::raw('MIN(punched_at_local) as first_seen'), DB::raw('MAX(punched_at_local) as last_seen'))
            ->groupBy('branch_id', 'device_emp_code')
            ->orderByDesc('punches')
            ->get();

        $branches = \App\Models\Branch::whereIn('id', $rows->pluck('branch_id')->unique())->pluck('name', 'id');

        return response()->json(['data' => $rows->map(fn ($r) => [
            'branch_id' => $r->branch_id,
            'branch' => $branches[$r->branch_id] ?? null,
            'device_emp_code' => $r->device_emp_code,
            'punches' => (int) $r->punches,
            'first_seen' => $r->first_seen,
            'last_seen' => $r->last_seen,
        ])]);
    }

    /** POST /employees/{employee}/map-device-code -- map a code and pull in its orphaned punches. */
    public function mapDeviceCode(Request $request, Employee $employee, \App\Services\Attendance\PunchRecorder $recorder, AttendanceProcessor $processor): JsonResponse
    {
        $this->authorize('update', $employee);

        $validated = $request->validate([
            'device_emp_code' => ['required', 'string', 'max:50',
                \Illuminate\Validation\Rule::unique('employees', 'biometric_emp_code')
                    ->where(fn ($q) => $q->where('branch_id', $employee->branch_id))
                    ->ignore($employee->id)],
        ]);

        $employee->update(['biometric_emp_code' => $validated['device_emp_code']]);
        $dates = $recorder->relinkUnmatched($employee);

        if ($dates) {
            $processor->processAffected([$employee->id => $dates]);
        }

        return response()->json([
            'data' => ['employee_id' => $employee->id, 'days_linked' => count($dates)],
            'message' => count($dates)
                ? "Linked and processed punches for " . count($dates) . ' day(s).'
                : 'Device code saved.',
        ]);
    }
}
