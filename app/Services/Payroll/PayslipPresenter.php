<?php

namespace App\Services\Payroll;

use App\Models\Payslip;
use App\Support\IndianNumberToWords;
use Carbon\Carbon;

/**
 * One view-model for a payslip regardless of when it was computed: v2
 * breakdowns (current engine) and v1 ones (before the engine rewrite) both
 * become the same rows, so old payslips still render and download.
 */
class PayslipPresenter
{
    public function present(Payslip $payslip): array
    {
        $payslip->loadMissing(['employee.branch', 'employee.designation', 'employee.department', 'payrollRun']);
        $employee = $payslip->employee;
        $run = $payslip->payrollRun;
        $company = $employee?->company()->first() ?? $employee?->branch?->company;
        $b = $payslip->breakdown_json ?? [];

        [$earnings, $deductions] = ($b['version'] ?? 1) >= 2 ? $this->v2($b) : $this->v1($b, $payslip);
        $att = $b['attendance'] ?? null;

        return [
            'company' => [
                'name' => $company?->legal_name ?: $company?->name ?? 'Company',
                'address' => collect([$company?->address_line1, $company?->address_line2, $company?->city, $company?->state, $company?->postal_code])->filter()->implode(', '),
                'logo_path' => $company?->logo_path ? storage_path('app/public/' . ltrim($company->logo_path, '/')) : null,
                'pf_code' => $company?->statutory['pf_establishment_code'] ?? null,
                'esi_code' => $company?->statutory['esi_employer_code'] ?? null,
            ],
            'period' => Carbon::create($run->year, $run->month, 1)->format('F Y'),
            'branch' => $employee?->branch?->name,
            'employee' => [
                'name' => $employee?->full_name,
                'code' => $employee?->employee_code,
                'designation' => $employee?->designation?->title,
                'department' => $employee?->department?->name,
                'date_of_joining' => $employee?->date_of_joining?->format('d M Y'),
                'pan' => $this->mask($employee?->tax_id, 4),
                'uan' => $employee?->uan,
                'esi_number' => $employee?->esi_number,
                'bank' => $employee?->bank_name,
                'account' => $this->mask($employee?->bank_account_number, 4),
                'tax_regime' => $employee?->tax_regime === 'old' ? 'Old regime' : 'New regime',
            ],
            'attendance' => $att ? [
                'basis_days' => $att['basis_days'],
                'payable_days' => $att['payable_days'],
                'lop_days' => $att['lop_days'],
                'present_days' => $att['present_days'] ?? null,
                'paid_leave_days' => $att['paid_leave_days'] ?? null,
            ] : [
                'basis_days' => $payslip->days_in_period,
                'payable_days' => $payslip->payable_days,
                'lop_days' => $payslip->lop_days,
                'present_days' => null,
                'paid_leave_days' => null,
            ],
            'earnings' => $earnings,
            'deductions' => $deductions,
            'employer' => $b['employer_contributions'] ?? [],
            'gross' => (float) $payslip->gross_pay,
            'total_deductions' => (float) $payslip->total_deductions,
            'net' => (float) $payslip->net_pay,
            'net_words' => IndianNumberToWords::rupees((float) $payslip->net_pay),
            'currency' => $payslip->currency_code === 'INR' ? '₹' : $payslip->currency_code . ' ',
            'tds' => $b['statutory']['tds'] ?? null,
            'generated_at' => now()->format('d M Y'),
        ];
    }

    private function v2(array $b): array
    {
        $earnings = array_map(fn ($e) => [
            'name' => $e['name'],
            'monthly' => $e['monthly'],
            'amount' => (float) $e['earned'],
            'note' => $e['note'] ?? null,
        ], $b['earnings'] ?? []);

        $deductions = array_map(fn ($d) => ['name' => $d['name'], 'amount' => (float) $d['amount'], 'note' => $d['note'] ?? null], $b['deductions'] ?? []);

        return [$earnings, $deductions];
    }

    /** Pre-engine payslips: earnings, OT, structure + statutory deductions and LOP as a deduction. */
    private function v1(array $b, Payslip $payslip): array
    {
        $earnings = array_map(fn ($e) => ['name' => $e['name'], 'monthly' => null, 'amount' => (float) $e['amount'], 'note' => $e['note'] ?? null], $b['earnings'] ?? []);
        if ((float) ($b['ot_pay'] ?? 0) > 0) {
            $earnings[] = ['name' => 'Overtime', 'monthly' => null, 'amount' => (float) $b['ot_pay'], 'note' => null];
        }

        $deductions = array_map(fn ($d) => ['name' => $d['name'], 'amount' => (float) $d['amount'], 'note' => $d['note'] ?? null], $b['deductions'] ?? []);
        foreach ($b['statutory_deductions'] ?? [] as $s) {
            $deductions[] = ['name' => ['PF' => 'Provident Fund', 'ESI' => 'ESI', 'TAX' => 'Income Tax (TDS)'][$s['rule_type']] ?? $s['rule_type'], 'amount' => (float) $s['amount'], 'note' => null];
        }
        if ((float) ($b['lop']['amount'] ?? 0) > 0) {
            $deductions[] = ['name' => 'Loss of pay (' . $b['lop']['days'] . ' days)', 'amount' => (float) $b['lop']['amount'], 'note' => null];
        }

        return [$earnings, $deductions];
    }

    private function mask(?string $value, int $visible): ?string
    {
        if (! $value) {
            return null;
        }
        $plain = preg_replace('/\s+/', '', $value);

        return str_repeat('X', max(0, strlen($plain) - $visible)) . substr($plain, -$visible);
    }
}
