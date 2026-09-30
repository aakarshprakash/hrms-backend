<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Payroll\PayrollRunService;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payroll runs (one per branch per month) and their lifecycle; see
 * PayrollRunService for the state machine.
 */
class PayrollRunController extends Controller
{
    public function __construct(private readonly PayrollRunService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $branchId = $this->requestedBranchId();

        $runs = PayrollRun::with('branch:id,name')
            ->withCount('payslips')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('year'), fn ($q) => $q->where('year', $request->integer('year')))
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', (string) $request->input('status'))))
            ->orderByDesc('year')->orderByDesc('month')->orderBy('branch_id')
            ->get();

        return response()->json(['data' => $runs]);
    }

    /**
     * POST /payroll-runs -- {branch_id, month, year}, or {all_branches: true,
     * month, year} to open the month for every branch the user manages.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required_without:all_branches', 'nullable', 'integer', 'exists:branches,id'],
            'all_branches' => ['sometimes', 'boolean'],
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $period = CarbonImmutable::create($validated['year'], $validated['month'], 1);
        abort_if($period->gt(now()->startOfMonth()->addMonth()), 422, 'Payroll can only be opened up to next month.');

        $allowed = $request->user()->accessibleBranchIds();
        $branchIds = ! empty($validated['all_branches'])
            ? Branch::when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->pluck('id')->all()
            : [(int) $validated['branch_id']];

        $created = [];
        foreach ($branchIds as $branchId) {
            $this->authorizeBranch($branchId, 'You are not allowed to manage payroll for this branch.');

            $exists = PayrollRun::where('branch_id', $branchId)
                ->where('month', $validated['month'])->where('year', $validated['year'])->exists();

            if ($exists) {
                if (empty($validated['all_branches'])) {
                    return response()->json(['message' => 'A payroll run for this branch, month and year already exists.'], 422);
                }
                continue;
            }

            $created[] = PayrollRun::create([
                'branch_id' => $branchId,
                'month' => $validated['month'],
                'year' => $validated['year'],
                'status' => 'draft',
                'notes' => $validated['notes'] ?? null,
            ]);
        }

        abort_if(empty($created), 422, 'Payroll for this month already exists for every branch.');

        return response()->json([
            'data' => count($created) === 1 ? $created[0] : $created,
            'message' => count($created) === 1 ? 'Payroll run created.' : count($created) . ' payroll runs created.',
        ], 201);
    }

    public function show(PayrollRun $run): JsonResponse
    {
        $run->load(['branch:id,name,payroll_days_in_month', 'runBy:id,name', 'finalizedBy:id,name'])->loadCount('payslips');

        return response()->json(['data' => $run]);
    }

    public function destroy(PayrollRun $run): JsonResponse
    {
        $this->authorizeRun($run);
        abort_unless($run->isEditable(), 422, 'A finalized or paid payroll run cannot be deleted. Reopen it first.');

        foreach (Payslip::where('payroll_run_id', $run->id)->whereNotNull('pdf_path')->pluck('pdf_path') as $path) {
            Storage::delete($path);
        }
        $run->delete();

        return response()->json(['message' => 'Payroll run deleted.']);
    }

    /** POST /payroll-runs/{run}/run -- compute (or recompute) every payslip. */
    public function run(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authorizeRun($run);
        $run = $this->service->process($run, $request->user());

        $warnings = $run->totals['warnings'] ?? 0;

        return response()->json([
            'data' => $run,
            'message' => "Payroll processed for {$run->totals['employees']} employee(s)."
                . ($warnings ? " {$warnings} warning(s) to review." : ' Review it, then finalize to publish payslips.'),
        ]);
    }

    /** Live, read-only computation -- nothing is saved. */
    public function preview(PayrollRun $run): JsonResponse
    {
        $this->authorizeRun($run);
        abort_unless($run->isEditable(), 422, 'Only a draft or processed run can be previewed.');

        $result = $this->service->preview($run);

        return response()->json(['data' => [
            'rows' => $result['rows']->map(fn ($r) => [
                'employee' => [
                    'id' => $r['employee']->id,
                    'employee_code' => $r['employee']->employee_code,
                    'name' => $r['employee']->full_name,
                ],
                'gross_pay' => $r['gross_pay'],
                'total_deductions' => $r['total_deductions'],
                'net_pay' => $r['net_pay'],
                'payable_days' => $r['payable_days'],
                'lop_days' => $r['lop_days'],
                'employer_cost' => $r['employer_cost'],
                'breakdown_json' => $r['breakdown_json'],
                'warnings' => $r['warnings'],
            ])->values(),
            'totals' => array_merge($result['totals'], [
                // legacy keys
                'employee_count' => $result['totals']['employees'],
                'total_gross' => $result['totals']['gross'],
                'total_deductions' => $result['totals']['deductions'],
                'total_net' => $result['totals']['net'],
            ]),
        ]]);
    }

    public function finalize(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authorizeRun($run);

        return response()->json([
            'data' => $this->service->finalize($run, $request->user()),
            'message' => 'Payroll finalized. Payslips are now visible to employees and the month\'s attendance is locked.',
        ]);
    }

    public function reopen(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authorizeRun($run);

        return response()->json([
            'data' => $this->service->reopen($run, $request->user()),
            'message' => 'Payroll reopened. Payslips are hidden from employees until you finalize again.',
        ]);
    }

    public function markPaid(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authorizeRun($run);
        $validated = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        return response()->json([
            'data' => $this->service->markPaid($run, $request->user(), $validated['payment_reference'] ?? null, $validated['paid_on'] ?? null),
            'message' => 'Payroll marked as paid.',
        ]);
    }

    public function status(PayrollRun $run): JsonResponse
    {
        $run->loadCount('payslips');

        return response()->json(['data' => [
            'id' => $run->id,
            'status' => $run->status,
            'payslips_count' => $run->payslips_count,
            'run_at' => $run->run_at,
            'totals' => $run->totals,
        ]]);
    }

    /**
     * GET /payroll-runs/{run}/bank-export -- salary transfer file (NEFT
     * bulk upload layout: beneficiary, account, IFSC, amount, narration).
     */
    public function bankExport(PayrollRun $run): StreamedResponse
    {
        $this->authorizeRun($run);
        abort_unless(in_array($run->status, ['finalized', 'paid'], true), 422, 'Finalize the payroll run before exporting the bank file.');

        $payslips = Payslip::with('employee')->where('payroll_run_id', $run->id)->where('net_pay', '>', 0)->get();
        $narration = 'Salary ' . CarbonImmutable::create($run->year, $run->month, 1)->format('M Y');
        $filename = "salary_transfer_{$run->year}_" . str_pad((string) $run->month, 2, '0', STR_PAD_LEFT) . "_branch{$run->branch_id}.csv";

        activity('payroll')->performedOn($run)->event('bank_export')->log('Bank transfer file exported');

        return response()->streamDownload(function () use ($payslips, $narration) {
            $out = fopen('php://output', 'w');
            Csv::put($out, ['Employee Code', 'Beneficiary Name', 'Account Number', 'IFSC', 'Bank', 'Amount', 'Payment Mode', 'Narration']);
            foreach ($payslips as $slip) {
                $e = $slip->employee;
                Csv::put($out, [
                    $e?->employee_code,
                    $e?->full_name,
                    $e?->bank_account_number ?? '',
                    $e?->bank_ifsc_code,
                    $e?->bank_name,
                    number_format((float) $slip->net_pay, 2, '.', ''),
                    $e?->payment_method === 'cash' ? 'CASH' : ($e?->payment_method === 'cheque' ? 'CHEQUE' : 'NEFT'),
                    $narration,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** GET /payroll/summary -- cost dashboard from processed and finalized runs. */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowedBranches = $user->accessibleBranchIds();
        $year = $request->integer('year', (int) date('Y'));
        $branchId = $this->requestedBranchId();

        $base = Payslip::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->where('payroll_runs.year', $year)
            ->whereIn('payroll_runs.status', ['processed', 'finalized', 'paid'])
            ->when($branchId, fn ($q) => $q->where('payroll_runs.branch_id', $branchId))
            ->when(! $branchId && $allowedBranches !== null, fn ($q) => $q->whereIn('payroll_runs.branch_id', $allowedBranches));

        $totals = (clone $base)->selectRaw('
            COALESCE(SUM(payslips.gross_pay),0) gross, COALESCE(SUM(payslips.net_pay),0) net,
            COALESCE(SUM(payslips.total_deductions),0) deductions, COALESCE(SUM(payslips.employer_cost),0) employer_cost,
            COALESCE(SUM(payslips.pf_employee + payslips.pf_employer + payslips.eps_employer),0) pf,
            COALESCE(SUM(payslips.esi_employee + payslips.esi_employer),0) esi,
            COALESCE(SUM(payslips.tds),0) tds, COALESCE(SUM(payslips.professional_tax),0) pt,
            COUNT(DISTINCT payslips.employee_id) employees')->first();

        $monthly = (clone $base)
            ->selectRaw('payroll_runs.month, SUM(payslips.gross_pay) gross_pay, SUM(payslips.net_pay) net_pay, SUM(payslips.total_deductions) total_deductions, SUM(payslips.employer_cost) employer_cost, COUNT(*) headcount')
            ->groupBy('payroll_runs.month')->orderBy('payroll_runs.month')
            ->get()
            ->map(fn ($r) => [
                'month' => (int) $r->month,
                'gross_pay' => (float) $r->gross_pay,
                'net_pay' => (float) $r->net_pay,
                'total_deductions' => (float) $r->total_deductions,
                'employer_cost' => (float) $r->employer_cost,
                'headcount' => (int) $r->headcount,
            ])->values();

        $byDepartment = (clone $base)
            ->join('employees', 'payslips.employee_id', '=', 'employees.id')
            ->leftJoin('departments', 'employees.department_id', '=', 'departments.id')
            ->selectRaw("COALESCE(departments.name, 'Unassigned') as name, COUNT(DISTINCT payslips.employee_id) as employee_count, SUM(payslips.gross_pay) as total_gross")
            ->groupBy(DB::raw("COALESCE(departments.name, 'Unassigned')"))
            ->orderByDesc('total_gross')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'employee_count' => (int) $r->employee_count, 'total_gross' => (float) $r->total_gross])
            ->values();

        return response()->json(['data' => [
            'total_gross' => (float) $totals->gross,
            'total_net' => (float) $totals->net,
            'total_deductions' => (float) $totals->deductions,
            'employer_cost' => (float) $totals->employer_cost,
            'statutory' => ['pf' => (float) $totals->pf, 'esi' => (float) $totals->esi, 'tds' => (float) $totals->tds, 'pt' => (float) $totals->pt],
            'employees_paid' => (int) $totals->employees,
            'monthly' => $monthly,
            'by_department' => $byDepartment,
        ]]);
    }

    private function authorizeRun(PayrollRun $run): void
    {
        // payroll.manage / payroll.finalize is enforced on the route.
        $this->authorizeBranch($run->branch_id, 'You are not allowed to manage payroll for this branch.');
    }
}
