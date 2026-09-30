<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftRoster;
use App\Services\Attendance\AttendanceProcessor;
use App\Services\Attendance\ScheduleResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Shift roster: who works which shift on which day, and rostered days off.
 * A roster entry overrides the employee's standing shift assignment for
 * that one date; clearing it falls back to the assignment / branch default.
 */
class RosterController extends Controller
{
    private const MAX_DAYS = 42;

    /** GET /rosters/grid?branch_id=&from=&to=[&department_id=] */
    public function grid(Request $request, ScheduleResolver $resolver): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);
        $this->authorizeBranch($validated['branch_id']);
        [$from, $to] = $this->range($validated['from'], $validated['to']);

        $employees = Employee::with(['department:id,name', 'designation:id,title'])
            ->visibleTo($request->user())
            ->where('branch_id', $validated['branch_id'])
            ->where('status', 'active')
            ->when($validated['department_id'] ?? null, fn ($q, $d) => $q->where('department_id', $d))
            ->orderBy('first_name')
            ->get();

        $schedules = $resolver->forEmployees($employees, $from, $to);

        $attendance = Attendance::whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('date', [$from, $to])
            ->get(['employee_id', 'date', 'status'])
            ->groupBy('employee_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($a) => [$a->date->toDateString() => $a->status]));

        $days = array_map(fn ($d) => $d->toDateString(), iterator_to_array(CarbonPeriod::create($from, $to)));

        return response()->json(['data' => [
            'days' => $days,
            'shifts' => Shift::where('branch_id', $validated['branch_id'])->orderBy('start_time')
                ->get(['id', 'name', 'code', 'start_time', 'end_time', 'color', 'is_active']),
            'rows' => $employees->map(fn (Employee $e) => [
                'employee' => [
                    'id' => $e->id,
                    'name' => $e->full_name,
                    'employee_code' => $e->employee_code,
                    'department' => $e->department?->name,
                    'designation' => $e->designation?->title,
                    'weekly_off_days' => $e->weekly_off_days,
                ],
                'cells' => collect($days)->mapWithKeys(fn ($d) => [$d => array_merge(
                    $schedules[$e->id][$d]->toArray(),
                    ['attendance' => $attendance->get($e->id)?->get($d)]
                )]),
            ])->values(),
        ]]);
    }

    /**
     * PUT /rosters/grid -- bulk edit cells.
     * entries: [{employee_id, date, shift_id?, is_off?, clear?}]
     */
    public function save(Request $request, AttendanceProcessor $processor): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'entries' => ['required', 'array', 'min:1', 'max:2000'],
            'entries.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'entries.*.date' => ['required', 'date'],
            'entries.*.shift_id' => ['nullable', 'integer', Rule::exists('shifts', 'id')->where('branch_id', $request->input('branch_id'))],
            'entries.*.is_off' => ['sometimes', 'boolean'],
            'entries.*.clear' => ['sometimes', 'boolean'],
        ]);
        $branchId = (int) $validated['branch_id'];
        $this->authorizeBranch($branchId);

        $employeeIds = collect($validated['entries'])->pluck('employee_id')->unique();
        $employees = Employee::visibleTo($request->user())
            ->where('branch_id', $branchId)
            ->whereIn('id', $employeeIds)
            ->get()
            ->keyBy('id');

        abort_if($employees->count() !== $employeeIds->count(), 422, 'Every employee must belong to the selected branch.');

        $locked = Attendance::whereIn('employee_id', $employeeIds)
            ->whereIn('date', collect($validated['entries'])->pluck('date')->unique())
            ->whereNotNull('locked_at')
            ->exists();
        abort_if($locked, 422, 'Some of these days are locked by a finalized payroll run.');

        $affected = [];

        DB::transaction(function () use ($validated, $employees, $branchId, &$affected) {
            foreach ($validated['entries'] as $entry) {
                $date = CarbonImmutable::parse($entry['date'])->toDateString();
                $employee = $employees[$entry['employee_id']];
                $key = ['employee_id' => $employee->id, 'date' => $date];

                if (! empty($entry['clear'])) {
                    ShiftRoster::where($key)->delete();
                } else {
                    $isOff = (bool) ($entry['is_off'] ?? false);
                    abort_if(! $isOff && empty($entry['shift_id']), 422, 'Pick a shift or mark the day off.');

                    ShiftRoster::updateOrCreate($key, [
                        'branch_id' => $branchId,
                        'department_id' => $employee->department_id,
                        'shift_id' => $isOff ? null : $entry['shift_id'],
                        'is_off' => $isOff,
                    ]);
                }

                $affected[$employee->id][] = $date;
            }
        });

        $this->reprocessPast($processor, $affected);

        return response()->json(['message' => 'Roster saved.', 'data' => ['cells' => count($validated['entries'])]]);
    }

    /** POST /rosters/copy-week -- repeat one week's roster onto another week. */
    public function copyWeek(Request $request, AttendanceProcessor $processor): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'source_start' => ['required', 'date'],
            'target_start' => ['required', 'date', 'different:source_start'],
        ]);
        $this->authorizeBranch($validated['branch_id']);

        $source = CarbonImmutable::parse($validated['source_start']);
        $offset = (int) $source->diffInDays(CarbonImmutable::parse($validated['target_start']), false);

        $rows = ShiftRoster::where('branch_id', $validated['branch_id'])
            ->whereIn('employee_id', Employee::visibleTo($request->user())->select('id'))
            ->whereBetween('date', [$source->toDateString(), $source->addDays(6)->toDateString()])
            ->get();

        $affected = [];
        DB::transaction(function () use ($rows, $offset, &$affected) {
            foreach ($rows as $row) {
                $date = $row->date->copy()->addDays($offset)->toDateString();
                ShiftRoster::updateOrCreate(
                    ['employee_id' => $row->employee_id, 'date' => $date],
                    $row->only(['branch_id', 'department_id', 'shift_id', 'is_off'])
                );
                $affected[$row->employee_id][] = $date;
            }
        });

        $this->reprocessPast($processor, $affected);

        return response()->json(['message' => "Copied {$rows->count()} roster entr" . ($rows->count() === 1 ? 'y.' : 'ies.')]);
    }

    /** PUT /rosters/weekly-offs -- personal weekly-off pattern (null = branch default). */
    public function weeklyOffs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
            'weekly_off_days' => ['present', 'nullable', 'array'],
            'weekly_off_days.*' => ['integer', 'between:0,6'],
        ]);

        $employees = Employee::visibleTo($request->user())->whereIn('id', $validated['employee_ids'])->get();
        abort_if($employees->count() !== count(array_unique($validated['employee_ids'])), 404, 'Employee not found.');

        foreach ($employees as $employee) {
            $this->authorizeBranch($employee->branch_id);
            $employee->update([
                'weekly_off_days' => $validated['weekly_off_days'] === null ? null : array_values(array_unique($validated['weekly_off_days'])),
            ]);
        }

        return response()->json(['message' => 'Weekly offs updated for ' . $employees->count() . ' employee(s).']);
    }

    /** @return array{0: string, 1: string} */
    private function range(string $from, string $to): array
    {
        $f = CarbonImmutable::parse($from);
        $t = CarbonImmutable::parse($to);
        abort_if($f->diffInDays($t) >= self::MAX_DAYS, 422, 'Show at most six weeks at a time.');

        return [$f->toDateString(), $t->toDateString()];
    }

    /** Days already worked follow the new roster immediately. */
    private function reprocessPast(AttendanceProcessor $processor, array $affected): void
    {
        $today = now()->toDateString();
        $past = array_filter(array_map(
            fn ($dates) => array_values(array_filter(array_unique($dates), fn ($d) => $d <= $today)),
            $affected
        ));

        if ($past) {
            $processor->processAffected($past);
        }
    }
}
