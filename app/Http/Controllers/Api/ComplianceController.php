<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

    public function download(Request $request, string $report): StreamedResponse
    {
        abort_unless(array_key_exists($report, StatutoryReports::REPORTS), 404);
        [$year, $month, $branches] = $this->period($request);
        $payslips = $this->payslipsFor($request, $report, $year, $month, $branches);
        abort_if($payslips->isEmpty(), 422, 'There is no finalized payroll for this period yet.');

        $stamp = $request->filled('quarter') ? "{$year}-Q{$request->integer('quarter')}" : sprintf('%04d%02d', $year, $month);

        activity('payroll')->causedBy($request->user())->event('compliance_export')
            ->withProperties(['report' => $report, 'period' => $stamp, 'branches' => $branches])
            ->log(StatutoryReports::REPORTS[$report] . " exported ({$stamp})");

        if ($report === 'pf-ecr') {
            $content = $this->reports->pfEcr($payslips);

            return response()->streamDownload(function () use ($content) {
                echo $content;
            }, "ECR_{$stamp}.txt", ['Content-Type' => 'text/plain']);
        }

        [$header, $rows] = match ($report) {
            'pf' => [
                ['Employee Code', 'UAN', 'Member Name', 'Gross Wages', 'EPF Wages', 'EPS Wages', 'EDLI Wages', 'EPF (Employee)', 'EPS (Employer)', 'EPF (Employer)', 'NCP Days'],
                collect($this->reports->pf($payslips))->map(fn ($r) => [
                    $r['employee_code'], $r['uan'], $r['name'], $r['gross_wages'], $r['epf_wages'], $r['eps_wages'], $r['edli_wages'],
                    $r['epf_contribution'], $r['eps_contribution'], $r['epf_eps_difference'], $r['ncp_days'],
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

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            Csv::put($out, $header);
            foreach ($rows as $row) {
                Csv::put($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
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
