<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Scopes\BranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Credits leave by each type's policy, and closes out leave years.
 *
 *   annual   the year's entitlement on the first day of the leave year (or
 *            on becoming eligible), pro-rated for joiners by the months left
 *            -- the joining month counts when joined by the 15th;
 *   monthly  days_per_year / 12 on the 1st of each month, the joining month
 *            pro-rated by days; amounts are spread so twelve credits add up
 *            to exactly the annual figure;
 *   none     nothing (comp-off, loss of pay: credited or unlimited by hand).
 *
 * Eligibility waits for min_service_days after joining. At a new leave year
 * the previous balance is carried forward (up to max_carry_forward, for
 * types that carry) and the rest lapses.
 *
 * Everything is idempotent -- one credit per balance per period -- so it is
 * safe to run daily, re-run, or call on demand before showing a balance.
 */
class LeaveAccrualService
{
    public function __construct(private LeaveLedger $ledger, private LeaveYear $years)
    {
    }

    /**
     * Bring every active employee of the current tenant up to date.
     *
     * @param  list<int>|null  $branchIds
     * @return array{employees: int, credits: int}
     */
    public function syncAll(CarbonImmutable $asOf, ?array $branchIds = null): array
    {
        $types = LeaveType::withoutGlobalScope(BranchScope::class)
            ->where('is_active', true)
            ->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->get()
            ->groupBy('branch_id');

        $stats = ['employees' => 0, 'credits' => 0];

        Employee::withoutGlobalScope(BranchScope::class)
            ->where('status', 'active')
            ->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->orderBy('id')
            ->chunkById(200, function ($employees) use ($asOf, $types, &$stats) {
                foreach ($employees as $employee) {
                    $stats['credits'] += $this->syncEmployee($employee, $asOf, $types->get($employee->branch_id, collect()));
                    $stats['employees']++;
                }
            });

        return $stats;
    }

    /** @return int number of credits posted */
    public function syncEmployee(Employee $employee, CarbonImmutable $asOf, ?Collection $types = null): int
    {
        if ($employee->status !== 'active' || ! $employee->company_id) {
            return 0;
        }

        $types ??= LeaveType::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $employee->branch_id)->where('is_active', true)->get();

        if ($types->isEmpty()) {
            return 0;
        }

        $asOf = $asOf->startOfDay();
        $year = $this->years->of($employee->company_id, $asOf);
        [$yearStart, $yearEnd] = $this->years->bounds($employee->company_id, $year);

        // One query for everything this employee has in the two years that matter.
        $balances = LeaveBalance::where('employee_id', $employee->id)
            ->whereIn('year', [$year - 1, $year])
            ->get()
            ->keyBy(fn ($b) => "{$b->leave_type_id}:{$b->year}");

        $joined = $employee->date_of_joining ? CarbonImmutable::parse($employee->date_of_joining)->startOfDay() : null;
        $left = $employee->date_of_leaving ? CarbonImmutable::parse($employee->date_of_leaving)->startOfDay() : null;
        $credits = 0;

        foreach ($types as $type) {
            if (! $type->appliesTo($employee)) {
                continue;
            }

            $previous = $balances->get("{$type->id}:" . ($year - 1));
            if ($previous && ! $previous->closed_at) {
                $this->closeYear($employee, $type, $previous, $year, $yearStart);
            }

            $eligibleFrom = $joined?->addDays((int) $type->min_service_days);
            if ($eligibleFrom && $eligibleFrom->gt($asOf)) {
                continue;
            }

            $current = $balances->get("{$type->id}:{$year}");

            $credits += match ($type->accrual) {
                'annual' => $this->creditAnnual($employee, $type, $current, $year, $yearStart, $yearEnd, $eligibleFrom),
                'monthly' => $this->creditMonthly($employee, $type, $current, $year, $yearStart, $yearEnd, $eligibleFrom, $left, $asOf),
                default => 0,
            };
        }

        return $credits;
    }

    private function creditAnnual(Employee $employee, LeaveType $type, ?LeaveBalance $balance, int $year,
        CarbonImmutable $yearStart, CarbonImmutable $yearEnd, ?CarbonImmutable $eligibleFrom): int
    {
        if ($balance && $balance->accrued_through && $balance->accrued_through->gte($yearEnd)) {
            return 0;
        }

        $days = (float) $type->days_per_year;

        if ($type->prorate_on_joining && $eligibleFrom && $eligibleFrom->gt($yearStart)) {
            $first = $eligibleFrom->day <= 15 ? $eligibleFrom->startOfMonth() : $eligibleFrom->startOfMonth()->addMonth();
            $months = $first->gt($yearEnd) ? 0 : ($yearEnd->year - $first->year) * 12 + ($yearEnd->month - $first->month) + 1;
            $days = round($days * $months / 12 * 2) / 2; // to the nearest half day
        }

        $balance ??= $this->ledger->balanceFor($employee, $type, $year);
        $posted = $days > 0 ? $this->ledger->post($balance, 'accrual', $days, [
            'period' => $yearStart->toDateString(),
            'note' => "Annual entitlement {$this->years->label($employee->company_id, $year)}",
            'created_by' => null,
        ]) : null;

        $balance->accrued_through = $yearEnd;
        $balance->save();

        return $posted ? 1 : 0;
    }

    private function creditMonthly(Employee $employee, LeaveType $type, ?LeaveBalance $balance, int $year,
        CarbonImmutable $yearStart, CarbonImmutable $yearEnd, ?CarbonImmutable $eligibleFrom, ?CarbonImmutable $left,
        CarbonImmutable $asOf): int
    {
        $through = $balance?->accrued_through ? CarbonImmutable::parse($balance->accrued_through) : null;
        $asOfMonthEnd = $asOf->endOfMonth()->startOfDay();

        if ($through && $through->gte(min($asOfMonthEnd, $yearEnd))) {
            return 0; // already credited up to this month
        }

        $rate = (float) $type->days_per_year / 12;
        if ($rate <= 0) {
            return 0;
        }

        $balance ??= $this->ledger->balanceFor($employee, $type, $year);
        $credits = 0;

        for ($month = $yearStart; $month->lte($asOf) && $month->lte($yearEnd); $month = $month->addMonth()) {
            $monthEnd = $month->endOfMonth()->startOfDay();

            if ($through && $monthEnd->lte($through)) {
                continue;
            }
            if ($left && $left->lt($month)) {
                break;
            }
            if ($eligibleFrom && $eligibleFrom->gt($monthEnd)) {
                continue;
            }

            if ($eligibleFrom && $eligibleFrom->gt($month)) {
                // Joined part-way through the month: credit the days from joining.
                $amount = round($rate * ($month->daysInMonth - $eligibleFrom->day + 1) / $month->daysInMonth, 2);
            } else {
                // k-th month of the leave year; cumulative rounding avoids drift.
                $k = ($month->year - $yearStart->year) * 12 + $month->month - $yearStart->month + 1;
                $amount = round($rate * $k, 2) - round($rate * ($k - 1), 2);
            }

            if ($amount > 0 && $this->ledger->post($balance, 'accrual', $amount, [
                'period' => $month->toDateString(),
                'note' => 'Accrued for ' . $month->format('F Y'),
                'created_by' => null,
            ])) {
                $credits++;
            }

            $balance->accrued_through = $monthEnd;
            $balance->save();
        }

        return $credits;
    }

    /**
     * Carry the previous year's unused balance into this one (as far as the
     * policy allows) and lapse the rest. Negative balances are left as they
     * are for HR to settle.
     */
    private function closeYear(Employee $employee, LeaveType $type, LeaveBalance $previous, int $year, CarbonImmutable $yearStart): void
    {
        DB::transaction(function () use ($employee, $type, $previous, $year, $yearStart) {
            $locked = LeaveBalance::whereKey($previous->id)->lockForUpdate()->first();
            if (! $locked || $locked->closed_at) {
                return;
            }

            $remaining = round((float) $locked->balance, 2);
            $period = $yearStart->toDateString();

            if ($remaining > 0) {
                $carry = 0.0;
                if ($type->carry_forward) {
                    $cap = $type->max_carry_forward;
                    $carry = $cap !== null ? min($remaining, (float) $cap) : $remaining;
                }

                if ($carry > 0) {
                    $this->ledger->post($locked, 'carry_forward_out', -$carry, [
                        'period' => $period, 'note' => "Carried forward to {$this->years->label($employee->company_id, $year)}", 'created_by' => null,
                    ]);
                    $this->ledger->post($this->ledger->balanceFor($employee, $type, $year), 'carry_forward', $carry, [
                        'period' => $period, 'note' => 'Carried forward from ' . $this->years->label($employee->company_id, $year - 1), 'created_by' => null,
                    ]);
                }

                $lapse = round($remaining - $carry, 2);
                if ($lapse > 0) {
                    $this->ledger->post($locked, 'lapse', -$lapse, [
                        'period' => $period,
                        'note' => $type->carry_forward ? 'Above the carry-forward limit' : 'Unused at year end',
                        'created_by' => null,
                    ]);
                }
            }

            $locked->closed_at = now();
            $locked->save();
            $previous->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
