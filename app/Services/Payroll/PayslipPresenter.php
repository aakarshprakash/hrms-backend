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

        [$earnings, $deductions, $lop] = ($b['version'] ?? 1) >= 2 ? $this->v2($b) : [...$this->v1($b, $payslip), 0.0];
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
            // Full-rate gross with loss of pay among the deductions; net pay is the same either way.
            'gross' => round((float) $payslip->gross_pay + $lop, 2),
            'total_deductions' => round((float) $payslip->total_deductions + $lop, 2),
            'net' => (float) $payslip->net_pay,
            'net_words' => IndianNumberToWords::rupees((float) $payslip->net_pay),
            'currency' => $payslip->currency_code === 'INR' ? '₹' : $payslip->currency_code . ' ',
            'tds' => $b['statutory']['tds'] ?? null,
            'generated_at' => now()->format('d M Y'),
        ];
    }

    /** Earnings at their full monthly rate; what attendance took off becomes a Loss of pay deduction. */
    private function v2(array $b): array
    {
        $lop = 0.0;
        $earnings = array_map(function ($e) use (&$lop) {
            $prorated = ($e['kind'] ?? '') === 'structure' && ! empty($e['prorated']) && $e['monthly'] !== null;
            if ($prorated) {
                $lop += max(0, (float) $e['monthly'] - (float) $e['earned']);
            }

            return ['name' => $e['name'], 'amount' => (float) ($prorated ? $e['monthly'] : $e['earned']), 'note' => $e['note'] ?? null];
        }, $b['earnings'] ?? []);
        $lop = round($lop, 2);

        $deductions = array_map(fn ($d) => ['name' => $d['name'], 'amount' => (float) $d['amount'], 'note' => $d['note'] ?? null], $b['deductions'] ?? []);
        if ($lop > 0) {
            $days = (float) ($b['attendance']['lop_days'] ?? 0);
            array_unshift($deductions, ['name' => $days > 0 ? 'Loss of pay (' . rtrim(rtrim(number_format($days, 2), '0'), '.') . ' day' . ($days == 1 ? '' : 's') . ')' : 'Loss of pay (unpaid days)',
                'amount' => $lop, 'note' => null]);
        }

        return [$earnings, $deductions, $lop];
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
