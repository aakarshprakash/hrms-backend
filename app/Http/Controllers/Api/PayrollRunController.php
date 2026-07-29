<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PayrollRunJob;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class PayrollRunController extends Controller
{
    private function assertCanManageBranch(int $branchId): void
    {
        $user = request()->user();
        if ($user->is_super_admin || $user->hasRole('super_admin')) {
            return;
        }
        abort_unless(
            ($user->hasAnyRole(['branch_admin', 'hr']) || $user->can('payroll.manage'))
                && ($user->branch_id === null || $user->branch_id === $branchId),
            403,
            'You are not allowed to manage payroll for this branch.'
        );
    }

    private function assertCanManage(PayrollRun $run): void
    {
        $this->assertCanManageBranch($run->branch_id);
    }

    public function index(Request $request): JsonResponse
    {
        $runs = PayrollRun::withCount('payslips')->orderByDesc('year')->orderByDesc('month')->get();
        return response()->json(['data' => $runs]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2000|max:2100',
        ]);

        $this->assertCanManageBranch($validated['branch_id']);

        $exists = PayrollRun::withoutGlobalScopes()
            ->where('branch_id', $validated['branch_id'])
            ->where('month', $validated['month'])
            ->where('year', $validated['year'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'A payroll run for this branch, month and year already exists.'], 422);
        }

        $run = PayrollRun::create([
            'branch_id' => $validated['branch_id'],
            'month' => $validated['month'],
            'year' => $validated['year'],
            'status' => 'draft',
        ]);

        return response()->json(['data' => $run, 'message' => 'Payroll run created.'], 201);
    }

    public function show(PayrollRun $run): JsonResponse
    {
        $run->load('branch')->loadCount('payslips');
        return response()->json(['data' => $run]);
    }

    public function destroy(PayrollRun $run): JsonResponse
    {
        $this->assertCanManage($run);
        abort_unless(
            $run->status !== 'processing',
            422,
            'This payroll run is currently processing and cannot be deleted.'
        );

        // Payslip rows cascade-delete with the run at the DB level, but the
        // generated PDFs on disk don't -- clean those up first so we don't
        // leave orphaned files behind.
        $pdfPaths = Payslip::withoutGlobalScopes()
            ->where('payroll_run_id', $run->id)
            ->whereNotNull('pdf_path')
            ->pluck('pdf_path');

        foreach ($pdfPaths as $path) {
            Storage::delete($path);
        }

        $run->delete();

        return response()->json(['message' => 'Payroll run deleted.']);
    }

    public function run(Request $request, PayrollRun $run): JsonResponse
    {
        $this->assertCanManage($run);

        if ($run->status === 'processing') {
            return response()->json(['message' => 'Payroll run is already processing.'], 422);
        }

        $run->run_by = $request->user()->id;
        $run->save();

        try {
            // Run inline rather than queueing: this app has no persistent queue
            // worker running under WAMP, so a queued dispatch would silently
            // never execute.
            PayrollRunJob::dispatchSync($run);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Payroll run failed: ' . $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Payroll run completed.', 'data' => $run->fresh()]);
    }

    public function status(PayrollRun $run): JsonResponse
    {
        $run->loadCount('payslips');
        return response()->json([
            'data' => [
                'id' => $run->id,
                'status' => $run->status,
                'payslips_count' => $run->payslips_count,
                'run_at' => $run->run_at,
            ]
        ]);
    }

    public function bankExport(PayrollRun $run): Response
    {
        $this->assertCanManage($run);

        $payslips = Payslip::withoutGlobalScopes()
            ->where('payroll_run_id', $run->id)
            ->with('employee')
            ->get();

        $csvLines = ['employee_code,name,bank_account,ifsc,net_pay'];

        foreach ($payslips as $payslip) {
            $employee = $payslip->employee;
            $name = $employee ? "{$employee->first_name} {$employee->last_name}" : '';
            $code = $employee?->employee_code ?? '';
            // Bank account and IFSC would come from employee profile — placeholder for now
            $csvLines[] = implode(',', [
                $code,
                '"' . str_replace('"', '""', $name) . '"',
                '', // bank_account
                '', // ifsc
                $payslip->net_pay,
            ]);
        }

        $csv = implode("\n", $csvLines);
        $filename = "bank_export_{$run->year}_{$run->month}.csv";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = $user->is_super_admin || $user->hasRole('super_admin');
        abort_unless(
            $isSuperAdmin || $user->hasAnyRole(['branch_admin', 'hr', 'manager']) || $user->can('payroll.view'),
            403
        );

        $year     = $request->integer('year', (int) date('Y'));
        // Non-super-admins can only ever see their own branch's numbers,
        // regardless of what branch_id they pass in.
        $branchId = $isSuperAdmin ? ($request->integer('branch_id', 0) ?: null) : $user->branch_id;

        $payslipQuery = Payslip::withoutGlobalScopes()
            ->whereHas('payrollRun', function ($q) use ($year, $branchId) {
                $q->where('year', $year);
                if ($branchId) {
                    $q->where('branch_id', $branchId);
                }
            });

        $totalGross      = (float) (clone $payslipQuery)->sum('gross_pay');
        $totalNet        = (float) (clone $payslipQuery)->sum('net_pay');
        $totalDeductions = (float) (clone $payslipQuery)->sum('total_deductions');
        $employeesPaid   = (clone $payslipQuery)->distinct('employee_id')->count('employee_id');

        // Monthly breakdown
        $monthlyRaw = (clone $payslipQuery)
            ->selectRaw('MONTH(created_at) as month, SUM(gross_pay) as gross_pay, SUM(net_pay) as net_pay, SUM(total_deductions) as total_deductions')
            ->groupByRaw('MONTH(created_at)')
            ->orderByRaw('MONTH(created_at)')
            ->get()
            ->map(fn ($r) => [
                'month'            => (int) $r->month,
                'gross_pay'        => (float) $r->gross_pay,
                'net_pay'          => (float) $r->net_pay,
                'total_deductions' => (float) $r->total_deductions,
            ])
            ->values();

        // Department breakdown
        $byDepartment = (clone $payslipQuery)
            ->join('employees', 'payslips.employee_id', '=', 'employees.id')
            ->join('departments', 'employees.department_id', '=', 'departments.id')
            ->selectRaw('departments.name, COUNT(DISTINCT payslips.employee_id) as employee_count, SUM(payslips.gross_pay) as total_gross')
            ->groupBy('departments.id', 'departments.name')
            ->orderByDesc('total_gross')
            ->get()
            ->map(fn ($r) => [
                'name'           => $r->name,
                'employee_count' => (int) $r->employee_count,
                'total_gross'    => (float) $r->total_gross,
            ])
            ->values();

        return response()->json([
            'data' => [
                'total_gross'      => $totalGross,
                'total_net'        => $totalNet,
                'total_deductions' => $totalDeductions,
                'employees_paid'   => $employeesPaid,
                'monthly'          => $monthlyRaw,
                'by_department'    => $byDepartment,
            ]
        ]);
    }
}
