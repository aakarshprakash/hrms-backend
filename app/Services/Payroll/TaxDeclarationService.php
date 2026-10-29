<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\SalaryStructure;
use App\Models\TaxDeclaration;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Income-tax declarations. An employee picks a regime and declares
 * investments / expenses by section; caps are applied per section; HR
 * approves, which sets the employee's regime and declared deductions --
 * what the payroll TDS projection already uses (old regime only; the new
 * regime allows the standard deduction alone).
 */
class TaxDeclarationService
{
    /** Sections under the old regime: label, annual cap (null = no cap), hint. */
    public const SECTIONS = [
        '80C' => ['label' => 'Section 80C', 'cap' => 150000, 'hint' => 'EPF (own), PPF, ELSS, life insurance, tuition fees, home-loan principal, 5-yr FD, NSC, SSY'],
        '80CCD1B' => ['label' => 'Section 80CCD(1B) — NPS', 'cap' => 50000, 'hint' => 'Your own additional NPS contribution'],
        '80D_SELF' => ['label' => 'Section 80D — health insurance (self, spouse, children)', 'cap' => 25000, 'senior_cap' => 50000, 'hint' => 'Premiums and preventive check-ups'],
        '80D_PARENTS' => ['label' => 'Section 80D — health insurance (parents)', 'cap' => 25000, 'senior_cap' => 50000, 'hint' => '₹50,000 if a parent is a senior citizen'],
        '24B' => ['label' => 'Section 24(b) — home-loan interest', 'cap' => 200000, 'hint' => 'Self-occupied property'],
        '80E' => ['label' => 'Section 80E — education-loan interest', 'cap' => null, 'hint' => 'No upper limit'],
        '80G' => ['label' => 'Section 80G — donations', 'cap' => null, 'hint' => 'Eligible amount of donations to approved funds'],
        '80TTA' => ['label' => 'Section 80TTA — savings-account interest', 'cap' => 10000, 'hint' => ''],
        'HRA' => ['label' => 'HRA exemption — rent paid (annual)', 'cap' => null, 'hint' => 'Enter yearly rent; the exemption is worked out from your basic and HRA'],
        'OTHER' => ['label' => 'Other deductions (80U, 80DD, 80EEA…)', 'cap' => null, 'hint' => 'Describe in the note'],
    ];

    public function financialYear(?Company $company, ?CarbonImmutable $on = null): string
    {
        $on ??= CarbonImmutable::now();
        $start = (int) ($company?->fiscal_year_start_month ?: 4);
        $year = $on->month >= $start ? $on->year : $on->year - 1;

        return sprintf('%d-%02d', $year, ($year + 1) % 100);
    }

    /** Annual basic and HRA from the salary structure in effect today. */
    public function annualSalary(Employee $employee): array
    {
        $lines = SalaryStructure::with('component')->where('employee_id', $employee->id)
            ->where('effective_from', '<=', now()->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
            ->orderByDesc('effective_from')->get()
            ->filter(fn ($s) => $s->component && $s->component->type === 'earning')
            ->unique('component_id');

        $basicLine = $lines->first(fn ($s) => $s->component->is_basic) ?? $lines->first(fn ($s) => stripos($s->component->name, 'basic') !== false);
        $basic = $basicLine ? (float) $basicLine->amount : 0.0;
        $monthly = fn ($s) => $s->component->calculation_type === 'percentage' && $s->component->percentage_of !== 'gross'
            ? $basic * (float) $s->amount / 100 : (float) $s->amount;
        $hraLine = $lines->first(fn ($s) => preg_match('/\bhra\b|house rent/i', $s->component->name));
        $taxable = $lines->filter(fn ($s) => $s->component->taxable)->sum($monthly);

        return [
            'basic' => round($basic * 12, 2),
            'hra' => round($hraLine ? $monthly($hraLine) * 12 : 0, 2),
            'gross' => round($taxable * 12, 2),
        ];
    }

    /** Section-wise eligible amounts after caps, the HRA exemption, and the total. */
    public function evaluate(Employee $employee, array $items, bool $metro = false): array
    {
        $salary = $this->annualSalary($employee);
        $lines = [];
        foreach ($items as $item) {
            $section = $item['section'];
            $def = self::SECTIONS[$section] ?? null;
            if (! $def) {
                continue;
            }
            $declared = max(0.0, (float) ($item['amount'] ?? 0));
            if ($section === 'HRA') {
                $eligible = $declared > 0 ? max(0.0, min(
                    $salary['hra'],
                    $declared - 0.10 * $salary['basic'],
                    ($metro ? 0.5 : 0.4) * $salary['basic'],
                )) : 0.0;
            } else {
                $cap = ! empty($item['senior']) && isset($def['senior_cap']) ? $def['senior_cap'] : $def['cap'];
                $eligible = $cap === null ? $declared : min($declared, (float) $cap);
            }
            $lines[] = ['section' => $section, 'label' => $def['label'], 'declared' => $declared, 'eligible' => round($eligible, 2),
                'note' => $item['note'] ?? null, 'senior' => ! empty($item['senior'])];
        }

        return ['lines' => $lines, 'total' => round(array_sum(array_column($lines, 'eligible')), 2), 'salary' => $salary];
    }

    /** Projected annual tax under each regime, for choosing one. */
    public function compare(Employee $employee, float $deductions): array
    {
        $calc = app(StatutoryCalculator::class);
        $gross = $this->annualSalary($employee)['gross'];
        $cfg = config('statutory.income_tax');
        $out = [];
        foreach (['new' => 0.0, 'old' => $deductions] as $regime => $extra) {
            $taxable = max(0.0, $gross - (float) $cfg[$regime]['standard_deduction'] - $extra);
            $out[$regime] = ['taxable_income' => round($taxable, 2), 'annual_tax' => $calc->annualIncomeTax($taxable, $regime),
                'standard_deduction' => (float) $cfg[$regime]['standard_deduction'], 'deductions' => $regime === 'old' ? $extra : 0];
        }
        $out['projected_gross'] = $gross;
        $out['better'] = $out['old']['annual_tax'] < $out['new']['annual_tax'] ? 'old' : 'new';
        $out['saving'] = abs($out['old']['annual_tax'] - $out['new']['annual_tax']);

        return $out;
    }

    public function save(Employee $employee, string $fy, array $data, bool $submit): TaxDeclaration
    {
        $declaration = TaxDeclaration::firstOrNew(['employee_id' => $employee->id, 'financial_year' => $fy]);
        abort_if($declaration->exists && $declaration->status === 'approved', 422, 'This declaration is approved. Ask HR to reopen it to make changes.');

        $eval = $this->evaluate($employee, $data['items'] ?? [], (bool) ($data['metro'] ?? false));
        $declaration->fill([
            'regime' => $data['regime'],
            'items' => array_map(fn ($l) => $l + ['metro' => (bool) ($data['metro'] ?? false)], $eval['lines']),
            'declared_total' => $data['regime'] === 'old' ? $eval['total'] : 0,
            'status' => $submit ? 'submitted' : 'draft',
            'submitted_at' => $submit ? now() : $declaration->submitted_at,
            'review_note' => $submit ? null : $declaration->review_note,
        ])->save();

        return $declaration->fresh();
    }

    public function review(TaxDeclaration $declaration, bool $approve, ?float $approvedTotal, ?string $note, User $by): TaxDeclaration
    {
        abort_unless($declaration->status === 'submitted', 422, 'Only a submitted declaration can be reviewed.');

        $declaration->update([
            'status' => $approve ? 'approved' : 'rejected',
            'approved_total' => $approve ? ($approvedTotal ?? (float) $declaration->declared_total) : null,
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        if ($approve) {
            // What TDS uses from the next payroll run.
            $declaration->employee->forceFill([
                'tax_regime' => $declaration->regime,
                'declared_deductions' => $declaration->regime === 'old' ? $declaration->approved_total : 0,
            ])->save();
        }

        return $declaration->fresh();
    }

    public function reopen(TaxDeclaration $declaration): TaxDeclaration
    {
        abort_unless(in_array($declaration->status, ['approved', 'rejected'], true), 422, 'Nothing to reopen.');
        $declaration->update(['status' => 'submitted', 'reviewed_at' => null, 'reviewed_by' => null]);

        return $declaration->fresh();
    }
}
