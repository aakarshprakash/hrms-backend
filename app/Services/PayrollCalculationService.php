<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\OvertimeRule;
use App\Models\PayrollRun;
use App\Models\PayrollRunAdjustment;
use App\Models\SalaryStructure;
use App\Models\StatutoryRule;
use Carbon\Carbon;

class PayrollCalculationService
{
    public function __construct(
        private OvertimeCalculationService $otService
    ) {}

    /**
     * Compute payroll for one employee on a given payroll_run.
     *
     * @return array{gross_pay: float, total_deductions: float, net_pay: float, breakdown_json: array}
     */
    public function computeForEmployee(Employee $employee, PayrollRun $run): array
    {
        $monthStart = Carbon::create($run->year, $run->month, 1)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        // 1. Get all active salary structures for this period
        $structures = SalaryStructure::where('employee_id', $employee->id)
            ->where('effective_from', '<=', $monthEnd)
            ->where(function ($q) use ($monthStart) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $monthStart);
            })
            ->with('component')
            ->get();

        // Separate earnings and deductions from structure
        $earningsComponents = $structures->filter(fn ($s) => $s->component && $s->component->type === 'earning');
        $deductionComponents = $structures->filter(fn ($s) => $s->component && $s->component->type === 'deduction');

        // Find basic pay for percentage calculations
        $basicPay = 0.0;
        foreach ($earningsComponents as $s) {
            if (strtolower($s->component->name) === 'basic' && $s->component->calculation_type === 'fixed') {
                $basicPay = (float) $s->amount;
                break;
            }
        }
        if ($basicPay === 0.0) {
            // Use first fixed earning as basic
            foreach ($earningsComponents as $s) {
                if ($s->component->calculation_type === 'fixed') {
                    $basicPay = (float) $s->amount;
                    break;
                }
            }
        }

        // 2. Compute gross earnings
        $earningsBreakdown = [];
        $gross = 0.0;
        foreach ($earningsComponents as $s) {
            $amount = $this->resolveAmount($s, $basicPay);
            $gross += $amount;
            $earningsBreakdown[] = [
                'name' => $s->component->name,
                'type' => $s->component->calculation_type,
                'amount' => round($amount, 2),
            ];
        }

        // 3. OT pay for the month
        $otPay = 0.0;
        $approvedOtRequests = OvertimeRequest::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->get();

        $otRule = OvertimeRule::withoutGlobalScopes()
            ->where('branch_id', $employee->branch_id)
            ->first();

        foreach ($approvedOtRequests as $otReq) {
            if ($otRule) {
                $otPay += $this->otService->calculateOtPay($employee, (float) $otReq->hours, $otRule);
            }
        }
        $otPay = round($otPay, 2);

        // 4. Structure deductions
        $structureDeductionsBreakdown = [];
        $structureDeductions = 0.0;
        foreach ($deductionComponents as $s) {
            $amount = $this->resolveAmount($s, $basicPay);
            $structureDeductions += $amount;
            $structureDeductionsBreakdown[] = [
                'name' => $s->component->name,
                'type' => $s->component->calculation_type,
                'amount' => round($amount, 2),
            ];
        }

        // 4.5 One-off adjustments for this specific run (e.g. a sales incentive
        // or an advance recovery that only applies to this month).
        $adjustments = PayrollRunAdjustment::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)
            ->with('component')
            ->get();

        $adjustmentsBreakdown = [];
        foreach ($adjustments as $adjustment) {
            if (! $adjustment->component) {
                continue;
            }
            $amount = (float) $adjustment->amount;
            $entry = [
                'name' => $adjustment->component->name,
                'type' => 'adjustment',
                'amount' => round($amount, 2),
                'note' => $adjustment->note,
            ];
            $adjustmentsBreakdown[] = $entry;

            if ($adjustment->component->type === 'earning') {
                $gross += $amount;
                $earningsBreakdown[] = $entry;
            } else {
                $structureDeductions += $amount;
                $structureDeductionsBreakdown[] = $entry;
            }
        }

        // 5. Statutory deductions from statutory_rules
        $statutoryRules = StatutoryRule::withoutGlobalScopes()
            ->where('branch_id', $employee->branch_id)
            ->where('is_active', true)
            ->get();

        $statutoryBreakdown = [];
        $statutoryTotal = 0.0;
        foreach ($statutoryRules as $rule) {
            $deduction = $this->computeStatutoryDeduction($rule, $gross, $monthStart);
            if ($deduction > 0) {
                $statutoryTotal += $deduction;
                $statutoryBreakdown[] = [
                    'rule_type' => $rule->rule_type,
                    'amount' => round($deduction, 2),
                ];
            }
        }

        $totalDeductions = round($structureDeductions + $statutoryTotal, 2);
        $netPay = round($gross + $otPay - $totalDeductions, 2);

        $breakdownJson = [
            'earnings' => $earningsBreakdown,
            'ot_pay' => $otPay,
            'deductions' => $structureDeductionsBreakdown,
            'statutory_deductions' => $statutoryBreakdown,
            'adjustments' => $adjustmentsBreakdown,
        ];

        return [
            'gross_pay' => round($gross, 2),
            'total_deductions' => $totalDeductions,
            'net_pay' => $netPay,
            'breakdown_json' => $breakdownJson,
        ];
    }

    private function resolveAmount(SalaryStructure $structure, float $basicPay): float
    {
        $amount = (float) $structure->amount;
        if ($structure->component->calculation_type === 'percentage') {
            return ($amount / 100) * $basicPay;
        }
        return $amount;
    }

    private function computeStatutoryDeduction(StatutoryRule $rule, float $gross, Carbon $monthStart): float
    {
        $config = $rule->config_json ?? [];

        // Rates are configured (and shown in the Payroll Settings UI) as
        // whole-number percentages, e.g. 12 for 12%, 0.75 for 0.75% — so we
        // divide by 100 here rather than expecting callers to pass fractions.
        switch ($rule->rule_type) {
            case 'PF':
                $wageCeiling = (float) ($config['wage_ceiling'] ?? 15000);
                $employeeRate = (float) ($config['employee_rate'] ?? 12) / 100;
                $applicableWage = min($gross, $wageCeiling);
                return $applicableWage * $employeeRate;

            case 'ESI':
                $wageCeiling = (float) ($config['wage_ceiling'] ?? 21000);
                $employeeRate = (float) ($config['employee_rate'] ?? 0.75) / 100;
                if ($gross > $wageCeiling) {
                    return 0.0; // ESI not applicable above ceiling
                }
                return $gross * $employeeRate;

            case 'TAX':
                $slabs = $config['slabs'] ?? [];
                return $this->computeTaxForMonth($gross, $slabs);

            default:
                return 0.0;
        }
    }

    /**
     * Compute monthly tax deduction based on annual projection.
     */
    private function computeTaxForMonth(float $monthlyGross, array $slabs): float
    {
        $annualGross = $monthlyGross * 12;
        $annualTax = 0.0;
        $previousLimit = 0.0;

        foreach ($slabs as $slab) {
            $upTo = (float) ($slab['up_to'] ?? 0);
            $rate = (float) ($slab['rate'] ?? 0) / 100;
            if ($annualGross <= $upTo) {
                $annualTax += ($annualGross - $previousLimit) * $rate;
                break;
            }
            $annualTax += ($upTo - $previousLimit) * $rate;
            $previousLimit = $upTo;
        }

        // If above all slabs, apply last slab rate to remainder
        if (!empty($slabs)) {
            $lastSlab = end($slabs);
            if ($annualGross > (float) ($lastSlab['up_to'] ?? 0)) {
                $annualTax += ($annualGross - (float) $lastSlab['up_to']) * ((float) ($lastSlab['rate'] ?? 0) / 100);
            }
        }

        return round($annualTax / 12, 2);
    }
}
