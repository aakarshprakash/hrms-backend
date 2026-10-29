<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\LoanRecovery;
use App\Models\PayrollRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Loans and salary advances.
 *
 *   total payable = principal + flat interest (rate % a year × tenure);
 *   EMI           = total ÷ tenure, the last instalment takes the remainder;
 *   payroll       deducts min(EMI, outstanding) each month from the start month;
 *   ledger        loan_recoveries -- a processed run writes one row per loan
 *                 (rewritten on reprocess), manual repayments have no run.
 */
class LoanService
{
    /** @return array{total_payable: float, emi: float} */
    public function terms(float $principal, float $ratePercent, int $tenure): array
    {
        $interest = round($principal * $ratePercent / 100 * $tenure / 12, 2);
        $total = round($principal + $interest, 2);

        return ['total_payable' => $total, 'emi' => round($total / max(1, $tenure), 2)];
    }

    public function create(Employee $employee, array $data, User $by): EmployeeLoan
    {
        $terms = $this->terms((float) $data['principal'], (float) ($data['interest_rate'] ?? 0), (int) $data['tenure_months']);

        return EmployeeLoan::create([
            'employee_id' => $employee->id,
            'type' => $data['type'],
            'principal' => $data['principal'],
            'interest_rate' => $data['interest_rate'] ?? 0,
            'tenure_months' => $data['tenure_months'],
            'start_month' => $data['start_month'],
            'start_year' => $data['start_year'],
            'disbursed_on' => $data['disbursed_on'] ?? now()->toDateString(),
            'reason' => $data['reason'] ?? null,
            'status' => 'active',
            'created_by' => $by->id,
        ] + $terms);
    }

    /** What's still owed, ignoring what $exceptRunId itself deducted (so a run can be recomputed). */
    public function outstanding(EmployeeLoan $loan, ?int $exceptRunId = null): float
    {
        $recovered = LoanRecovery::where('employee_loan_id', $loan->id)
            ->when($exceptRunId, fn ($q) => $q->where(fn ($w) => $w->whereNull('payroll_run_id')->orWhere('payroll_run_id', '!=', $exceptRunId)))
            ->sum('amount');

        return max(0.0, round((float) $loan->total_payable - (float) $recovered, 2));
    }

    /**
     * Instalments due from an employee in a payroll run, as payslip deduction lines.
     *
     * @return list<array<string, mixed>>
     */
    public function deductionsFor(Employee $employee, PayrollRun $run): array
    {
        $lines = [];
        $loans = EmployeeLoan::where('employee_id', $employee->id)->where('status', 'active')->orderBy('id')->get();

        foreach ($loans as $loan) {
            if (! $loan->startsBy($run->year, $run->month)) {
                continue;
            }
            $owed = $this->outstanding($loan, $run->id);
            if ($owed <= 0) {
                continue;
            }
            $amount = round(min((float) $loan->emi, $owed), 2);
            $paidCount = LoanRecovery::where('employee_loan_id', $loan->id)->where('method', 'payroll')
                ->where('payroll_run_id', '!=', $run->id)->count();

            $lines[] = [
                'code' => $loan->type === 'advance' ? 'ADV' : 'LOAN',
                'name' => $loan->type === 'advance' ? 'Salary advance recovery' : 'Loan EMI',
                'amount' => $amount,
                'kind' => 'loan',
                'loan_id' => $loan->id,
                'note' => sprintf('Instalment %d of %d · balance after %s', $paidCount + 1, $loan->tenure_months, number_format($owed - $amount, 2)),
            ];
        }

        return $lines;
    }

    /** After a run is (re)processed: its ledger rows mirror exactly what its payslips deducted. */
    public function syncRun(PayrollRun $run, Collection $results): void
    {
        LoanRecovery::where('payroll_run_id', $run->id)->delete();
        $on = CarbonImmutable::create($run->year, $run->month, 1)->endOfMonth()->toDateString();

        foreach ($results as $result) {
            foreach ($result['breakdown_json']['deductions'] ?? [] as $line) {
                if (($line['kind'] ?? null) === 'loan' && ! empty($line['loan_id']) && $line['amount'] > 0) {
                    LoanRecovery::create([
                        'employee_loan_id' => $line['loan_id'],
                        'payroll_run_id' => $run->id,
                        'amount' => $line['amount'],
                        'recovered_on' => $on,
                        'method' => 'payroll',
                        'note' => sprintf('Payroll %02d/%d', $run->month, $run->year),
                    ]);
                }
            }
        }
    }

    /** A repayment outside payroll (cash, bank transfer) or a waiver; capped at what's owed. */
    public function repay(EmployeeLoan $loan, float $amount, string $method, ?string $note, User $by): LoanRecovery
    {
        $owed = $this->outstanding($loan);
        abort_if($owed <= 0, 422, 'This loan is already settled.');

        return LoanRecovery::create([
            'employee_loan_id' => $loan->id,
            'amount' => min($amount, $owed),
            'recovered_on' => now()->toDateString(),
            'method' => $method,
            'note' => $note,
            'created_by' => $by->id,
        ]);
    }

    /** For the API: the loan with its balance and a month-by-month schedule. */
    public function present(EmployeeLoan $loan): array
    {
        $recoveries = $loan->recoveries()->with('payrollRun:id,month,year,status')->orderBy('recovered_on')->get();
        $recovered = round((float) $recoveries->sum('amount'), 2);
        $outstanding = max(0.0, round((float) $loan->total_payable - $recovered, 2));

        $schedule = [];
        $balance = $outstanding;
        $month = CarbonImmutable::create($loan->start_year, $loan->start_month, 1);
        $lastRecovered = $recoveries->whereNotNull('payroll_run_id')->map(fn ($r) => $r->payrollRun ? $r->payrollRun->year * 100 + $r->payrollRun->month : 0)->max();
        if ($lastRecovered) {
            $month = CarbonImmutable::create(intdiv($lastRecovered, 100), $lastRecovered % 100, 1)->addMonth();
        }
        while ($balance > 0 && $loan->status === 'active' && count($schedule) < 120) {
            $amount = round(min((float) $loan->emi, $balance), 2);
            $balance = round($balance - $amount, 2);
            $schedule[] = ['month' => $month->format('Y-m'), 'label' => $month->format('M Y'), 'amount' => $amount, 'balance_after' => $balance];
            $month = $month->addMonth();
        }

        return $loan->toArray() + [
            'employee' => $loan->employee?->only(['id', 'employee_code', 'first_name', 'last_name']) + ['name' => $loan->employee?->full_name],
            'recovered' => $recovered,
            'outstanding' => $outstanding,
            'state' => $loan->status === 'cancelled' ? 'cancelled' : ($outstanding <= 0 ? 'settled' : 'active'),
            'recoveries' => $recoveries->map(fn ($r) => $r->only(['id', 'amount', 'recovered_on', 'method', 'note', 'payroll_run_id'])),
            'schedule' => $schedule,
        ];
    }
}
