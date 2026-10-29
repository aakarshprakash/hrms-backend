<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Compliance\Form16Service;
use App\Services\Compliance\StatutoryReports;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Monthly statutory compliance: challan totals and due dates, and the
 * files the PF / ESIC portals and the TDS return need -- all from
 * finalized payroll, for the branches in the user's scope.
 */
class ComplianceController extends Controller
{
    public function __construct(private StatutoryReports $reports)
    {
    }

    public function summary(Request $request): JsonResponse
    {
        [$year, $month, $branches] = $this->period($request);

        return response()->json(['data' => $this->reports->summary($year, $month, $branches, $request->user())]);
    }

    /** First rows of a report, for the on-screen tables. */
    public function preview(Request $request, string $report): JsonResponse
    {
        abort_unless(array_key_exists($report, StatutoryReports::REPORTS), 404);
        [$year, $month, $branches] = $this->period($request);
        $payslips = $this->payslipsFor($request, $report, $year, $month, $branches);

        $data = match ($report) {
            'pf', 'pf-ecr' => $this->reports->pf($payslips),
            'esi' => $this->reports->esi($payslips, $year, $month),
            'pt' => $this->reports->pt($payslips),
            'tds' => $this->reports->tds($payslips, $request->user()->can('employees.sensitive')),
            'salary-register' => $this->reports->salaryRegister($payslips),
        };

        return response()->json(['data' => $data]);
    }

    public function download(Request $request, string $report)
    {
        abort_unless(array_key_exists($report, StatutoryReports::REPORTS), 404);
        [$year, $month, $branches] = $this->period($request);
        $payslips = $this->payslipsFor($request, $report, $year, $month, $branches);
        abort_if($payslips->isEmpty(), 422, 'There is no finalized payroll for this period yet.');
        $pdf = $request->input('format') === 'pdf';
        if ($pdf && $report === 'pf-ecr') {
            $report = 'pf';
        }

        $stamp = $request->filled('quarter') ? "{$year}-Q{$request->integer('quarter')}" : sprintf('%04d%02d', $year, $month);

        activity('payroll')->causedBy($request->user())->event('compliance_export')
            ->withProperties(['report' => $report, 'period' => $stamp, 'branches' => $branches, 'format' => $pdf ? 'pdf' : 'file'])
            ->log(StatutoryReports::REPORTS[$report] . " exported ({$stamp})");

        if ($report === 'pf-ecr') {
            $content = $this->reports->pfEcr($payslips);

            return response()->streamDownload(function () use ($content) {
                echo $content;
            }, "ECR_{$stamp}.txt", ['Content-Type' => 'text/plain']);
        }

        [$header, $rows] = match ($report) {
            'pf' => [
                ['Employee Code', 'UAN', 'Member Name', 'Gross Wages', 'EPF Wages', 'EPS Wages', 'EDLI Wages', 'EPF (Employee)', 'EPS (Employer)', 'EPF (Employer)', 'NCP Days',
                    'Arrear EPF Wages', 'Arrear EPF (Employee)', 'Arrear EPS (Employer)', 'Arrear EPF (Employer)'],
                collect($this->reports->pf($payslips))->map(fn ($r) => [
                    $r['employee_code'], $r['uan'], $r['name'], $r['gross_wages'], $r['epf_wages'], $r['eps_wages'], $r['edli_wages'],
                    $r['epf_contribution'], $r['eps_contribution'], $r['epf_eps_difference'], $r['ncp_days'],
                    $r['arrear_epf_wages'], $r['arrear_epf'], $r['arrear_eps'], $r['arrear_epf_eps_difference'],
                ]),
            ],
            // Column titles exactly as the ESIC monthly-contribution upload template has them.
            'esi' => [
                ['IP Number', 'IP Name ( Only alphabets and space )', 'No of Days for which wages paid/payable during the month', 'Total Monthly Wages',
                    'Reason Code for Zero workings days(numeric only; provide 0 for all other reasons- Click on the link for reference)',
                    'Last Working Day( Format DD/MM/YYYY  or DD-MM-YYYY)'],
                collect($this->reports->esi($payslips, $year, $month))->map(fn ($r) => [
                    $r['ip_number'], $r['ip_name'], $r['days'], $r['wages'], $r['reason_code'], $r['last_working_day'],
                ]),
            ],
            'pt' => [
                ['Employee Code', 'Name', 'Branch', 'State', 'Gross Salary', 'Professional Tax'],
                collect($this->reports->pt($payslips))->map(fn ($r) => array_values($r)),
            ],
            'tds' => [
                ['Employee Code', 'Name', 'PAN', 'Section', 'Month', 'Date of Payment', 'Amount Paid/Credited', 'TDS Deducted', 'Tax Regime'],
                collect($this->reports->tds($payslips, $request->user()->can('employees.sensitive')))->map(fn ($r) => array_values($r)),
            ],
            'salary-register' => $this->registerRows($this->reports->salaryRegister($payslips)),
        };

        $filename = str_replace(' ', '-', strtolower(StatutoryReports::REPORTS[$report])) . "-{$stamp}.csv";

        if ($pdf) {
            return $this->pdfStatement($request, $report, $header, collect($rows)->map(fn ($r) => array_values((array) $r))->all(), $year, $month, $branches, $stamp);
        }

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            Csv::put($out, $header);
            foreach ($rows as $row) {
                Csv::put($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** A printable statement: challan totals and due date on top, the register below, a signature block. */
    private function pdfStatement(Request $request, string $report, array $header, array $rows, int $year, int $month, ?array $branches, string $stamp)
    {
        $summary = $this->reports->summary($year, $month, $branches, $request->user());
        $inr = fn ($v) => '₹' . number_format((float) $v, 2);
        $due = fn ($d) => $d ? CarbonImmutable::parse($d)->format('d M Y') : '—';

        [$numeric, $boxes] = match ($report) {
            'pf' => [range(3, 14), [
                'Members' => $summary['pf']['members'], 'EPF wages' => $inr($summary['pf']['epf_wages']),
                'Employee share' => $inr($summary['pf']['employee_share']), 'Employer EPF + EPS' => $inr($summary['pf']['employer_epf'] + $summary['pf']['employer_eps']),
                'EDLI + admin' => $inr($summary['pf']['edli_charges'] + $summary['pf']['admin_charges']), 'Challan total' => $inr($summary['pf']['total']),
                'Due by' => $due($summary['pf']['due_date']),
            ]],
            'esi' => [[2, 3], [
                'Members' => $summary['esi']['members'], 'Wages' => $inr($summary['esi']['wages']), 'Employee 0.75%' => $inr($summary['esi']['employee_share']),
                'Employer 3.25%' => $inr($summary['esi']['employer_share']), 'Challan total' => $inr($summary['esi']['total']), 'Due by' => $due($summary['esi']['due_date']),
            ]],
            'pt' => [[4, 5], ['Employees' => $summary['pt']['employees'], 'Professional tax' => $inr($summary['pt']['total'])]],
            'tds' => [[6, 7], ['Employees with TDS' => $summary['tds']['employees'], 'TDS for the month' => $inr($summary['tds']['total']),
                'Deposit by' => $due($summary['tds']['due_date']), '24Q return due' => $due($summary['tds']['return_due'])]],
            default => [range(5, count($header) - 1), ['Employees' => count($rows), 'Gross' => $inr($summary['payroll']['gross']), 'Net pay' => $inr($summary['payroll']['net']),
                'Cost to company' => $inr($summary['payroll']['employer_cost'])]],
        };
        if ($request->filled('quarter') && $report === 'tds') {
            $boxes = ['Employees' => collect($rows)->pluck(0)->unique()->count(), 'TDS in the quarter' => $inr(collect($rows)->sum(fn ($r) => (float) $r[7]))];
        }

        $period = $request->filled('quarter')
            ? 'FY ' . $year . '-' . sprintf('%02d', ($year + 1) % 100) . ' · Q' . $request->integer('quarter')
            : CarbonImmutable::create($year, $month, 1)->format('F Y');

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.compliance', [
            'title' => StatutoryReports::REPORTS[$report], 'period' => $period, 'header' => $header, 'rows' => $rows,
            'numeric' => $numeric, 'boxes' => $boxes, 'company' => app(\App\Support\Tenancy\TenantContext::class)->company(),
            'generatedBy' => $request->user()->name,
        ])->setPaper('a4', $report === 'salary-register' || $report === 'pf' ? 'landscape' : 'portrait');

        $name = str_replace(' ', '-', strtolower(StatutoryReports::REPORTS[$report])) . "-{$stamp}.pdf";

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }

    /** Employees with salary in a financial year, for issuing Form 16. */
    public function form16List(Request $request, Form16Service $form16): JsonResponse
    {
        $fy = $this->financialYear($request);
        $company = app(\App\Support\Tenancy\TenantContext::class)->company();
        $branchId = $this->requestedBranchId();
        $employees = \App\Models\Employee::visibleTo($request->user())->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->get()->keyBy('id');
        $slips = $form16->payslips($employees->keys()->all(), $fy, (int) ($company?->fiscal_year_start_month ?: 4))->groupBy('employee_id');

        $rows = $slips->map(fn ($s, $id) => [
            'employee_id' => (int) $id,
            'employee_code' => $employees[$id]->employee_code,
            'name' => $employees[$id]->full_name,
            'pan' => $employees[$id]->tax_id ? 'XXXXXX' . substr(strtoupper($employees[$id]->tax_id), -4) : null,
            'months' => $s->count(),
            'taxable' => round((float) $s->sum(fn ($p) => $p->taxable_gross ?: $p->gross_pay), 2),
            'tds' => round((float) $s->sum('tds'), 2),
        ])->sortBy('name')->values();

        return response()->json(['data' => $rows, 'financial_year' => $fy, 'employer' => [
            'pan' => $company?->statutory['pan'] ?? null, 'tan' => $company?->statutory['tan'] ?? null,
        ]]);
    }

    public function form16(Request $request, Form16Service $form16)
    {
        $request->validate(['employee_id' => 'required|integer|exists:employees,id']);
        $employee = $this->authorizeEmployeeVisible($request->integer('employee_id'));

        return $this->form16Pdf($employee, $this->financialYear($request), $form16);
    }

    /** The signed-in employee's own statement. */
    public function myForm16(Request $request, Form16Service $form16)
    {
        $employee = $this->actorEmployee();
        abort_unless($employee, 422, 'No employee profile found for this user.');

        return $this->form16Pdf($employee, $this->financialYear($request), $form16);
    }

    private function form16Pdf(\App\Models\Employee $employee, string $fy, Form16Service $form16)
    {
        $data = $form16->build($employee->loadMissing('designation:id,title'), $fy, app(\App\Support\Tenancy\TenantContext::class)->company());
        abort_if($data['months']->isEmpty(), 422, "No finalized payroll for {$employee->full_name} in FY {$fy}.");
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.form16', ['f' => $data])->setPaper('a4');
        $name = "Form16-PartB-{$employee->employee_code}-{$fy}.pdf";

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }

    private function financialYear(Request $request): string
    {
        $request->validate(['financial_year' => ['nullable', 'regex:/^\d{4}-\d{2}$/']]);

        return $request->input('financial_year')
            ?: app(\App\Services\Payroll\TaxDeclarationService::class)->financialYear(app(\App\Support\Tenancy\TenantContext::class)->company());
    }

    /** @return array{0: list<string>, 1: Collection} */
    private function registerRows(array $register): array
    {
        $earnings = $register['columns']['earnings'];
        $deductions = $register['columns']['deductions'];

        $header = array_merge(
            ['Employee Code', 'Name', 'Designation', 'Department', 'Branch', 'Days in Period', 'Payable Days', 'LOP Days'],
            array_values($earnings), ['Gross'], array_values($deductions),
            ['Total Deductions', 'Net Pay', 'Employer PF', 'Employer ESI', 'Cost to Company'],
        );

        $rows = collect($register['rows'])->map(fn ($r) => array_merge(
            [$r['employee_code'], $r['name'], $r['designation'], $r['department'], $r['branch'], $r['days_in_period'], $r['payable_days'], $r['lop_days']],
            array_values($r['earnings']), [$r['gross']], array_values($r['deductions']),
            [$r['total_deductions'], $r['net_pay'], $r['employer_pf'], $r['employer_esi'], $r['ctc']],
        ));

        return [$header, $rows];
    }

    /** TDS can span a quarter of the financial year (?quarter=1..4 with year = FY start). */
    private function payslipsFor(Request $request, string $report, int $year, int $month, ?array $branches): Collection
    {
        if ($report !== 'tds' || ! $request->filled('quarter')) {
            return $this->reports->payslips($year, $month, $branches);
        }

        $quarter = max(1, min(4, $request->integer('quarter')));
        $start = CarbonImmutable::create($year, 4, 1)->addMonths(($quarter - 1) * 3);

        return collect(range(0, 2))
            ->flatMap(fn ($i) => $this->reports->payslips($start->addMonths($i)->year, $start->addMonths($i)->month, $branches))
            ->values();
    }

    /** @return array{0: int, 1: int, 2: list<int>|null} */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
            'quarter' => 'nullable|integer|min:1|max:4',
            'branch_id' => 'nullable|integer',
        ]);

        $branchId = $this->requestedBranchId();

        return [(int) $validated['year'], (int) $validated['month'], $branchId ? [$branchId] : $request->user()->accessibleBranchIds()];
    }
}
