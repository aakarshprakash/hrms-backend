<?php

namespace App\Services\Compliance;

use App\Models\Branch;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Scopes\BranchScope;
use App\Models\StatutoryRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Statutory returns built from finalized payroll: nothing here recomputes
 * a contribution -- every figure is what the payslip already carries, so the
 * returns always agree with what employees were paid.
 *
 *  - PF ECR 2.0 upload file (EPFO) and a readable PF register;
 *  - ESIC monthly contribution upload (ESIC portal template);
 *  - Professional tax register by state;
 *  - TDS on salary (section 192) for the quarterly 24Q return;
 *  - Salary register (register of wages) with every component.
 */
class StatutoryReports
{
    public const REPORTS = [
        'pf' => 'PF register',
        'pf-ecr' => 'PF ECR upload file',
        'esi' => 'ESIC contribution upload',
        'pt' => 'Professional tax register',
        'tds' => 'TDS (24Q) salary details',
        'salary-register' => 'Salary register',
    ];

    /**
     * Finalized / paid payslips of a month for the branches in scope.
     *
     * @param  list<int>|null  $branchIds  null = every branch of the tenant
     * @return Collection<int, Payslip>
     */
    public function payslips(int $year, int $month, ?array $branchIds): Collection
    {
        $runIds = PayrollRun::withoutGlobalScope(BranchScope::class)
            ->where('year', $year)->where('month', $month)
            ->whereIn('status', ['finalized', 'paid'])
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->pluck('id');

        return Payslip::with([
            'employee' => fn ($q) => $q->withoutGlobalScope(BranchScope::class),
            'employee.designation:id,title', 'employee.department:id,name', 'employee.branch:id,name,state',
            'payrollRun:id,branch_id,year,month,status,paid_at',
        ])
            ->whereIn('payroll_run_id', $runIds)
            ->get()
            ->sortBy(fn (Payslip $p) => $p->employee?->employee_code)
            ->values();
    }

    /** Runs of the month that exist but aren't finalized yet (their payslips are left out). */
    public function unfinalizedRuns(int $year, int $month, ?array $branchIds): Collection
    {
        return PayrollRun::withoutGlobalScope(BranchScope::class)->with('branch:id,name')
            ->where('year', $year)->where('month', $month)
            ->whereIn('status', ['draft', 'processed'])
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->get(['id', 'branch_id', 'status']);
    }

    // ── PF ───────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function pf(Collection $payslips): array
    {
        return $payslips
            ->filter(fn (Payslip $p) => (float) $p->pf_employee > 0)
            ->map(function (Payslip $p) {
                $e = $p->employee;
                $epfWage = (int) round((float) $p->pf_wage);
                $ceiling = (int) config('statutory.pf.wage_ceiling', 15000);

                return [
                    'employee_code' => $e?->employee_code,
                    'uan' => $e?->uan,
                    'name' => $this->plainName($e?->full_name),
                    'gross_wages' => (int) round((float) $p->gross_pay),
                    'epf_wages' => $epfWage,
                    'eps_wages' => (float) $p->eps_employer > 0 ? min($epfWage, $ceiling) : 0,
                    'edli_wages' => min($epfWage, $ceiling),
                    'epf_contribution' => (int) round((float) $p->pf_employee),
                    'eps_contribution' => (int) round((float) $p->eps_employer),
                    'epf_eps_difference' => (int) round((float) $p->pf_employer),
                    'ncp_days' => (int) round((float) $p->lop_days),
                    'refund_of_advances' => 0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * EPFO ECR 2.0: one line per member, fields joined by #~#, no header.
     * Members without a UAN can't be uploaded and are left out (the
     * summary lists them).
     */
    public function pfEcr(Collection $payslips): string
    {
        $lines = [];
        foreach ($this->pf($payslips) as $r) {
            if (! $r['uan']) {
                continue;
            }
            $lines[] = implode('#~#', [
                $r['uan'], strtoupper($r['name']), $r['gross_wages'], $r['epf_wages'], $r['eps_wages'], $r['edli_wages'],
                $r['epf_contribution'], $r['eps_contribution'], $r['epf_eps_difference'], $r['ncp_days'], $r['refund_of_advances'],
            ]);
        }

        return implode("\n", $lines) . ($lines ? "\n" : '');
    }

    // ── ESI ──────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function esi(Collection $payslips, int $year, int $month): array
    {
        $monthEnd = CarbonImmutable::create($year, $month, 1)->endOfMonth();

        return $payslips
            ->filter(fn (Payslip $p) => (float) $p->esi_employee > 0 || (float) $p->esi_employer > 0)
            ->map(function (Payslip $p) use ($monthEnd) {
                $e = $p->employee;
                $days = (int) round((float) $p->payable_days);
                $left = $e?->date_of_leaving ? CarbonImmutable::parse($e->date_of_leaving) : null;
                $leftThisMonth = $left && $left->lte($monthEnd);

                return [
                    'employee_code' => $e?->employee_code,
                    'ip_number' => $e?->esi_number,
                    'ip_name' => $this->plainName($e?->full_name),
                    'days' => $days,
                    'wages' => (int) round((float) $p->esi_wage),
                    // ESIC reason codes for zero days: 2 = left service; 0 otherwise.
                    'reason_code' => $days === 0 ? ($leftThisMonth ? 2 : 1) : ($leftThisMonth ? 2 : 0),
                    'last_working_day' => $leftThisMonth ? $left->format('d/m/Y') : '',
                    'employee_contribution' => (float) $p->esi_employee,
                    'employer_contribution' => (float) $p->esi_employer,
                ];
            })
            ->values()
            ->all();
    }

    // ── Professional tax ─────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function pt(Collection $payslips): array
    {
        return $payslips
            ->filter(fn (Payslip $p) => (float) $p->professional_tax > 0)
            ->map(fn (Payslip $p) => [
                'employee_code' => $p->employee?->employee_code,
                'name' => $p->employee?->full_name,
                'branch' => $p->employee?->branch?->name,
                'state' => $p->employee?->branch?->state ?? $p->employee?->state,
                'gross' => round((float) $p->gross_pay, 2),
                'professional_tax' => round((float) $p->professional_tax, 2),
            ])
            ->values()
            ->all();
    }

    // ── TDS ──────────────────────────────────────────────────────────────

    /**
     * Salary TDS rows (section 192) for 24Q: one per employee per month.
     * PAN is shown in full only to users allowed to see sensitive data.
     *
     * @return list<array<string, mixed>>
     */
    public function tds(Collection $payslips, bool $showPan): array
    {
        return $payslips
            ->filter(fn (Payslip $p) => (float) $p->tds > 0 || (float) $p->taxable_gross > 0)
            ->map(function (Payslip $p) use ($showPan) {
                $e = $p->employee;
                $pan = $e?->tax_id ? strtoupper(preg_replace('/\s+/', '', (string) $e->tax_id)) : null;
                $run = $p->payrollRun;
                $paidOn = $run?->paid_at ? CarbonImmutable::parse($run->paid_at) : CarbonImmutable::create($run->year, $run->month, 1)->endOfMonth();

                return [
                    'employee_code' => $e?->employee_code,
                    'name' => $e?->full_name,
                    'pan' => $pan ? ($showPan ? $pan : str_repeat('X', 6) . substr($pan, -4)) : null,
                    'section' => '192',
                    'month' => CarbonImmutable::create($run->year, $run->month, 1)->format('M Y'),
                    'payment_date' => $paidOn->format('d/m/Y'),
                    'amount_paid' => round((float) ($p->taxable_gross ?: $p->gross_pay), 2),
                    'tds' => round((float) $p->tds, 2),
                    'tax_regime' => $e?->tax_regime ?? 'new',
                ];
            })
            ->values()
            ->all();
    }

    // ── Salary register ──────────────────────────────────────────────────

    /** @return array{columns: array{earnings: array<string, string>, deductions: array<string, string>}, rows: list<array<string, mixed>>} */
    public function salaryRegister(Collection $payslips): array
    {
        $earnings = [];
        $deductions = [];
        foreach ($payslips as $p) {
            $b = $this->breakdown($p);
            foreach ($b['earnings'] ?? [] as $line) {
                $earnings[$line['code'] ?? $line['name']] ??= $line['name'] ?? $line['code'];
            }
            foreach ($b['deductions'] ?? [] as $line) {
                $deductions[$line['code'] ?? $line['name']] ??= $line['name'] ?? $line['code'];
            }
        }

        $rows = $payslips->map(function (Payslip $p) use ($earnings, $deductions) {
            $b = $this->breakdown($p);
            $e = $p->employee;
            $earned = collect($b['earnings'] ?? [])->keyBy(fn ($l) => $l['code'] ?? $l['name']);
            $deducted = collect($b['deductions'] ?? [])->keyBy(fn ($l) => $l['code'] ?? $l['name']);

            return [
                'employee_code' => $e?->employee_code,
                'name' => $e?->full_name,
                'designation' => $e?->designation?->title,
                'department' => $e?->department?->name,
                'branch' => $e?->branch?->name,
                'days_in_period' => (float) $p->days_in_period,
                'payable_days' => (float) $p->payable_days,
                'lop_days' => (float) $p->lop_days,
                'earnings' => collect($earnings)->map(fn ($name, $code) => round((float) ($earned[$code]['earned'] ?? $earned[$code]['amount'] ?? 0), 2))->all(),
                'gross' => round((float) $p->gross_pay, 2),
                'deductions' => collect($deductions)->map(fn ($name, $code) => round((float) ($deducted[$code]['amount'] ?? 0), 2))->all(),
                'total_deductions' => round((float) $p->total_deductions, 2),
                'net_pay' => round((float) $p->net_pay, 2),
                'employer_pf' => round((float) $p->pf_employer + (float) $p->eps_employer, 2),
                'employer_esi' => round((float) $p->esi_employer, 2),
                'ctc' => round((float) $p->employer_cost, 2),
            ];
        })->values()->all();

        return ['columns' => ['earnings' => $earnings, 'deductions' => $deductions], 'rows' => $rows];
    }

    // ── Summary ──────────────────────────────────────────────────────────

    /**
     * Challan amounts, due dates and data gaps for one month.
     *
     * @param  list<int>|null  $branchIds
     */
    public function summary(int $year, int $month, ?array $branchIds, User $user): array
    {
        $payslips = $this->payslips($year, $month, $branchIds);
        $pfRows = collect($this->pf($payslips));
        $esiRows = collect($this->esi($payslips, $year, $month));
        $period = CarbonImmutable::create($year, $month, 1);
        $next = $period->addMonth();

        $epfWages = $pfRows->sum('epf_wages');
        $edliWages = $pfRows->sum('edli_wages');
        [$edliRate, $adminRate] = $this->pfChargeRates($branchIds);
        $adminCharges = $pfRows->isEmpty() ? 0 : max(500, (int) round($epfWages * $adminRate / 100));
        $edliCharges = (int) round($edliWages * $edliRate / 100);

        $pfEmployee = (int) $pfRows->sum('epf_contribution');
        $pfEmployerEpf = (int) $pfRows->sum('epf_eps_difference');
        $pfEps = (int) $pfRows->sum('eps_contribution');
        $esiEmployee = round((float) $esiRows->sum('employee_contribution'), 2);
        $esiEmployer = round((float) $esiRows->sum('employer_contribution'), 2);

        // Quarterly TDS returns are due the month after the quarter (Q4: 31 May).
        $quarterEnds = in_array($month, [3, 6, 9, 12], true);

        $missing = fn (string $field, Collection $rows) => $rows->filter(fn ($r) => empty($r[$field]))->pluck('employee_code')->filter()->values()->all();

        return [
            'period' => ['year' => $year, 'month' => $month, 'label' => $period->format('F Y')],
            'employees' => $payslips->count(),
            'unfinalized_runs' => $this->unfinalizedRuns($year, $month, $branchIds)->map(fn ($r) => ['id' => $r->id, 'branch' => $r->branch?->name, 'status' => $r->status])->values(),
            'pf' => [
                'members' => $pfRows->count(),
                'epf_wages' => $epfWages,
                'employee_share' => $pfEmployee,
                'employer_epf' => $pfEmployerEpf,
                'employer_eps' => $pfEps,
                'edli_charges' => $edliCharges,
                'admin_charges' => $adminCharges,
                'total' => $pfEmployee + $pfEmployerEpf + $pfEps + $edliCharges + $adminCharges,
                'due_date' => $next->setDay(15)->toDateString(),
                'missing_uan' => $missing('uan', $pfRows),
            ],
            'esi' => [
                'members' => $esiRows->count(),
                'wages' => (int) $esiRows->sum('wages'),
                'employee_share' => $esiEmployee,
                'employer_share' => $esiEmployer,
                'total' => (int) ceil($esiEmployee + $esiEmployer),
                'due_date' => $next->setDay(15)->toDateString(),
                'missing_ip_number' => $missing('ip_number', $esiRows),
            ],
            'pt' => [
                'employees' => $payslips->filter(fn ($p) => (float) $p->professional_tax > 0)->count(),
                'total' => round((float) $payslips->sum('professional_tax'), 2),
                'by_state' => collect($this->pt($payslips))->groupBy('state')->map(fn ($rows, $state) => [
                    'state' => $state ?: 'Unknown', 'employees' => $rows->count(), 'total' => round((float) $rows->sum('professional_tax'), 2),
                ])->values(),
            ],
            'tds' => [
                'employees' => $payslips->filter(fn ($p) => (float) $p->tds > 0)->count(),
                'total' => round((float) $payslips->sum('tds'), 2),
                // Deposit by the 7th of next month (30 April for March).
                'due_date' => $month === 3 ? CarbonImmutable::create($year, 4, 30)->toDateString() : $next->setDay(7)->toDateString(),
                'return_due' => $quarterEnds ? ($month === 3 ? CarbonImmutable::create($year, 5, 31)->toDateString() : $next->endOfMonth()->toDateString()) : null,
                'missing_pan' => $payslips->filter(fn ($p) => (float) $p->tds > 0 && empty($p->employee?->tax_id))->map(fn ($p) => $p->employee?->employee_code)->filter()->values(),
            ],
            'payroll' => [
                'gross' => round((float) $payslips->sum('gross_pay'), 2),
                'net' => round((float) $payslips->sum('net_pay'), 2),
                'employer_cost' => round((float) $payslips->sum('employer_cost'), 2),
            ],
        ];
    }

    /** EDLI and admin charge rates (%) from the branches' PF rules, else defaults. */
    private function pfChargeRates(?array $branchIds): array
    {
        $config = StatutoryRule::withoutGlobalScope(BranchScope::class)
            ->where('rule_type', 'PF')->where('is_active', true)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->value('config_json');

        $config = is_array($config) ? $config : (json_decode((string) $config, true) ?: []);

        return [
            (float) ($config['edli_rate'] ?? config('statutory.pf.edli_rate', 0.5)),
            (float) ($config['admin_rate'] ?? config('statutory.pf.admin_rate', 0.5)),
        ];
    }

    private function breakdown(Payslip $p): array
    {
        $b = $p->breakdown_json;

        return is_array($b) ? $b : (json_decode((string) $b, true) ?: []);
    }

    /** Names as the EPFO / ESIC portals accept them: letters, dots and spaces. */
    private function plainName(?string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z. ]/', ' ', (string) $name)));
    }
}
