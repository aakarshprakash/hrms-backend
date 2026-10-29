<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\CoverageRule;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Team coverage: for a team (a coverage rule, or a department with no rule)
 * how many people are scheduled on a day, how many are in, on leave
 * (approved, or still pending for the coming days) and whether that meets
 * the team's minimum.
 *
 *   ok       -- enough people in (or expected)
 *   at_risk  -- not short yet, but expected to be once leave is counted
 *   short    -- fewer than the minimum in after the check time (today), or
 *               expected below it (a coming day)
 */
class CoverageService
{
    public const PRESENT = ['present', 'late', 'half_day'];

    /** Today's teams: every active rule, then departments no rule covers (no minimum). */
    public function today(?int $branchId = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $date = $now->toDateString();
        $rules = CoverageRule::with(['branch:id,name,timezone', 'department:id,name', 'designation:id,title'])
            ->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->orderBy('name')->get();

        $teams = $rules->map(fn (CoverageRule $r) => ['rule' => $r, 'key' => "rule-{$r->id}", 'name' => $r->name,
            'filters' => ['branch_id' => $r->branch_id ?? $branchId, 'department_id' => $r->department_id, 'designation_id' => $r->designation_id]]);

        $covered = $rules->whereNull('designation_id')->pluck('department_id')->filter()->all();
        $departments = Department::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotIn('id', $covered)->orderBy('name')->get(['id', 'name']);
        foreach ($departments as $d) {
            $teams->push(['rule' => null, 'key' => "dept-{$d->id}", 'name' => $d->name, 'filters' => ['branch_id' => $branchId, 'department_id' => $d->id, 'designation_id' => null]]);
        }

        $rows = $teams->map(fn ($t) => $this->snapshot($t['filters'], $date, $t['rule'], $now) + ['key' => $t['key'], 'name' => $t['name']])
            ->filter(fn ($row) => $row['headcount'] > 0)->values();

        return [
            'date' => $date,
            'teams' => $rows,
            'summary' => [
                'short' => $rows->where('status', 'short')->count(),
                'at_risk' => $rows->where('status', 'at_risk')->count(),
                'teams' => $rows->count(),
            ],
        ];
    }

    /** The coming days for every active rule: expected in vs minimum, counting approved and pending leave. */
    public function forecast(?int $branchId = null, int $days = 14, ?CarbonImmutable $from = null): array
    {
        $from ??= CarbonImmutable::today()->addDay();
        $rules = CoverageRule::with(['branch:id,name', 'department:id,name', 'designation:id,title'])->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->orderBy('name')->get();
        $dates = collect(CarbonPeriod::create($from, $from->addDays($days - 1)))->map(fn ($d) => CarbonImmutable::instance($d)->toDateString());

        return [
            'dates' => $dates->values(),
            'rules' => $rules->map(fn (CoverageRule $r) => [
                'rule_id' => $r->id,
                'name' => $r->name,
                'min_present' => $r->min_present,
                'days' => $dates->mapWithKeys(fn ($date) => [$date => collect($this->snapshot(
                    ['branch_id' => $r->branch_id ?? $branchId, 'department_id' => $r->department_id, 'designation_id' => $r->designation_id], $date, $r,
                ))->only(['status', 'scheduled', 'expected', 'on_leave', 'pending_leave', 'off_day', 'people_on_leave'])]),
            ])->values(),
        ];
    }

    /**
     * What approving this leave would do to each team the employee belongs to:
     * the days on which the team drops below its minimum.
     *
     * @return list<array{rule: string, date: string, expected: int, required: int}>
     */
    public function leaveImpact(Leave $leave): array
    {
        $employee = $leave->employee;
        if (! $employee) {
            return [];
        }
        $rules = CoverageRule::where('is_active', true)->get()->filter(fn (CoverageRule $r) => $this->matches($r, $employee));
        $out = [];
        foreach ($rules as $rule) {
            foreach (CarbonPeriod::create($leave->start_date, $leave->end_date) as $day) {
                $date = CarbonImmutable::instance($day)->toDateString();
                $snap = $this->snapshot(['branch_id' => $rule->branch_id, 'department_id' => $rule->department_id, 'designation_id' => $rule->designation_id],
                    $date, $rule, null, $leave->id);
                if ($snap['off_day'] || $snap['scheduled'] === 0) {
                    continue;
                }
                // Expected without this leave, minus this person.
                $expected = $snap['expected'] - ($leave->half_day_session ? 0 : 1);
                if ($expected < $rule->min_present) {
                    $out[] = ['rule' => $rule->name, 'date' => $date, 'expected' => max(0, $expected), 'required' => $rule->min_present];
                }
            }
        }

        return $out;
    }

    public function matches(CoverageRule $rule, Employee $employee): bool
    {
        return (! $rule->branch_id || $rule->branch_id === $employee->branch_id)
            && (! $rule->department_id || $rule->department_id === $employee->department_id)
            && (! $rule->designation_id || $rule->designation_id === $employee->designation_id);
    }

    /**
     * Counts for one team on one day. $ignoreLeaveId leaves a request out
     * (to judge what approving it would change).
     */
    public function snapshot(array $filters, string $date, ?CoverageRule $rule = null, ?CarbonImmutable $now = null, ?int $ignoreLeaveId = null): array
    {
        $members = Employee::with('branch:id,timezone,week_off_days')
            ->where('status', 'active')
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($filters['designation_id'] ?? null, fn ($q, $v) => $q->where('designation_id', $v))
            ->where(fn ($q) => $q->whereNull('date_of_joining')->orWhere('date_of_joining', '<=', $date))
            ->get(['id', 'first_name', 'last_name', 'employee_code', 'branch_id', 'department_id', 'designation_id', 'weekly_off_days']);

        $ids = $members->pluck('id');
        $attendance = Attendance::whereIn('employee_id', $ids)->whereDate('date', $date)->get()->keyBy('employee_id');
        $leaves = Leave::with('leaveType:id,name')->whereIn('employee_id', $ids)->whereIn('status', ['approved', 'pending'])
            ->when($ignoreLeaveId, fn ($q) => $q->where('id', '!=', $ignoreLeaveId))
            ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->get()->groupBy('employee_id');
        $holidays = $this->holidaysOn($members->pluck('branch_id')->unique()->all(), $date);
        $dow = CarbonImmutable::parse($date)->dayOfWeek;

        $counts = ['present' => 0, 'on_leave' => 0, 'pending_leave' => 0, 'off' => 0, 'not_in' => 0];
        $people = ['on_leave' => [], 'not_in' => []];
        foreach ($members as $m) {
            $a = $attendance->get($m->id);
            $leave = $leaves->get($m->id)?->sortBy(fn ($l) => $l->status === 'approved' ? 0 : 1)->first();
            $weekOff = array_map('intval', (array) ($m->weekly_off_days ?? $m->branch?->week_off_days ?? [0]));
            $name = trim("{$m->first_name} {$m->last_name}");

            if ($a && (in_array($a->status, self::PRESENT, true) || $a->check_in)) {
                $counts['present']++;
            } elseif ($leave) {
                $counts[$leave->status === 'approved' ? 'on_leave' : 'pending_leave']++;
                $people['on_leave'][] = ['name' => $name, 'code' => $m->employee_code, 'leave' => $leave->leaveType?->name, 'pending' => $leave->status !== 'approved'];
            } elseif (in_array($m->branch_id, $holidays, true) || in_array($dow, $weekOff, true)) {
                $counts['off']++;
            } else {
                $counts['not_in']++;
                $people['not_in'][] = ['name' => $name, 'code' => $m->employee_code];
            }
        }

        $headcount = $members->count();
        $scheduled = $headcount - $counts['off'];
        $required = $rule?->min_present;
        $today = CarbonImmutable::today()->toDateString();
        $expected = $scheduled - $counts['on_leave'] - $counts['pending_leave'];
        $offDay = $scheduled === 0 || ($headcount > 0 && $counts['off'] >= $headcount / 2 && $counts['present'] === 0);

        $status = 'ok';
        if ($required !== null && ! $offDay) {
            if ($date < $today || ($date === $today && $this->pastCheck($rule, $members->first()?->branch?->timezone, $now))) {
                $status = $counts['present'] >= $required ? 'ok' : 'short';
            } elseif ($expected < $required) {
                $status = $date === $today ? 'at_risk' : 'short';
            } elseif ($expected - $counts['not_in'] < $required && $date === $today) {
                $status = 'at_risk';
            }
        }

        return [
            'rule_id' => $rule?->id,
            'designation_id' => $rule?->designation_id,
            'branch' => $rule?->branch?->name,
            'department' => $rule?->department?->name,
            'designation' => $rule?->designation?->title,
            'min_present' => $required,
            'check_at' => $rule ? substr((string) $rule->check_at, 0, 5) : null,
            'headcount' => $headcount,
            'scheduled' => max(0, $scheduled),
            'present' => $counts['present'],
            'on_leave' => $counts['on_leave'],
            'pending_leave' => $counts['pending_leave'],
            'not_in' => $counts['not_in'],
            'expected' => max(0, $expected),
            'off_day' => $offDay,
            'status' => $status,
            'people_on_leave' => $people['on_leave'],
            'people_not_in' => $people['not_in'],
        ];
    }

    /** Has the rule's check time passed in the branch's timezone? */
    public function pastCheck(?CoverageRule $rule, ?string $tz, ?CarbonImmutable $now = null): bool
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone($tz ?: config('app.timezone'));

        return $local->format('H:i') >= substr((string) ($rule?->check_at ?? '10:30'), 0, 5);
    }

    /** @return list<int> branch ids with a holiday on $date */
    private function holidaysOn(array $branchIds, string $date): array
    {
        $d = CarbonImmutable::parse($date);

        return Holiday::whereIn('branch_id', $branchIds)
            ->where(fn ($q) => $q->whereDate('date', $date)
                ->orWhere(fn ($r) => $r->where('recurring', true)->whereMonth('date', $d->month)->whereDay('date', $d->day)))
            ->pluck('branch_id')->unique()->map(fn ($id) => (int) $id)->all();
    }

    /** Recipients for a short-staffing alert: attendance managers in the branch, plus anyone named on the rule. */
    public function recipients(CoverageRule $rule): Collection
    {
        $users = \App\Models\User::permission('attendance.manage')
            ->when($rule->branch_id, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $rule->branch_id)))
            ->get();
        if ($rule->notify_user_ids) {
            $users = $users->merge(\App\Models\User::whereIn('id', $rule->notify_user_ids)->get());
        }

        return $users->unique('id')->values();
    }
}
