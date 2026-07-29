<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Services\AttendanceStatusResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function checkIn(Request $request, AttendanceStatusResolver $resolver): JsonResponse
    {
        $employee = Employee::withoutGlobalScopes()->where('user_id', $request->user()->id)->first();

        if (!$employee) {
            return response()->json(['message' => 'No employee profile found for this user.'], 422);
        }

        $today = Carbon::today()->toDateString();

        $holiday = Holiday::where('branch_id', $employee->branch_id)
            ->whereDate('date', $today)
            ->first();

        $attendance = Attendance::firstOrCreate(
            ['employee_id' => $employee->id, 'date' => $today],
            ['status' => 'present', 'source' => $request->input('source', 'web')]
        );

        if ($attendance->check_in) {
            return response()->json(['message' => 'Already checked in today.'], 422);
        }

        $now = Carbon::now();
        $resolved = $resolver->resolve($employee, $today, $now, null);

        $attendance->update([
            'check_in' => $now,
            'source'    => $request->input('source', 'web'),
            'latitude'  => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
            'shift_id' => $resolved['shift_id'],
            'status' => $resolved['status'],
            'late_by_minutes' => $resolved['late_by_minutes'],
        ]);

        $message = $holiday
            ? "Checked in on a holiday: {$holiday->name}. This has been recorded."
            : ($resolved['status'] === 'late' ? "Checked in successfully — {$resolved['late_by_minutes']} min late." : 'Checked in successfully.');

        return response()->json([
            'data'    => $attendance->fresh(),
            'message' => $message,
            'holiday' => $holiday ? ['name' => $holiday->name, 'date' => $today] : null,
        ]);
    }

    public function checkOut(Request $request, AttendanceStatusResolver $resolver): JsonResponse
    {
        $employee = Employee::withoutGlobalScopes()->where('user_id', $request->user()->id)->first();

        if (!$employee) {
            return response()->json(['message' => 'No employee profile found for this user.'], 422);
        }

        $today = Carbon::today()->toDateString();

        $attendance = Attendance::where('employee_id', $employee->id)
            ->where('date', $today)
            ->first();

        if (!$attendance || !$attendance->check_in) {
            return response()->json(['message' => 'No check-in found for today.'], 422);
        }

        if ($attendance->check_out) {
            return response()->json(['message' => 'Already checked out today.'], 422);
        }

        $now = Carbon::now();
        $resolved = $resolver->resolve($employee, $today, $attendance->check_in, $now);

        $attendance->update([
            'check_out' => $now,
            'shift_id' => $resolved['shift_id'],
            'status' => $resolved['status'],
            'late_by_minutes' => $resolved['late_by_minutes'],
            'early_by_minutes' => $resolved['early_by_minutes'],
            'worked_minutes' => $resolved['worked_minutes'],
        ]);

        return response()->json(['data' => $attendance->fresh(), 'message' => 'Checked out successfully.']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Attendance::with('employee');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('branch_id')) {
            $query->whereHas('employee', fn ($q) => $q->where('branch_id', $request->integer('branch_id')));
        }

        if ($request->filled('date')) {
            $query->whereDate('date', $request->date('date'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('month') && $request->filled('year')) {
            $query->whereMonth('date', $request->integer('month'))
                ->whereYear('date', $request->integer('year'));
        } elseif ($request->filled('year')) {
            $query->whereYear('date', $request->integer('year'));
        }

        $paginator = $query->orderBy('date', 'desc')->paginate(30);
        $items     = $paginator->items();

        // Annotate each attendance record with holiday info
        if (!empty($items)) {
            $dates      = array_map(fn($a) => $a->date instanceof \Carbon\Carbon ? $a->date->toDateString() : $a->date, $items);
            $branchIds  = array_unique(array_filter(array_map(fn($a) => $a->employee?->branch_id ?? null, $items)));
            $holidayMap = [];

            if (!empty($branchIds)) {
                Holiday::whereIn('branch_id', $branchIds)
                    ->whereIn(\Illuminate\Support\Facades\DB::raw('DATE(date)'), $dates)
                    ->get()
                    ->each(function ($h) use (&$holidayMap) {
                        $holidayMap[$h->date->toDateString()] = $h->name;
                    });
            }

            foreach ($items as $att) {
                $dateKey = $att->date instanceof \Carbon\Carbon ? $att->date->toDateString() : $att->date;
                $att->is_holiday    = isset($holidayMap[$dateKey]);
                $att->holiday_name  = $holidayMap[$dateKey] ?? null;
            }
        }

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            'message' => 'Attendances retrieved successfully.',
        ]);
    }

    public function show(Attendance $attendance): JsonResponse
    {
        $attendance->load('employee');

        return response()->json(['data' => $attendance, 'message' => 'Attendance retrieved successfully.']);
    }

    /**
     * Roster view for a single day: every active employee with their attendance
     * record (or null), so HR can mark attendance manually. API-based device
     * sync will feed the same records later.
     */
    public function daySummary(Request $request): JsonResponse
    {
        $request->validate(['date' => ['required', 'date']]);

        $date = Carbon::parse($request->input('date'))->toDateString();

        $employees = Employee::with(['department:id,name', 'designation:id,title', 'media'])
            ->where('status', 'active')
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->orderBy('first_name')
            ->get();

        $attendances = Attendance::whereDate('date', $date)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get()
            ->keyBy('employee_id');

        $onLeave = \App\Models\Leave::where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->pluck('employee_id')
            ->all();

        $rows = $employees->map(function ($emp) use ($attendances, $onLeave) {
            return [
                'employee' => [
                    'id' => $emp->id,
                    'employee_code' => $emp->employee_code,
                    'name' => $emp->full_name,
                    'avatar_url' => $emp->avatar_url,
                    'department' => $emp->department?->name,
                    'designation' => $emp->designation?->title,
                ],
                'attendance' => $attendances->get($emp->id),
                'on_approved_leave' => in_array($emp->id, $onLeave, true),
            ];
        });

        $counts = [
            'total' => $employees->count(),
            'present' => $rows->filter(fn ($r) => in_array($r['attendance']?->status, ['present', 'late'], true))->count(),
            'late' => $rows->filter(fn ($r) => $r['attendance']?->status === 'late')->count(),
            'absent' => $rows->filter(fn ($r) => $r['attendance']?->status === 'absent')->count(),
            'half_day' => $rows->filter(fn ($r) => $r['attendance']?->status === 'half_day')->count(),
            'on_leave' => count($onLeave),
            'unmarked' => $rows->filter(fn ($r) => $r['attendance'] === null && ! $r['on_approved_leave'])->count(),
        ];

        return response()->json([
            'data' => ['date' => $date, 'rows' => $rows->values(), 'counts' => $counts],
            'message' => 'Day summary retrieved successfully.',
        ]);
    }

    /**
     * Manual attendance entry by HR/admin: create or update the record for an
     * employee on a given date.
     */
    public function manualUpsert(Request $request, AttendanceStatusResolver $resolver): JsonResponse
    {
        $user = $request->user();
        if (! ($user->is_super_admin || $user->hasAnyRole(['super_admin', 'branch_admin', 'hr']) || $user->can('attendance.manage'))) {
            abort(403, 'You are not allowed to manage attendance.');
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date'],
            'status' => ['required', 'in:present,absent,half_day,late'],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
        ]);

        $date = Carbon::parse($validated['date'])->toDateString();
        $employee = Employee::withoutGlobalScopes()->with('branch')->findOrFail($validated['employee_id']);

        // HR types check-in/out in the employee's branch's local wall-clock
        // time, not the app's UTC — parse as that zone, then convert to UTC
        // so it's the actual value Eloquent writes to the database (it
        // formats Carbon instances for storage in whatever zone they're
        // currently set to, not UTC, unless told to).
        $timezone = $employee->branch?->timezone ?: 'UTC';
        $checkIn = isset($validated['check_in']) ? Carbon::parse("{$date} {$validated['check_in']}", $timezone)->utc() : null;
        $checkOut = isset($validated['check_out']) ? Carbon::parse("{$date} {$validated['check_out']}", $timezone)->utc() : null;

        // HR's chosen status is authoritative (they may be recording an
        // approved exception) — but the shift/lateness/worked-time figures
        // are still computed so reports stay consistent with automatic entries.
        $resolved = $resolver->resolve($employee, $date, $checkIn, $checkOut);

        $attendance = Attendance::updateOrCreate(
            ['employee_id' => $validated['employee_id'], 'date' => $date],
            [
                'status' => $validated['status'],
                'source' => 'manual',
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'shift_id' => $resolved['shift_id'],
                'late_by_minutes' => $resolved['late_by_minutes'],
                'early_by_minutes' => $resolved['early_by_minutes'],
                'worked_minutes' => $resolved['worked_minutes'],
            ]
        );

        return response()->json([
            'data' => $attendance->fresh('employee'),
            'message' => 'Attendance saved.',
        ]);
    }
}
