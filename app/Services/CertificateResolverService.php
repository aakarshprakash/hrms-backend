<?php

namespace App\Services;

use App\Models\Employee;
use Carbon\Carbon;

class CertificateResolverService
{
    public function resolve(string $html, Employee $employee): string
    {
        // Load employee with relationships
        $employee->load(['designation', 'department', 'branch.company', 'user']);

        // Get latest payslip for salary tokens
        $latestPayslip = $employee->payslips()->latest()->first();

        $replacements = [
            '{{employee_name}}' => $employee->first_name . ' ' . $employee->last_name,
            '{{employee_code}}' => $employee->employee_code ?? '',
            '{{employee_email}}' => $employee->email ?? '',
            '{{designation}}' => $employee->designation?->title ?? '',
            '{{department}}' => $employee->department?->name ?? '',
            '{{date_of_joining}}' => $employee->date_of_joining
                ? Carbon::parse($employee->date_of_joining)->format('d M Y')
                : '',
            '{{date_of_leaving}}' => '', // resolved per request context if needed
            '{{employment_type}}' => ucfirst($employee->employment_type ?? ''),
            '{{gross_salary}}' => $latestPayslip ? '₹' . number_format($latestPayslip->gross_pay, 2) : '',
            '{{net_salary}}' => $latestPayslip ? '₹' . number_format($latestPayslip->net_pay, 2) : '',
            '{{ctc}}' => $latestPayslip ? '₹' . number_format($latestPayslip->gross_pay * 12, 2) : '',
            '{{branch_name}}' => $employee->branch?->name ?? '',
            '{{company_name}}' => $employee->branch?->company?->name ?? '',
            '{{branch_address}}' => $employee->branch?->address ?? '',
            '{{branch_city}}' => $employee->branch?->city ?? '',
            '{{branch_country}}' => $employee->branch?->country ?? '',
            '{{issue_date}}' => Carbon::now()->format('d M Y'),
            '{{certificate_number}}' => '', // filled after issuance
            '{{valid_until}}' => Carbon::now()->addYear()->format('d M Y'),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    public function resolveWithCertNumber(string $html, string $certNumber): string
    {
        return str_replace('{{certificate_number}}', $certNumber, $html);
    }
}
