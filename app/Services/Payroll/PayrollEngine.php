<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\OvertimeRule;
use App\Models\PayrollRun;
use App\Models\PayrollRunAdjustment;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Scopes\BranchScope;
use App\Models\StatutoryRule;
use App\Services\OvertimeCalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Computes one employee's pay for one payroll run.
 *
 *   earnings     structure lines (fixed, % of basic, % of gross), pro-rated
 *                by payable days where the component is attendance-linked;
 *                plus approved overtime and one-off adjustments
 *   statutory    PF, ESI, professional tax, TDS -- per the branch's rules
 *   employer     PF (EPF + EPS), EDLI, admin, ESI -> cost to company
 *
 * Output is plain data (payslip columns + a versioned breakdown), so the same
 * computation serves preview, processing and tests.
 */
class PayrollEngine
{
    public function __construct(
        private readonly AttendanceSummarizer $attendance,
        private readonly StatutoryCalculator $statutory,
        private readonly OvertimeCalculationService $overtime,
    ) {
    }

    /** Rules and settings shared by every employee of a run. */
    public function contextFor(PayrollRun $run): array
    {
        $rules = StatutoryRule::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $run->branch_id)
            ->where('is_active', true)
            ->get()
            ->keyBy('rule_type');

        // Tenant scope resolves the run's own company in request, support-mode and console contexts alike.
        $company = Company::find($run->company_id);

        return [
            'rules' => $rules,
            'ot_rule' => OvertimeRule::withoutGlobalScope(BranchScope::class)->where('branch_id', $run->branch_id)->first(),
            'fy_start_month' => (int) ($company?->fiscal_year_start_month ?: 4),
        ];
    }

    public function compute(Employee $employee, PayrollRun $run, ?array $context = null): array
    {
        $context ??= $this->contextFor($run);
        $start = CarbonImmutable::create($run->year, $run->month, 1)->startOfDay();
        $end = $start->endOfMonth()->startOfDay();
        $branch = $employee->branch;
        $warnings = [];

        $att = $this->attendance->summarize($employee, $branch, $start, $end);
        $proration = $att['proration'];

        // ── Structure lines in effect (latest per component) ─────────────
        $lines = SalaryStructure::with('component')
            ->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start->toDateString()))
            ->orderByDesc('effective_from')
            ->get()
            ->filter(fn ($s) => $s->component && $s->component->is_active !== false)
            ->unique('component_id')
            ->sortBy(fn ($s) => [$s->component->display_order ?? 100, $s->component->id])
            ->values();

        if ($lines->isEmpty()) {
            $warnings[] = 'No salary structure in effect for this period.';
        }

        $earningLines = $lines->where('component.type', 'earning');
        $basicMonthly = $this->basicMonthly($earningLines);

        // ── Earnings ─────────────────────────────────────────────────────
        $earnings = [];
        $monthlyGrossBase = 0.0;
        foreach ($earningLines->reject(fn ($s) => $this->isPercentOfGross($s)) as $line) {
            $monthly = $this->monthlyAmount($line, $basicMonthly, 0.0);
            $monthlyGrossBase += $monthly;
            $earnings[] = $this->earningEntry($line, $monthly, $proration);
        }
        foreach ($earningLines->filter(fn ($s) => $this->isPercentOfGross($s)) as $line) {
            $earnings[] = $this->earningEntry($line, $this->monthlyAmount($line, $basicMonthly, $monthlyGrossBase), $proration);
        }

        // Approved overtime.
        $otPay = 0.0;
        if ($context['ot_rule'] && $att['ot_hours_approved'] > 0) {
            $otPay = $this->overtime->calculateOtPay($employee, $att['ot_hours_approved'], $context['ot_rule']);
            if ($otPay > 0) {
                $earnings[] = ['code' => 'OT', 'name' => 'Overtime', 'monthly' => null, 'earned' => $otPay, 'kind' => 'overtime',
                    'pf' => false, 'esi' => true, 'taxable' => true, 'note' => "{$att['ot_hours_approved']} h approved"];
            }
        }

        // One-off adjustments for this run.
        $deductions = [];
        $adjustments = PayrollRunAdjustment::with('component')
            ->where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)
            ->get();
        foreach ($adjustments as $adj) {
            if (! $adj->component) {
                continue;
            }
            $entry = ['code' => $adj->component->code, 'name' => $adj->component->name, 'amount' => round((float) $adj->amount, 2), 'kind' => 'adjustment', 'note' => $adj->note];
            if ($adj->component->type === 'earning') {
                $earnings[] = $entry + ['monthly' => null, 'earned' => $entry['amount'], 'pf' => (bool) $adj->component->pf_applicable,
                    'esi' => (bool) $adj->component->esi_applicable, 'taxable' => (bool) $adj->component->taxable];
            } else {
                $deductions[] = $entry;
            }
        }

        // Structure deductions (loan EMI, advance recovery...).
        foreach ($lines->where('component.type', 'deduction') as $line) {
            $monthly = $this->monthlyAmount($line, $basicMonthly, $monthlyGrossBase);
            $amount = $line->component->prorate ? round($monthly * $proration, 2) : round($monthly, 2);
            if ($amount > 0) {
                $deductions[] = ['code' => $line->component->code, 'name' => $line->component->name, 'amount' => $amount, 'kind' => 'structure'];
            }
        }

        $gross = round(array_sum(array_column($earnings, 'earned')), 2);

        // ── Statutory ────────────────────────────────────────────────────
        $rules = $context['rules'];
        $stat = ['pf' => null, 'esi' => null, 'pt' => null, 'lwf' => null, 'tds' => null];

        if ($rules->has('PF') && ! $employee->pf_opted_out) {
            $pfWage = array_sum(array_map(fn ($e) => $e['pf'] ? $e['earned'] : 0, $earnings));
            if ($pfWage <= 0 && $basicMonthly > 0) {
                $pfWage = $basicMonthly * $proration; // no component flagged: basic
            }
            $stat['pf'] = $this->statutory->pf($pfWage, $rules['PF']->config_json ?? []);
            if ($stat['pf']['employee'] > 0) {
                $deductions[] = ['code' => 'PF', 'name' => 'Provident Fund', 'amount' => $stat['pf']['employee'], 'kind' => 'statutory'];
            }
        }

        if ($rules->has('ESI')) {
            $fixedEsiWage = array_sum(array_map(fn ($e) => $e['esi'] && ($e['kind'] ?? '') === 'structure' && empty($e['variable']) ? (float) $e['monthly'] : 0, $earnings));
            $earnedEsiWage = array_sum(array_map(fn ($e) => $e['esi'] ? $e['earned'] : 0, $earnings));
            $stat['esi'] = $this->statutory->esi($fixedEsiWage, $earnedEsiWage, $rules['ESI']->config_json ?? []);
            if ($stat['esi']['employee'] > 0) {
                $deductions[] = ['code' => 'ESI', 'name' => 'ESI', 'amount' => $stat['esi']['employee'], 'kind' => 'statutory'];
            }
        }

        if ($rules->has('PT') && ! $employee->pt_exempt) {
            $pt = $this->statutory->professionalTax($gross, $run->month, $rules['PT']->config_json ?? [], $employee->gender);
            $stat['pt'] = ['amount' => $pt, 'state' => $rules['PT']->config_json['state'] ?? null];
            if ($pt > 0) {
                $deductions[] = ['code' => 'PT', 'name' => 'Professional Tax', 'amount' => $pt, 'kind' => 'statutory'];
            }
        }

        if ($rules->has('LWF')) {
            $lwf = $rules['LWF']->config_json ?? [];
            $amount = (float) ($lwf['employee_amount'] ?? 0);
            if ($amount > 0 && in_array($run->month, array_map('intval', $lwf['months'] ?? [6, 12]), true)) {
                $stat['lwf'] = ['employee' => $amount, 'employer' => (float) ($lwf['employer_amount'] ?? 0)];
                $deductions[] = ['code' => 'LWF', 'name' => 'Labour Welfare Fund', 'amount' => $amount, 'kind' => 'statutory'];
            }
        }

        $taxableGross = round(array_sum(array_map(fn ($e) => $e['taxable'] ? $e['earned'] : 0, $earnings)), 2);

        if ($rules->has('TAX')) {
            $stat['tds'] = $this->tds($employee, $run, $rules['TAX']->config_json ?? [], $taxableGross, $earnings, $context['fy_start_month']);
            if ($stat['tds']['amount'] > 0) {
                $deductions[] = ['code' => 'TDS', 'name' => 'Income Tax (TDS)', 'amount' => $stat['tds']['amount'], 'kind' => 'statutory'];
            }
        }

        $totalDeductions = round(array_sum(array_column($deductions, 'amount')), 2);
        $net = round($gross - $totalDeductions, 2);
        if ($net < 0) {
            $warnings[] = 'Deductions exceed earnings: net pay is negative.';
        }

        $employer = [];
        if ($stat['pf']) {
            $employer[] = ['name' => 'PF — EPF (employer)', 'amount' => $stat['pf']['employer_epf']];
            $employer[] = ['name' => 'PF — EPS (employer)', 'amount' => $stat['pf']['employer_eps']];
            $employer[] = ['name' => 'EDLI', 'amount' => $stat['pf']['edli']];
            $employer[] = ['name' => 'PF admin charges', 'amount' => $stat['pf']['admin']];
        }
        if ($stat['esi'] && $stat['esi']['employer'] > 0) {
            $employer[] = ['name' => 'ESI (employer)', 'amount' => $stat['esi']['employer']];
        }
        if ($stat['lwf'] && $stat['lwf']['employer'] > 0) {
            $employer[] = ['name' => 'Labour Welfare Fund (employer)', 'amount' => $stat['lwf']['employer']];
        }
        $employerCost = round($gross + array_sum(array_column($employer, 'amount')), 2);

        return [
            'gross_pay' => $gross,
            'total_deductions' => $totalDeductions,
            'net_pay' => $net,
            'currency_code' => $branch?->currency_code ?? 'INR',
            'days_in_period' => $att['basis_days'],
            'payable_days' => $att['payable_days'],
            'lop_days' => $att['lop_days'],
            'taxable_gross' => $taxableGross,
            'pf_wage' => $stat['pf']['wage'] ?? 0,
            'pf_employee' => $stat['pf']['employee'] ?? 0,
            'pf_employer' => $stat['pf']['employer_epf'] ?? 0,
            'eps_employer' => $stat['pf']['employer_eps'] ?? 0,
            'esi_wage' => $stat['esi']['wage'] ?? 0,
            'esi_employee' => $stat['esi']['employee'] ?? 0,
            'esi_employer' => $stat['esi']['employer'] ?? 0,
            'professional_tax' => $stat['pt']['amount'] ?? 0,
            'tds' => $stat['tds']['amount'] ?? 0,
            'employer_cost' => $employerCost,
            'breakdown_json' => [
                'version' => 2,
                'attendance' => $att,
                'earnings' => array_map(fn ($e) => array_intersect_key($e, array_flip(['code', 'name', 'monthly', 'earned', 'kind', 'note', 'prorated'])), $earnings),
                'deductions' => $deductions,
                'statutory' => $stat,
                'employer_contributions' => $employer,
                'totals' => ['gross' => $gross, 'deductions' => $totalDeductions, 'net' => $net, 'employer_cost' => $employerCost],
                'warnings' => $warnings,
                // v1 keys, for anything still reading the old shape
                'ot_pay' => $otPay,
                'lop' => ['days' => $att['lop_days'], 'per_day_rate' => null, 'amount' => null],
            ],
            'warnings' => $warnings,
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function basicMonthly(Collection $earningLines): float
    {
        $basic = $earningLines->first(fn ($s) => $s->component->is_basic && $s->component->calculation_type === 'fixed')
            ?? $earningLines->first(fn ($s) => str_starts_with(strtolower($s->component->name), 'basic') && $s->component->calculation_type === 'fixed')
            ?? $earningLines->first(fn ($s) => $s->component->calculation_type === 'fixed');

        return $basic ? (float) $basic->amount : 0.0;
    }

    private function isPercentOfGross(SalaryStructure $line): bool
    {
        return $line->component->calculation_type === 'percentage' && $line->component->percentage_of === 'gross';
    }

    private function monthlyAmount(SalaryStructure $line, float $basicMonthly, float $grossBase): float
    {
        if ($line->component->calculation_type !== 'percentage') {
            return (float) $line->amount;
        }

        $base = $line->component->percentage_of === 'gross' ? $grossBase : $basicMonthly;

        return round($base * (float) $line->amount / 100, 2);
    }

    private function earningEntry(SalaryStructure $line, float $monthly, float $proration): array
    {
        $c = $line->component;
        $earned = $c->prorate ? round($monthly * $proration, 2) : round($monthly, 2);

        return [
            'code' => $c->code,
            'name' => $c->name,
            'monthly' => round($monthly, 2),
            'earned' => $earned,
            'kind' => 'structure',
            'prorated' => (bool) $c->prorate,
            'variable' => (bool) $c->is_variable,
            'pf' => (bool) $c->pf_applicable,
            'esi' => (bool) $c->esi_applicable,
            'taxable' => (bool) $c->taxable,
        ];
    }

    private function tds(Employee $employee, PayrollRun $run, array $config, float $taxableGross, array $earnings, int $fyStartMonth): array
    {
        // Rules configured before the income-tax engine carry custom slabs:
        // keep computing them exactly as they always were.
        if (! empty($config['slabs']) && ($config['mode'] ?? 'custom_slabs') === 'custom_slabs') {
            return ['mode' => 'custom_slabs', 'amount' => $this->legacySlabTax($taxableGross, $config['slabs'])];
        }

        $fyStartYear = $run->month >= $fyStartMonth ? $run->year : $run->year - 1;
        $monthsRemaining = (($fyStartMonth + 12 - $run->month - 1) % 12) + 1;
        $fyStartKey = $fyStartYear * 100 + $fyStartMonth;
        $runKey = $run->year * 100 + $run->month;

        $ytd = Payslip::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->where('payslips.employee_id', $employee->id)
            ->where('payslips.payroll_run_id', '!=', $run->id)
            ->whereIn('payroll_runs.status', ['processed', 'finalized', 'paid'])
            ->whereRaw('(payroll_runs.year * 100 + payroll_runs.month) >= ?', [$fyStartKey])
            ->whereRaw('(payroll_runs.year * 100 + payroll_runs.month) < ?', [$runKey])
            ->selectRaw('COALESCE(SUM(payslips.taxable_gross), 0) as taxable, COALESCE(SUM(payslips.tds), 0) as tds')
            ->first();

        $monthlyFixed = array_sum(array_map(
            fn ($e) => ($e['kind'] ?? '') === 'structure' && $e['taxable'] && empty($e['variable']) ? (float) $e['monthly'] : 0,
            $earnings
        ));

        return ['mode' => 'income_tax'] + $this->statutory->monthlyTds([
            'regime' => $employee->tax_regime ?: 'new',
            'ytd_taxable' => (float) $ytd->taxable,
            'ytd_tds' => (float) $ytd->tds,
            'current_taxable' => $taxableGross,
            'monthly_taxable_fixed' => $monthlyFixed,
            'months_remaining' => $monthsRemaining,
            'declared_deductions' => (float) $employee->declared_deductions,
        ], $config);
    }

    /** The original annualised-slab method (no deductions, rebate or cess). */
    private function legacySlabTax(float $monthlyGross, array $slabs): float
    {
        $annual = $monthlyGross * 12;
        $tax = 0.0;
        $previous = 0.0;

        foreach ($slabs as $slab) {
            $upTo = (float) ($slab['up_to'] ?? 0);
            $rate = (float) ($slab['rate'] ?? 0) / 100;
            if ($annual <= $upTo) {
                $tax += ($annual - $previous) * $rate;
                break;
            }
            $tax += ($upTo - $previous) * $rate;
            $previous = $upTo;
        }

        if ($slabs) {
            $last = end($slabs);
            if ($annual > (float) ($last['up_to'] ?? 0)) {
                $tax += ($annual - (float) $last['up_to']) * ((float) ($last['rate'] ?? 0) / 100);
            }
        }

        return round($tax / 12, 2);
    }
}
