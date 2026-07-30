<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Powers the dashboard HR calendar: a month grid of holidays, birthdays,
 * work anniversaries and approved leave, plus a short "what's coming up"
 * list for the sidebar widgets. Branch scoping is handled automatically by
 * each model's global scope, so this controller just assembles the data.
 */
class CalendarController extends Controller
{
    public function events(Request $request): JsonResponse
    {
        $month = $request->integer('month') ?: now()->month;
        $year = $request->integer('year') ?: now()->year;
        $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;

        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $employees = Employee::where('status', 'active')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'first_name', 'last_name', 'date_of_birth', 'date_of_joining', 'branch_id']);

        $holidays = Holiday::when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'name', 'date', 'recurring', 'branch_id'])
            ->filter(function ($h) use ($monthStart, $monthEnd) {
                $d = $h->recurring ? $h->date->copy()->setYear($monthStart->year) : $h->date;
                return $d->between($monthStart, $monthEnd);
            });

        $leaves = Leave::where('status', 'approved')
            ->whereDate('start_date', '<=', $monthEnd)
            ->whereDate('end_date', '>=', $monthStart)
            ->when($branchId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('branch_id', $branchId)))
            ->with('employee:id,first_name,last_name')
            ->get();

        // Display-only: resolve the branch's work-week pattern when a single
        // branch is selected; an all-branches view has no single pattern to
        // resolve, so it falls back to the classic Sat+Sun highlighting.
        $branch = $branchId ? Branch::find($branchId) : null;

        $days = [];
        for ($d = $monthStart->copy(); $d->lte($monthEnd); $d->addDay()) {
            $key = $d->toDateString();
            $days[$key] = [
                'date' => $key,
                'is_weekend' => $branch ? !$branch->isWorkingDay($d) : $d->isWeekend(),
                'holidays' => [],
                'birthdays' => [],
                'anniversaries' => [],
                'on_leave' => [],
            ];
        }

        foreach ($holidays as $h) {
            $key = ($h->recurring ? $h->date->copy()->setYear($year) : $h->date)->toDateString();
            if (isset($days[$key])) {
                $days[$key]['holidays'][] = ['id' => $h->id, 'name' => $h->name];
            }
        }

        foreach ($employees as $emp) {
            if ($emp->date_of_birth) {
                $bday = $emp->date_of_birth->copy()->setYear($year);
                if (isset($days[$bday->toDateString()])) {
                    $days[$bday->toDateString()]['birthdays'][] = [
                        'employee_id' => $emp->id,
                        'name' => trim("{$emp->first_name} {$emp->last_name}"),
                    ];
                }
            }
            if ($emp->date_of_joining) {
                $anniv = $emp->date_of_joining->copy()->setYear($year);
                $years = $year - $emp->date_of_joining->year;
                if ($years > 0 && isset($days[$anniv->toDateString()])) {
                    $days[$anniv->toDateString()]['anniversaries'][] = [
                        'employee_id' => $emp->id,
                        'name' => trim("{$emp->first_name} {$emp->last_name}"),
                        'years' => $years,
                    ];
                }
            }
        }

        foreach ($leaves as $leave) {
            $from = $leave->start_date->max($monthStart);
            $to = $leave->end_date->min($monthEnd);
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $key = $d->toDateString();
                if (isset($days[$key]) && $leave->employee) {
                    $days[$key]['on_leave'][] = [
                        'employee_id' => $leave->employee->id,
                        'name' => trim("{$leave->employee->first_name} {$leave->employee->last_name}"),
                    ];
                }
            }
        }

        // Upcoming widgets: next 60 days, independent of which month is being browsed
        $today = now()->startOfDay();
        $horizon = $today->copy()->addDays(60);

        $upcomingBirthdays = $employees
            ->filter(fn ($e) => $e->date_of_birth)
            ->map(function ($e) use ($today) {
                $next = $e->date_of_birth->copy()->setYear($today->year);
                if ($next->lt($today)) $next = $next->addYear();
                return ['employee_id' => $e->id, 'name' => trim("{$e->first_name} {$e->last_name}"), 'date' => $next->toDateString(), 'days_away' => $today->diffInDays($next)];
            })
            ->filter(fn ($e) => $e['days_away'] <= 60)
            ->sortBy('days_away')
            ->take(8)
            ->values();

        $upcomingAnniversaries = $employees
            ->filter(fn ($e) => $e->date_of_joining && $e->date_of_joining->lt($today))
            ->map(function ($e) use ($today) {
                $next = $e->date_of_joining->copy()->setYear($today->year);
                if ($next->lt($today)) $next = $next->addYear();
                $years = $next->year - $e->date_of_joining->year;
                return ['employee_id' => $e->id, 'name' => trim("{$e->first_name} {$e->last_name}"), 'date' => $next->toDateString(), 'days_away' => $today->diffInDays($next), 'years' => $years];
            })
            ->filter(fn ($e) => $e['days_away'] <= 60 && $e['years'] > 0)
            ->sortBy('days_away')
            ->take(8)
            ->values();

        $upcomingHolidays = Holiday::when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'name', 'date', 'recurring'])
            ->map(function ($h) use ($today) {
                $next = $h->recurring ? $h->date->copy()->setYear($today->year) : $h->date->copy();
                if ($h->recurring && $next->lt($today)) $next = $next->addYear();
                return ['id' => $h->id, 'name' => $h->name, 'date' => $next->toDateString(), 'next' => $next];
            })
            ->filter(fn ($h) => $h['next']->gte($today))
            ->map(fn ($h) => ['id' => $h['id'], 'name' => $h['name'], 'date' => $h['date'], 'days_away' => $today->diffInDays($h['next'])])
            ->filter(fn ($h) => $h['days_away'] <= 60)
            ->sortBy('days_away')
            ->values();

        $onLeaveToday = Leave::where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->when($branchId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('branch_id', $branchId)))
            ->with('employee:id,first_name,last_name')
            ->get()
            ->map(fn ($l) => ['employee_id' => $l->employee?->id, 'name' => $l->employee ? trim("{$l->employee->first_name} {$l->employee->last_name}") : 'Unknown', 'end_date' => $l->end_date->toDateString()])
            ->values();

        return response()->json([
            'data' => [
                'month' => $month,
                'year' => $year,
                'days' => array_values($days),
                'upcoming_birthdays' => $upcomingBirthdays,
                'upcoming_anniversaries' => $upcomingAnniversaries,
                'upcoming_holidays' => $upcomingHolidays,
                'on_leave_today' => $onLeaveToday,
            ],
        ]);
    }
}
