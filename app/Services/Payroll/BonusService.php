<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\PayrollRunAdjustment;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Statutory bonus (Payment of Bonus Act, 1965) for a financial year, from
 * the year's processed payslips:
 *
 *   salary         basic + DA (payslip lines named Basic / Dearness / DA);
 *   eligible       average monthly salary ≤ eligibility ceiling (₹21,000)
 *                  and at least 30 payable days in the year;
 *   bonus          rate % (8.33 – 20) of each month's salary, capped at the
 *                  calculation ceiling (₹7,000 or the minimum wage), pro rata
 *                  for the days paid that month.
 *
 * apply() adds it to a payroll run as a "Bonus" adjustment (replacing an
 * earlier one, so it can be re-applied after corrections).
 */
class BonusService
{
    private const SALARY_LINE = '/basic|dearness|\bda\b/i';

    /** @return array{rows: Collection, totals: array} */
    public function calculate(int $branchId, string $financialYear, array $options): array
    {
        [$startYear] = array_map('intval', explode('-', $financialYear));
        $fyStart = (int) ($options['fy_start_month'] ?? 4);
        $from = $startYear * 100 + $fyStart;
        $to = ($startYear + 1) * 100 + $fyStart - 1;
        $rate = (float) $options['rate'];
        $eligibility = (float) ($options['eligibility_ceiling'] ?? 21000);
        $ceiling = (float) ($options['calculation_ceiling'] ?? 7000);

        $payslips = Payslip::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->where('payroll_runs.branch_id', $branchId)
            ->whereIn('payroll_runs.status', ['processed', 'finalized', 'paid'])
            ->whereRaw('(payroll_runs.year * 100 + payroll_runs.month) between ? and ?', [$from, $to])
            ->select('payslips.*', 'payroll_runs.month', 'payroll_runs.year')
            ->get()
            ->groupBy('employee_id');

        $employees = Employee::whereIn('id', $payslips->keys())->get()->keyBy('id');

        $rows = $payslips->map(function (Collection $slips, $employeeId) use ($employees, $rate, $eligibility, $ceiling) {
            $months = $slips->map(function ($p) use ($ceiling) {
                $lines = collect($p->breakdown_json['earnings'] ?? [])->filter(fn ($e) => preg_match(self::SALARY_LINE, (string) ($e['name'] ?? '')));
                $monthly = (float) $lines->sum(fn ($e) => $e['monthly'] ?? $e['earned'] ?? 0);
                $ratio = $p->days_in_period > 0 ? min(1, (float) $p->payable_days / (float) $p->days_in_period) : 1;

                return ['monthly' => $monthly, 'days' => (float) $p->payable_days, 'bonus_wage' => round(min($monthly, $ceiling) * $ratio, 2)];
            });

            $avg = $months->avg('monthly') ?: 0;
            $days = $months->sum('days');
            $reason = $avg > $eligibility ? 'Salary above ₹' . number_format($eligibility) . '/month'
                : ($days < 30 ? 'Fewer than 30 working days' : null);
            $wages = round($months->sum('bonus_wage'), 2);

            $e = $employees->get($employeeId);

            return [
                'employee_id' => (int) $employeeId,
                'employee_code' => $e?->employee_code,
                'name' => $e?->full_name,
                'months' => $months->count(),
                'payable_days' => $days,
                'avg_monthly_salary' => round($avg, 2),
                'bonus_wages' => $wages,
                'eligible' => $reason === null,
                'reason' => $reason,
                'bonus' => $reason === null ? round($wages * $rate / 100) : 0,
            ];
        })->sortBy('name')->values();

        return ['rows' => $rows, 'totals' => [
            'employees' => $rows->count(),
            'eligible' => $rows->where('eligible', true)->count(),
            'bonus' => $rows->sum('bonus'),
        ]];
    }

    public function apply(PayrollRun $run, Collection $rows, string $financialYear, float $rate, User $by): int
    {
        abort_unless(in_array($run->status, ['draft', 'processed'], true), 422, 'Add the bonus to a draft or processed payroll run.');
        $component = $this->component($run->branch_id, 'BONUS', 'Statutory bonus', ['pf_applicable' => false, 'esi_applicable' => false]);

        return DB::transaction(function () use ($run, $rows, $component, $financialYear, $rate, $by) {
            PayrollRunAdjustment::where('payroll_run_id', $run->id)->where('component_id', $component->id)->delete();
            $count = 0;
            foreach ($rows->where('bonus', '>', 0) as $row) {
                PayrollRunAdjustment::create([
                    'payroll_run_id' => $run->id, 'employee_id' => $row['employee_id'], 'component_id' => $component->id,
                    'amount' => $row['bonus'], 'note' => "Bonus FY {$financialYear} @ {$rate}%", 'created_by' => $by->id,
                ]);
                $count++;
            }

            return $count;
        });
    }

    /** The branch's component for an automatic adjustment, created on first use. */
    public function component(int $branchId, string $code, string $name, array $flags = []): SalaryComponent
    {
        return SalaryComponent::firstOrCreate(['branch_id' => $branchId, 'code' => $code], array_merge([
            'name' => $name, 'type' => 'earning', 'calculation_type' => 'fixed', 'default_value' => 0,
            'is_basic' => false, 'pf_applicable' => false, 'esi_applicable' => true, 'taxable' => true,
            'prorate' => false, 'is_variable' => true, 'display_order' => 90, 'is_active' => true,
        ], $flags));
    }
}
