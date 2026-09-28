<?php

namespace App\Services\Payroll;

/**
 * Indian statutory deductions and employer contributions. Pure functions of
 * their inputs (the rule's config + wage figures), so every figure on a
 * payslip can be reproduced and unit-tested. Rates come from each branch's
 * editable statutory rules, falling back to config/statutory.php.
 */
class StatutoryCalculator
{
    /**
     * Provident Fund.
     *
     * @param  float  $pfWage  earned wages of PF-applicable components (basic + DA ...)
     */
    public function pf(float $pfWage, array $config = []): array
    {
        $c = array_merge(config('statutory.pf'), $config);
        $ceiling = (float) $c['wage_ceiling'];

        $wage = ($c['restrict_to_ceiling'] ?? true) ? min($pfWage, $ceiling) : $pfWage;
        $epsWage = min($pfWage, $ceiling);

        $employee = round($wage * (float) $c['employee_rate'] / 100);
        $employerTotal = round($wage * (float) ($c['employer_rate'] ?? 12) / 100);
        $eps = min(round($epsWage * (float) ($c['eps_rate'] ?? 8.33) / 100), round($ceiling * 0.0833));
        $epf = max(0, $employerTotal - $eps);

        return [
            'wage' => round($wage, 2),
            'eps_wage' => round($epsWage, 2),
            'employee' => $employee,
            'employer_epf' => $epf,
            'employer_eps' => $eps,
            'edli' => round($epsWage * (float) ($c['edli_rate'] ?? 0.5) / 100),
            'admin' => round($wage * (float) ($c['admin_rate'] ?? 0.5) / 100),
        ];
    }

    /**
     * Employees' State Insurance. Eligibility is judged on the fixed monthly
     * wage (not reduced by absences); contributions on the wage actually
     * earned, rounded up to the next rupee as ESIC does.
     */
    public function esi(float $fixedMonthlyWage, float $earnedWage, array $config = []): array
    {
        $c = array_merge(config('statutory.esi'), $config);

        if ($fixedMonthlyWage > (float) $c['wage_ceiling'] || $earnedWage <= 0) {
            return ['eligible' => false, 'wage' => 0.0, 'employee' => 0.0, 'employer' => 0.0];
        }

        return [
            'eligible' => true,
            'wage' => round($earnedWage, 2),
            'employee' => (float) ceil($earnedWage * (float) $c['employee_rate'] / 100),
            'employer' => (float) ceil($earnedWage * (float) $c['employer_rate'] / 100),
        ];
    }

    /**
     * Professional tax for the month. Config: {state, basis: monthly|half_yearly,
     * months?: [..], slabs: [[from, to|null, amount]], special_months?: {m: amount},
     * female_slabs?: [...]}.
     */
    public function professionalTax(float $monthlyGross, int $month, array $config, ?string $gender = null): float
    {
        if (empty($config['slabs']) && ! empty($config['state'])) {
            $config = array_merge(config("statutory.pt.{$config['state']}", []), $config);
        }

        $slabs = ($gender === 'female' && ! empty($config['female_slabs'])) ? $config['female_slabs'] : ($config['slabs'] ?? []);
        if (! $slabs) {
            return 0.0;
        }

        $halfYearly = ($config['basis'] ?? 'monthly') === 'half_yearly';
        if ($halfYearly && ! in_array($month, array_map('intval', $config['months'] ?? [9, 3]), true)) {
            return 0.0;
        }

        $income = $halfYearly ? $monthlyGross * 6 : $monthlyGross;
        $amount = 0.0;
        foreach ($slabs as [$from, $to, $value]) {
            if ($income >= (float) $from && ($to === null || $income <= (float) $to)) {
                $amount = (float) $value;
                break;
            }
        }

        $special = $config['special_months'][$month] ?? $config['special_months'][(string) $month] ?? null;
        if ($amount > 0 && ! $halfYearly && $special !== null) {
            $amount = (float) $special;
        }

        return $amount;
    }

    /**
     * Annual income tax on taxable income (after standard deduction and any
     * old-regime deductions), with 87A rebate + marginal relief, surcharge
     * and 4% health & education cess.
     */
    public function annualIncomeTax(float $taxableIncome, string $regime = 'new', array $config = []): float
    {
        $defaults = config('statutory.income_tax');
        $r = array_merge($defaults[$regime] ?? $defaults['new'], $config[$regime] ?? []);
        $income = max(0.0, $taxableIncome);

        $tax = 0.0;
        $lower = 0.0;
        foreach ($r['slabs'] as [$upto, $rate]) {
            $top = $upto === null ? $income : min($income, (float) $upto);
            if ($top > $lower) {
                $tax += ($top - $lower) * (float) $rate / 100;
            }
            if ($upto === null || $income <= (float) $upto) {
                break;
            }
            $lower = (float) $upto;
        }

        // Section 87A rebate, with marginal relief just above the limit.
        if ($income <= (float) $r['rebate_limit']) {
            $tax = max(0.0, $tax - (float) $r['rebate_max']);
        } elseif ($regime === 'new') {
            $tax = min($tax, $income - (float) $r['rebate_limit']);
        }

        $surchargeRate = 0.0;
        foreach ($config['surcharge'] ?? $defaults['surcharge'] as [$upto, $rate]) {
            if ($upto === null || $income <= (float) $upto) {
                $surchargeRate = (float) $rate;
                break;
            }
        }
        $tax += $tax * $surchargeRate / 100;

        return round($tax * (1 + (float) ($config['cess'] ?? $defaults['cess']) / 100));
    }

    /**
     * This month's TDS: the year's projected tax, less TDS already deducted,
     * spread evenly over the months left in the financial year.
     */
    public function monthlyTds(array $input, array $config = []): array
    {
        $regime = $input['regime'] ?? 'new';
        $defaults = config('statutory.income_tax');
        $r = array_merge($defaults[$regime] ?? $defaults['new'], $config[$regime] ?? []);

        $projectedGross = $input['ytd_taxable'] + $input['current_taxable'] + $input['monthly_taxable_fixed'] * max(0, $input['months_remaining'] - 1);
        $deductions = (float) $r['standard_deduction'] + ($regime === 'old' ? (float) ($input['declared_deductions'] ?? 0) : 0.0);
        $taxable = max(0.0, $projectedGross - $deductions);

        $annualTax = $this->annualIncomeTax($taxable, $regime, $config);
        $remaining = max(1, (int) $input['months_remaining']);
        $monthly = max(0.0, round(($annualTax - (float) $input['ytd_tds']) / $remaining));

        return [
            'regime' => $regime,
            'projected_annual_income' => round($projectedGross, 2),
            'deductions' => round($deductions, 2),
            'taxable_income' => round($taxable, 2),
            'annual_tax' => $annualTax,
            'tds_to_date' => round((float) $input['ytd_tds'], 2),
            'months_remaining' => $remaining,
            'amount' => $monthly,
        ];
    }
}
