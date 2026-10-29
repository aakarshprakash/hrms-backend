<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\PayrollRunAdjustment;
use App\Models\Payslip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Arrears after a back-dated salary revision: each already-processed month
 * in the range is recomputed with today's salary structure (same attendance,
 * same rules) and compared with what its payslip actually paid. The
 * difference in salary earnings is paid in a later run as two adjustments --
 * the part on PF wages (so PF is deducted on it) and the rest.
 */
class ArrearsService
{
    public function __construct(private PayrollEngine $engine, private BonusService $bonus)
    {
    }

    /** @return array{rows: Collection, totals: array} */
    public function calculate(PayrollRun $target, string $from, string $to, ?array $employeeIds = null): array
    {
        $fromKey = (int) str_replace('-', '', $from);
        $toKey = (int) str_replace('-', '', $to);
        abort_if($toKey < $fromKey, 422, 'The end month must be on or after the start month.');
        abort_unless($toKey < $target->year * 100 + $target->month, 422, 'Arrears are for months before the run that pays them.');

        $runs = PayrollRun::where('branch_id', $target->branch_id)
            ->whereIn('status', ['processed', 'finalized', 'paid'])
            ->whereRaw('(year * 100 + month) between ? and ?', [$fromKey, $toKey])
            ->orderBy('year')->orderBy('month')->get();

        $lines = collect();
        foreach ($runs as $run) {
            $context = $this->engine->contextFor($run);
            $payslips = Payslip::where('payroll_run_id', $run->id)
                ->when($employeeIds, fn ($q) => $q->whereIn('employee_id', $employeeIds))->get();
            $employees = Employee::whereIn('id', $payslips->pluck('employee_id'))->get()->keyBy('id');

            foreach ($payslips as $old) {
                $employee = $employees->get($old->employee_id);
                if (! $employee) {
                    continue;
                }
                $new = $this->engine->compute($employee, $run, $context);
                $paid = $this->structureEarnings($old->breakdown_json['earnings'] ?? []);
                $due = $this->structureEarnings($new['breakdown_json']['earnings'] ?? []);
                $diff = round($due - $paid, 2);
                if (abs($diff) < 1) {
                    continue;
                }
                $pfDiff = round(max(0, (float) $new['pf_wage'] - (float) $old->pf_wage), 2);
                $lines->push([
                    'employee_id' => $employee->id,
                    'month' => CarbonImmutable::create($run->year, $run->month, 1)->format('M Y'),
                    'paid' => $paid, 'due' => $due, 'difference' => $diff,
                    'pf_part' => $diff > 0 ? min($pfDiff, $diff) : 0,
                ]);
            }
        }

        $all = Employee::whereIn('id', $lines->pluck('employee_id')->unique())->get()->keyBy('id');
        $rows = $lines->groupBy('employee_id')->map(function (Collection $months, $id) use ($all) {
            $e = $all->get($id);
            $total = round($months->sum('difference'), 2);

            return [
                'employee_id' => (int) $id,
                'employee_code' => $e?->employee_code,
                'name' => $e?->full_name,
                'months' => $months->values(),
                'month_labels' => $months->pluck('month')->implode(', '),
                'paid' => round($months->sum('paid'), 2),
                'due' => round($months->sum('due'), 2),
                'arrears' => $total,
                'pf_part' => $total > 0 ? round(min($months->sum('pf_part'), $total), 2) : 0,
            ];
        })->sortBy('name')->values();

        return ['rows' => $rows, 'runs' => $runs->count(), 'totals' => [
            'employees' => $rows->count(),
            'arrears' => round($rows->where('arrears', '>', 0)->sum('arrears'), 2),
            'recoveries' => round($rows->where('arrears', '<', 0)->sum('arrears'), 2),
        ]];
    }

    /** Adds positive arrears to the run (replacing earlier arrears adjustments for those people). */
    public function apply(PayrollRun $target, Collection $rows, string $label, User $by): int
    {
        abort_unless(in_array($target->status, ['draft', 'processed'], true), 422, 'Add arrears to a draft or processed payroll run.');
        $pf = $this->bonus->component($target->branch_id, 'ARR_PF', 'Arrears (PF wages)', ['pf_applicable' => true]);
        $other = $this->bonus->component($target->branch_id, 'ARR', 'Arrears (other)');

        return DB::transaction(function () use ($target, $rows, $pf, $other, $label, $by) {
            $count = 0;
            foreach ($rows->where('arrears', '>', 0) as $row) {
                PayrollRunAdjustment::where('payroll_run_id', $target->id)->where('employee_id', $row['employee_id'])
                    ->whereIn('component_id', [$pf->id, $other->id])->delete();
                $rest = round($row['arrears'] - $row['pf_part'], 2);
                foreach ([[$pf, $row['pf_part']], [$other, $rest]] as [$component, $amount]) {
                    if ($amount > 0) {
                        PayrollRunAdjustment::create([
                            'payroll_run_id' => $target->id, 'employee_id' => $row['employee_id'], 'component_id' => $component->id,
                            'amount' => $amount, 'note' => "Arrears {$label}", 'created_by' => $by->id,
                        ]);
                    }
                }
                $count++;
            }

            return $count;
        });
    }

    private function structureEarnings(array $earnings): float
    {
        return round((float) collect($earnings)->where('kind', 'structure')->sum('earned'), 2);
    }
}
