<?php

namespace App\Services\Compliance;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Payslip;
use App\Models\TaxDeclaration;
use App\Services\Payroll\StatutoryCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The annual salary and TDS statement for an employee (the details of
 * Form 16 Part B), from the financial year's finalized payslips: salary by
 * month, the deductions allowed, tax on the year's income and TDS deducted
 * quarter by quarter. Part A (the TRACES certificate) comes from TRACES.
 */
class Form16Service
{
    public function __construct(private StatutoryCalculator $calc)
    {
    }

    /** @return array{0: int, 1: int} first month key and last month key (YYYYMM) */
    public function range(string $financialYear, int $fyStartMonth): array
    {
        $start = (int) substr($financialYear, 0, 4);

        return [$start * 100 + $fyStartMonth, ($start + 1) * 100 + $fyStartMonth - 1];
    }

    /** Finalized payslips of the year for these employees. */
    public function payslips(array $employeeIds, string $financialYear, int $fyStartMonth): Collection
    {
        [$from, $to] = $this->range($financialYear, $fyStartMonth);

        return Payslip::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->whereIn('payslips.employee_id', $employeeIds)
            ->whereIn('payroll_runs.status', ['finalized', 'paid'])
            ->whereRaw('(payroll_runs.year * 100 + payroll_runs.month) between ? and ?', [$from, $to])
            ->orderBy('payroll_runs.year')->orderBy('payroll_runs.month')
            ->select('payslips.*', 'payroll_runs.month as run_month', 'payroll_runs.year as run_year')
            ->get();
    }

    public function build(Employee $employee, string $financialYear, ?Company $company): array
    {
        $fyStart = (int) ($company?->fiscal_year_start_month ?: 4);
        $slips = $this->payslips([$employee->id], $financialYear, $fyStart);

        $months = $slips->map(fn ($p) => [
            'label' => CarbonImmutable::create($p->run_year, $p->run_month, 1)->format('M Y'),
            'quarter' => (int) floor(((($p->run_month - $fyStart + 12) % 12)) / 3) + 1,
            'gross' => round((float) $p->gross_pay, 2),
            'taxable' => round((float) ($p->taxable_gross ?: $p->gross_pay), 2),
            'pf' => round((float) $p->pf_employee, 2),
            'pt' => round((float) $p->professional_tax, 2),
            'tds' => round((float) $p->tds, 2),
        ]);

        $declaration = TaxDeclaration::where('employee_id', $employee->id)->where('financial_year', $financialYear)->where('status', 'approved')->first();
        $regime = $declaration?->regime ?? ($employee->tax_regime ?: 'new');
        $cfg = config('statutory.income_tax');
        $grossSalary = round($months->sum('taxable'), 2);
        $standard = min((float) $cfg[$regime]['standard_deduction'], $grossSalary);
        $pt = $regime === 'old' ? round($months->sum('pt'), 2) : 0.0; // 16(iii), old regime only
        $chapterVIA = $regime === 'old' ? (float) ($declaration?->approved_total ?? $employee->declared_deductions ?? 0) : 0.0;
        $taxable = max(0.0, round($grossSalary - $standard - $pt - $chapterVIA, 2));
        $tax = $this->calc->annualIncomeTax($taxable, $regime);
        $tds = round($months->sum('tds'), 2);

        return [
            'financial_year' => $financialYear,
            'assessment_year' => sprintf('%d-%02d', (int) substr($financialYear, 0, 4) + 1, ((int) substr($financialYear, 0, 4) + 2) % 100),
            'employer' => [
                'name' => $company?->legal_name ?: $company?->name,
                'address' => collect([$company?->address_line1, $company?->address_line2, $company?->city, $company?->state, $company?->postal_code])->filter()->implode(', '),
                'pan' => $company?->statutory['pan'] ?? null,
                'tan' => $company?->statutory['tan'] ?? null,
            ],
            'employee' => [
                'name' => $employee->full_name,
                'code' => $employee->employee_code,
                'pan' => $employee->tax_id ? strtoupper($employee->tax_id) : null,
                'designation' => $employee->designation?->title,
                'address' => collect([$employee->address_line1, $employee->city, $employee->state])->filter()->implode(', '),
            ],
            'regime' => $regime,
            'months' => $months->values(),
            'quarters' => collect([1, 2, 3, 4])->map(fn ($q) => [
                'quarter' => "Q{$q}",
                'taxable' => round($months->where('quarter', $q)->sum('taxable'), 2),
                'tds' => round($months->where('quarter', $q)->sum('tds'), 2),
            ]),
            'computation' => [
                'gross_salary' => $grossSalary,
                'standard_deduction' => $standard,
                'professional_tax' => $pt,
                'chapter_via' => $chapterVIA,
                'chapter_via_lines' => $regime === 'old' ? collect($declaration?->items ?? [])->filter(fn ($i) => ($i['eligible'] ?? 0) > 0)->values() : collect(),
                'taxable_income' => $taxable,
                'tax_payable' => $tax,
                'tds_deducted' => $tds,
                'balance' => round($tax - $tds, 2),
            ],
        ];
    }
}
