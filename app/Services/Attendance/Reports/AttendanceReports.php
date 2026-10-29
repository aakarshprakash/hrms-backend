<?php

namespace App\Services\Attendance\Reports;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\OvertimeRequest;
use App\Models\RawPunch;
use App\Models\Shift;
use App\Models\User;
use App\Services\Attendance\GeoFence;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Every attendance report from one engine, for any date range.
 *
 * Each employee-day is resolved once, the same way for every report:
 *   recorded status (absent with an approved leave counts as leave) →
 *   holiday → weekly off → approved leave → upcoming → "not marked".
 * Days before joining / after leaving don't count. Reports then only
 * differ in how they roll those days up.
 *
 * A report is {title, columns: [{key, label, align?, type?}], rows, totals?, layout}.
 */
class AttendanceReports
{
    public const TYPES = [
        'summary' => 'Attendance summary',
        'daily' => 'Daily attendance',
        'muster' => 'Muster roll',
        'late_early' => 'Late arrivals & early exits',
        'absent' => 'Absentees',
        'overtime' => 'Overtime',
        'department' => 'Department summary',
        'punches' => 'Punch log',
        'location' => 'Location exceptions',
    ];

    public const MAX_DAYS = 92;

    /** Short codes for the muster roll. */
    public const CODES = [
        'present' => 'P', 'late' => 'P', 'half_day' => 'HD', 'absent' => 'A', 'on_leave' => 'L',
        'weekly_off' => 'WO', 'holiday' => 'H', 'not_marked' => '-', 'upcoming' => '',
    ];

    public const STATUS_LABELS = [
        'present' => 'Present', 'late' => 'Late', 'half_day' => 'Half day', 'absent' => 'Absent', 'on_leave' => 'On leave',
        'weekly_off' => 'Weekly off', 'holiday' => 'Holiday', 'not_marked' => 'Not marked', 'upcoming' => '',
    ];

    private CarbonImmutable $from;

    private CarbonImmutable $to;

    private array $filters;

    public function __construct(private GeoFence $geoFence)
    {
    }

    public function build(string $type, array $filters, User $user): array
    {
        abort_unless(isset(self::TYPES[$type]), 404, 'Unknown report.');

        $this->filters = $filters;
        $this->from = CarbonImmutable::parse($filters['from'])->startOfDay();
        $this->to = CarbonImmutable::parse($filters['to'])->startOfDay();
        abort_if($this->to->lt($this->from), 422, 'The end date must be on or after the start date.');
        abort_if($this->from->diffInDays($this->to) + 1 > self::MAX_DAYS, 422, 'Reports cover up to ' . self::MAX_DAYS . ' days at a time.');
        abort_if($type === 'muster' && $this->from->diffInDays($this->to) + 1 > 31, 422, 'The muster roll covers up to 31 days.');

        $report = match ($type) {
            'summary' => $this->summary($user),
            'daily' => $this->daily($user),
            'muster' => $this->muster($user),
            'late_early' => $this->lateEarly($user),
            'absent' => $this->absent($user),
            'overtime' => $this->overtime($user),
            'department' => $this->department($user),
            'punches' => $this->punches($user, false),
            'location' => $this->punches($user, true),
        };

        return $report + [
            'type' => $type,
            'title' => self::TYPES[$type],
            'period' => ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'days' => $this->from->diffInDays($this->to) + 1],
            'layout' => $report['layout'] ?? 'table',
            'totals' => $report['totals'] ?? null,
        ];
    }

    // ── Reports ───────────────────────────────────────────────────────────

    private function summary(User $user): array
    {
        $rows = $this->grid($user)->map(fn ($e) => $this->employeeCells($e['employee']) + $this->rollup($e['days']))->values();

        return [
            'columns' => [
                ...$this->employeeColumns(),
                ['key' => 'working_days', 'label' => 'Working days', 'align' => 'right'],
                ['key' => 'present', 'label' => 'Present', 'align' => 'right'],
                ['key' => 'late', 'label' => 'Late', 'align' => 'right'],
                ['key' => 'half_day', 'label' => 'Half day', 'align' => 'right'],
                ['key' => 'absent', 'label' => 'Absent', 'align' => 'right'],
                ['key' => 'leave', 'label' => 'Leave', 'align' => 'right'],
                ['key' => 'weekly_off', 'label' => 'Weekly off', 'align' => 'right'],
                ['key' => 'holiday', 'label' => 'Holiday', 'align' => 'right'],
                ['key' => 'not_marked', 'label' => 'Not marked', 'align' => 'right'],
                ['key' => 'payable_days', 'label' => 'Payable days', 'align' => 'right', 'emphasis' => true],
                ['key' => 'worked_hours', 'label' => 'Worked hrs', 'align' => 'right'],
                ['key' => 'overtime_hours', 'label' => 'OT hrs', 'align' => 'right'],
                ['key' => 'attendance_pct', 'label' => 'Attendance %', 'align' => 'right', 'type' => 'percent'],
            ],
            'rows' => $rows,
            'totals' => $this->sumColumns($rows, ['working_days', 'present', 'late', 'half_day', 'absent', 'leave', 'weekly_off', 'holiday', 'not_marked', 'payable_days', 'worked_hours', 'overtime_hours'])
                + ['attendance_pct' => $this->pct($rows->sum('present') + $rows->sum('half_day') / 2, $rows->sum('working_days'))],
        ];
    }

    private function daily(User $user): array
    {
        $status = $this->filters['status'] ?? null;
        $rows = collect();
        foreach ($this->grid($user) as $e) {
            foreach ($e['days'] as $date => $d) {
                if ($d['status'] === 'upcoming' || ($status && $d['status'] !== $status && ! ($status === 'present' && $d['status'] === 'late'))) {
                    continue;
                }
                $a = $d['attendance'];
                $rows->push($this->employeeCells($e['employee']) + [
                    'date' => $date,
                    'day' => CarbonImmutable::parse($date)->format('D'),
                    'shift' => $d['shift'],
                    'status' => $d['status'],
                    'status_label' => $d['label'],
                    'check_in' => $this->time($a?->check_in, $e['tz']),
                    'check_out' => $this->time($a?->check_out, $e['tz']),
                    'worked_hours' => $a?->worked_minutes ? round($a->worked_minutes / 60, 2) : null,
                    'late_minutes' => $a?->late_by_minutes ?: null,
                    'early_minutes' => $a?->early_by_minutes ?: null,
                    'overtime_minutes' => $a?->overtime_minutes ?: null,
                    'source' => $a?->source,
                    'remarks' => $a?->remarks ?? $d['note'],
                ]);
            }
        }

        return [
            'columns' => [
                ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'day', 'label' => 'Day'],
                ...$this->employeeColumns(),
                ['key' => 'shift', 'label' => 'Shift'],
                ['key' => 'status_label', 'label' => 'Status', 'type' => 'status', 'status_key' => 'status'],
                ['key' => 'check_in', 'label' => 'In'],
                ['key' => 'check_out', 'label' => 'Out'],
                ['key' => 'worked_hours', 'label' => 'Worked hrs', 'align' => 'right'],
                ['key' => 'late_minutes', 'label' => 'Late (min)', 'align' => 'right'],
                ['key' => 'early_minutes', 'label' => 'Early exit (min)', 'align' => 'right'],
                ['key' => 'overtime_minutes', 'label' => 'OT (min)', 'align' => 'right'],
                ['key' => 'source', 'label' => 'Source'],
                ['key' => 'remarks', 'label' => 'Remarks'],
            ],
            'rows' => $rows->sortBy([['date', 'asc'], ['name', 'asc']])->values(),
        ];
    }

    private function muster(User $user): array
    {
        $dates = collect(CarbonPeriod::create($this->from, $this->to))->map(fn ($d) => $d->toDateString());
        $rows = $this->grid($user)->map(function ($e) use ($dates) {
            $cells = [];
            foreach ($dates as $date) {
                $d = $e['days'][$date] ?? null;
                $cells[$date] = $d ? ['code' => self::CODES[$d['status']], 'status' => $d['status']] : ['code' => '', 'status' => null];
            }
            $r = $this->rollup($e['days']);

            return $this->employeeCells($e['employee']) + ['cells' => $cells] + [
                'present' => $r['present'], 'absent' => $r['absent'], 'leave' => $r['leave'], 'half_day' => $r['half_day'],
                'weekly_off' => $r['weekly_off'], 'holiday' => $r['holiday'], 'payable_days' => $r['payable_days'],
            ];
        })->values();

        return [
            'layout' => 'grid',
            'dates' => $dates->values(),
            'legend' => ['P' => 'Present', 'HD' => 'Half day', 'A' => 'Absent', 'L' => 'Leave', 'WO' => 'Weekly off', 'H' => 'Holiday', '-' => 'Not marked'],
            'columns' => [
                ['key' => 'employee_code', 'label' => 'Code'],
                ['key' => 'name', 'label' => 'Employee'],
                ['key' => 'present', 'label' => 'P', 'align' => 'right'],
                ['key' => 'half_day', 'label' => 'HD', 'align' => 'right'],
                ['key' => 'absent', 'label' => 'A', 'align' => 'right'],
                ['key' => 'leave', 'label' => 'L', 'align' => 'right'],
                ['key' => 'weekly_off', 'label' => 'WO', 'align' => 'right'],
                ['key' => 'holiday', 'label' => 'H', 'align' => 'right'],
                ['key' => 'payable_days', 'label' => 'Payable', 'align' => 'right', 'emphasis' => true],
            ],
            'rows' => $rows,
        ];
    }

    private function lateEarly(User $user): array
    {
        $rows = collect();
        foreach ($this->grid($user) as $e) {
            foreach ($e['days'] as $date => $d) {
                $a = $d['attendance'];
                if (! $a || (! $a->late_by_minutes && ! $a->early_by_minutes)) {
                    continue;
                }
                $rows->push($this->employeeCells($e['employee']) + [
                    'date' => $date,
                    'shift' => $d['shift'],
                    'shift_start' => $d['shift_start'],
                    'check_in' => $this->time($a->check_in, $e['tz']),
                    'late_minutes' => $a->late_by_minutes ?: null,
                    'shift_end' => $d['shift_end'],
                    'check_out' => $this->time($a->check_out, $e['tz']),
                    'early_minutes' => $a->early_by_minutes ?: null,
                ]);
            }
        }

        return [
            'columns' => [
                ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
                ...$this->employeeColumns(),
                ['key' => 'shift', 'label' => 'Shift'],
                ['key' => 'shift_start', 'label' => 'Shift start'],
                ['key' => 'check_in', 'label' => 'In'],
                ['key' => 'late_minutes', 'label' => 'Late (min)', 'align' => 'right', 'emphasis' => true],
                ['key' => 'shift_end', 'label' => 'Shift end'],
                ['key' => 'check_out', 'label' => 'Out'],
                ['key' => 'early_minutes', 'label' => 'Early exit (min)', 'align' => 'right', 'emphasis' => true],
            ],
            'rows' => $rows->sortBy([['date', 'asc'], ['name', 'asc']])->values(),
            'totals' => [
                'late_minutes' => $rows->sum('late_minutes'),
                'early_minutes' => $rows->sum('early_minutes'),
                'late_count' => $rows->whereNotNull('late_minutes')->count(),
                'early_count' => $rows->whereNotNull('early_minutes')->count(),
            ],
        ];
    }

    private function absent(User $user): array
    {
        $rows = collect();
        foreach ($this->grid($user) as $e) {
            $streak = 0;
            foreach ($e['days'] as $date => $d) {
                if (in_array($d['status'], ['weekly_off', 'holiday'], true)) {
                    continue; // offs don't break or extend a run of absences
                }
                if (! in_array($d['status'], ['absent', 'not_marked'], true)) {
                    $streak = 0;

                    continue;
                }
                $streak++;
                $rows->push($this->employeeCells($e['employee']) + [
                    'date' => $date,
                    'day' => CarbonImmutable::parse($date)->format('D'),
                    'status' => $d['status'],
                    'status_label' => $d['label'],
                    'consecutive' => $streak,
                    'phone' => $e['employee']->phone,
                ]);
            }
        }

        return [
            'columns' => [
                ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'day', 'label' => 'Day'],
                ...$this->employeeColumns(),
                ['key' => 'status_label', 'label' => 'Status', 'type' => 'status', 'status_key' => 'status'],
                ['key' => 'consecutive', 'label' => 'Consecutive days', 'align' => 'right', 'emphasis' => true],
                ['key' => 'phone', 'label' => 'Phone'],
            ],
            'rows' => $rows->sortBy([['date', 'asc'], ['name', 'asc']])->values(),
            'totals' => ['absent_days' => $rows->where('status', 'absent')->count(), 'not_marked_days' => $rows->where('status', 'not_marked')->count(),
                'employees' => $rows->pluck('employee_id')->unique()->count()],
        ];
    }

    private function overtime(User $user): array
    {
        $grid = $this->grid($user);
        $requests = OvertimeRequest::whereIn('employee_id', $grid->keys())
            ->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()])
            ->get()->groupBy('employee_id');

        $rows = $grid->map(function ($e, $id) use ($requests) {
            $records = collect($e['days'])->pluck('attendance')->filter();
            $reqs = $requests->get($id, collect());

            return $this->employeeCells($e['employee']) + [
                'ot_days' => $records->where('overtime_minutes', '>', 0)->count(),
                'computed_hours' => round($records->sum('overtime_minutes') / 60, 2),
                'claimed_hours' => round((float) $reqs->sum('hours'), 2),
                'approved_hours' => round((float) $reqs->where('status', 'approved')->sum('hours'), 2),
                'pending_hours' => round((float) $reqs->where('status', 'pending')->sum('hours'), 2),
            ];
        })->filter(fn ($r) => $r['computed_hours'] > 0 || $r['claimed_hours'] > 0)->values();

        return [
            'columns' => [
                ...$this->employeeColumns(),
                ['key' => 'ot_days', 'label' => 'Days with OT', 'align' => 'right'],
                ['key' => 'computed_hours', 'label' => 'From punches (hrs)', 'align' => 'right'],
                ['key' => 'claimed_hours', 'label' => 'Claimed (hrs)', 'align' => 'right'],
                ['key' => 'approved_hours', 'label' => 'Approved (hrs)', 'align' => 'right', 'emphasis' => true],
                ['key' => 'pending_hours', 'label' => 'Pending (hrs)', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => $this->sumColumns($rows, ['ot_days', 'computed_hours', 'claimed_hours', 'approved_hours', 'pending_hours']),
        ];
    }

    private function department(User $user): array
    {
        $rows = $this->grid($user)->groupBy(fn ($e) => $e['employee']->department?->name ?? 'No department')
            ->map(function ($group, $name) {
                $r = collect($group)->map(fn ($e) => $this->rollup($e['days']));
                $working = $r->sum('working_days');

                return [
                    'department' => $name,
                    'headcount' => count($group),
                    'working_days' => $working,
                    'present' => $r->sum('present'),
                    'absent' => $r->sum('absent'),
                    'leave' => $r->sum('leave'),
                    'late' => $r->sum('late'),
                    'worked_hours' => round($r->sum('worked_hours'), 1),
                    'overtime_hours' => round($r->sum('overtime_hours'), 1),
                    'attendance_pct' => $this->pct($r->sum('present') + $r->sum('half_day') / 2, $working),
                ];
            })->sortBy('department')->values();

        return [
            'columns' => [
                ['key' => 'department', 'label' => 'Department'],
                ['key' => 'headcount', 'label' => 'Employees', 'align' => 'right'],
                ['key' => 'working_days', 'label' => 'Working days', 'align' => 'right'],
                ['key' => 'present', 'label' => 'Present', 'align' => 'right'],
                ['key' => 'absent', 'label' => 'Absent', 'align' => 'right'],
                ['key' => 'leave', 'label' => 'Leave', 'align' => 'right'],
                ['key' => 'late', 'label' => 'Late', 'align' => 'right'],
                ['key' => 'worked_hours', 'label' => 'Worked hrs', 'align' => 'right'],
                ['key' => 'overtime_hours', 'label' => 'OT hrs', 'align' => 'right'],
                ['key' => 'attendance_pct', 'label' => 'Attendance %', 'align' => 'right', 'type' => 'percent', 'emphasis' => true],
            ],
            'rows' => $rows,
            'totals' => $this->sumColumns($rows, ['headcount', 'working_days', 'present', 'absent', 'leave', 'late', 'worked_hours', 'overtime_hours'])
                + ['attendance_pct' => $this->pct($rows->sum('present'), $rows->sum('working_days'))],
        ];
    }

    private function punches(User $user, bool $outsideOnly): array
    {
        $employees = $this->employees($user)->keyBy('id');
        $branches = Branch::whereIn('id', $employees->pluck('branch_id')->unique())->get()->keyBy('id');

        $punches = RawPunch::whereIn('employee_id', $employees->keys())
            ->whereBetween('punched_at', [$this->from->subDay()->toDateTimeString(), $this->to->addDays(2)->toDateTimeString()])
            ->orderBy('punched_at')
            ->get();

        $rows = collect();
        foreach ($punches as $p) {
            $emp = $employees->get($p->employee_id);
            $branch = $branches->get($emp?->branch_id);
            $tz = $branch?->timezone ?: config('app.timezone');
            $local = CarbonImmutable::parse($p->punched_at, 'UTC')->setTimezone($tz);
            if ($local->lt($this->from) || $local->gt($this->to->endOfDay())) {
                continue;
            }

            if ($emp && $branch) {
                $emp->setRelation('branch', $branch);
            }
            $geo = $p->latitude !== null && $emp && $branch ? $this->geoFence->evaluateFor($emp, (float) $p->latitude, (float) $p->longitude) : null;
            $payload = is_array($p->payload) ? $p->payload : (json_decode((string) $p->payload, true) ?: []);
            $outside = ($geo && $geo['enabled'] && $geo['restricted'] && ! $geo['inside']) || ! empty($payload['outside_geofence']);
            if ($outsideOnly && ! $outside) {
                continue;
            }

            $rows->push($this->employeeCells($emp) + [
                'id' => $p->id,
                'punched_at' => $local->format('Y-m-d H:i'),
                'direction' => $p->direction ? strtoupper($p->direction) : '—',
                'source' => $p->source,
                'location' => $p->latitude !== null ? sprintf('%.5f, %.5f', $p->latitude, $p->longitude) : null,
                'map_url' => $p->latitude !== null ? "https://www.google.com/maps?q={$p->latitude},{$p->longitude}" : null,
                'site' => $geo['site'] ?? ($payload['site'] ?? null),
                'distance_m' => $geo['distance_m'] ?? ($payload['distance_m'] ?? null),
                'geofence' => $outside ? 'Outside' : ($geo && $geo['enabled'] ? ($geo['restricted'] ? 'Inside' : 'Exempt') : '—'),
                'offline' => ! empty($payload['captured_offline']) ? 'Yes' : null,
            ]);
        }

        return [
            'columns' => [
                ['key' => 'punched_at', 'label' => 'Time'],
                ...$this->employeeColumns(),
                ['key' => 'direction', 'label' => 'In / out'],
                ['key' => 'source', 'label' => 'Source'],
                ['key' => 'location', 'label' => 'Location', 'type' => 'link', 'href_key' => 'map_url'],
                ['key' => 'site', 'label' => 'Nearest site'],
                ['key' => 'distance_m', 'label' => 'Distance (m)', 'align' => 'right'],
                ['key' => 'geofence', 'label' => 'Geo-fence', 'type' => 'status', 'status_key' => 'geofence'],
                ['key' => 'offline', 'label' => 'Synced offline'],
            ],
            'rows' => $rows->values(),
            'totals' => ['punches' => $rows->count(), 'outside' => $rows->where('geofence', 'Outside')->count()],
        ];
    }

    // ── The day grid ──────────────────────────────────────────────────────

    /** @return Collection<int, Employee> employees in scope who were employed during the period */
    private function employees(User $user): Collection
    {
        return Employee::with(['branch:id,name,timezone,week_off_days', 'department:id,name'])
            ->visibleTo($user)
            ->when(! empty($this->filters['branch_id']), fn ($q) => $q->where('branch_id', $this->filters['branch_id']))
            ->when(! empty($this->filters['department_id']), fn ($q) => $q->where('department_id', $this->filters['department_id']))
            ->when(! empty($this->filters['employee_id']), fn ($q) => $q->where('id', $this->filters['employee_id']))
            ->where(fn ($q) => $q->whereNull('date_of_joining')->orWhere('date_of_joining', '<=', $this->to->toDateString()))
            ->where(fn ($q) => $q->whereNull('date_of_leaving')->orWhere('date_of_leaving', '>=', $this->from->toDateString()))
            ->where(fn ($q) => $q->where('status', 'active')->orWhereIn('id', Attendance::select('employee_id')
                ->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()])))
            ->orderBy('first_name')->orderBy('last_name')
            ->get();
    }

    /**
     * employee_id → {employee, tz, days: date → {status, label, attendance, shift, shift_start, shift_end, leave, note}}
     */
    private function grid(User $user): Collection
    {
        $employees = $this->employees($user);
        if ($employees->isEmpty()) {
            return collect();
        }
        $ids = $employees->pluck('id');
        $range = [$this->from->toDateString(), $this->to->toDateString()];

        $attendance = Attendance::whereIn('employee_id', $ids)->whereBetween('date', $range)->get()
            ->keyBy(fn ($a) => $a->employee_id . '|' . $a->date->toDateString());
        $shifts = Shift::whereIn('id', $attendance->pluck('shift_id')->filter()->unique())->get()->keyBy('id');

        $leaves = Leave::with('leaveType:id,name,paid')->whereIn('employee_id', $ids)->where('status', 'approved')
            ->whereDate('start_date', '<=', $this->to)->whereDate('end_date', '>=', $this->from)->get()->groupBy('employee_id');

        $holidays = Holiday::whereIn('branch_id', $employees->pluck('branch_id')->unique())->get()
            ->groupBy('branch_id')
            ->map(fn ($hs) => $hs->flatMap(function ($h) {
                $dates = [];
                foreach (range($this->from->year, $this->to->year) as $y) {
                    $dates[] = ($h->recurring ? $h->date->copy()->setYear($y) : $h->date)->toDateString();
                    if (! $h->recurring) {
                        break;
                    }
                }

                return collect($dates)->mapWithKeys(fn ($d) => [$d => $h->name]);
            }));

        $today = CarbonImmutable::today()->toDateString();
        $period = collect(CarbonPeriod::create($this->from, $this->to))->map(fn ($d) => CarbonImmutable::instance($d));

        return $employees->mapWithKeys(function (Employee $emp) use ($attendance, $shifts, $leaves, $holidays, $today, $period) {
            $tz = $emp->branch?->timezone ?: config('app.timezone');
            $weekOff = $emp->weekly_off_days ?? $emp->branch?->week_off_days ?? [0, 6];
            $empLeaves = $leaves->get($emp->id, collect());
            $empHolidays = $holidays->get($emp->branch_id, collect());
            $joined = $emp->date_of_joining?->toDateString();
            $left = $emp->date_of_leaving?->toDateString();
            $days = [];

            foreach ($period as $day) {
                $date = $day->toDateString();
                if (($joined && $date < $joined) || ($left && $date > $left)) {
                    continue;
                }
                $a = $attendance->get($emp->id . '|' . $date);
                $leave = $empLeaves->first(fn ($l) => $date >= $l->start_date->toDateString() && $date <= $l->end_date->toDateString());
                $note = null;

                if ($a) {
                    $status = $a->status === 'absent' && $leave ? 'on_leave' : $a->status;
                } elseif ($empHolidays->has($date)) {
                    $status = 'holiday';
                    $note = $empHolidays[$date];
                } elseif (in_array($day->dayOfWeek, array_map('intval', (array) $weekOff), true)) {
                    $status = 'weekly_off';
                } elseif ($leave) {
                    $status = 'on_leave';
                } elseif ($date > $today) {
                    $status = 'upcoming';
                } else {
                    $status = 'not_marked';
                }
                if ($status === 'on_leave' && $leave) {
                    $note = $leave->leaveType?->name . ($leave->half_day_session ? ' (half day)' : '');
                }

                $shift = $a?->shift_id ? $shifts->get($a->shift_id) : null;
                $days[$date] = [
                    'status' => $status,
                    'label' => self::STATUS_LABELS[$status],
                    'attendance' => $a,
                    'leave' => $status === 'on_leave' ? $leave : null,
                    'shift' => $shift?->name,
                    'shift_start' => $shift ? substr((string) $shift->start_time, 0, 5) : null,
                    'shift_end' => $shift ? substr((string) $shift->end_time, 0, 5) : null,
                    'note' => $note,
                ];
            }

            return [$emp->id => ['employee' => $emp, 'tz' => $tz, 'days' => $days]];
        });
    }

    /** Counts and payable days for one employee's resolved days. */
    private function rollup(array $days): array
    {
        $c = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        $leave = $paidLeave = $worked = $ot = 0.0;

        foreach ($days as $d) {
            $c[$d['status']]++;
            if ($d['status'] === 'on_leave') {
                $portion = $d['leave']?->half_day_session ? 0.5 : 1.0;
                $leave += $portion;
                if (! $d['leave'] || $d['leave']->leaveType?->paid) {
                    $paidLeave += $portion;
                }
            }
            $worked += (int) ($d['attendance']?->worked_minutes ?? 0);
            $ot += (int) ($d['attendance']?->overtime_minutes ?? 0);
        }

        $present = $c['present'] + $c['late'];
        $working = $present + $c['half_day'] + $c['absent'] + $c['on_leave'] + $c['not_marked'];

        return [
            'working_days' => $working,
            'present' => $present,
            'late' => $c['late'],
            'half_day' => $c['half_day'],
            'absent' => $c['absent'],
            'leave' => $leave,
            'weekly_off' => $c['weekly_off'],
            'holiday' => $c['holiday'],
            'not_marked' => $c['not_marked'],
            'payable_days' => $present + $c['half_day'] * 0.5 + $c['weekly_off'] + $c['holiday'] + $paidLeave,
            'worked_hours' => round($worked / 60, 1),
            'overtime_hours' => round($ot / 60, 1),
            'attendance_pct' => $this->pct($present + $c['half_day'] / 2, $working),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function employeeColumns(): array
    {
        return [
            ['key' => 'employee_code', 'label' => 'Code'],
            ['key' => 'name', 'label' => 'Employee'],
            ['key' => 'department', 'label' => 'Department'],
        ];
    }

    private function employeeCells(?Employee $e): array
    {
        return [
            'employee_id' => $e?->id,
            'employee_code' => $e?->employee_code,
            'name' => $e?->full_name,
            'department' => $e?->department?->name,
            'branch' => $e?->branch?->name,
        ];
    }

    private function time($value, string $tz): ?string
    {
        return $value ? CarbonImmutable::parse($value, 'UTC')->setTimezone($tz)->format('H:i') : null;
    }

    private function pct(float $part, float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    private function sumColumns(Collection $rows, array $keys): array
    {
        return collect($keys)->mapWithKeys(fn ($k) => [$k => round((float) $rows->sum($k), 2)])->all();
    }

    /** Branches and departments for the filter bar. */
    public static function filterOptions(User $user): array
    {
        return [
            'types' => collect(self::TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'statuses' => collect(self::STATUS_LABELS)->except(['upcoming', 'late'])->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
        ];
    }
}
