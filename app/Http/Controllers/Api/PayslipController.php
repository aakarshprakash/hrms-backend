<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Payslip;
use App\Models\Scopes\BranchScope;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Payslips. Payroll staff see every payslip in their scope, including ones
 * still under review; everyone sees their own -- once the run is finalized
 * (published).
 */
class PayslipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Payslip::with(['employee:id,employee_code,first_name,last_name,department_id,designation_id', 'payrollRun:id,branch_id,month,year,status']);

        $isPayrollStaff = $user->can('payroll.view') || $user->can('payroll.manage');

        if ($request->filled('employee_id') && (int) $request->input('employee_id') !== $user->employee_id) {
            $employee = $this->authorizeEmployeeVisible($request->integer('employee_id'));
            $this->authorize('viewSalary', $employee);
            $query->where('employee_id', $employee->id);
        } elseif ($request->boolean('mine') || ! $isPayrollStaff || $request->filled('employee_id')) {
            $query->where('employee_id', $user->employee_id ?? 0)->published();
        } else {
            $query->visibleTo($user);
        }

        $query->when($request->filled('payroll_run_id'), fn ($q) => $q->where('payroll_run_id', $request->integer('payroll_run_id')))
            ->when($request->filled('year'), fn ($q) => $q->whereHas('payrollRun', fn ($r) => $r->where('year', $request->integer('year'))));

        $paginator = $query->orderByDesc('created_at')->paginate(min(max($request->integer('per_page', 20), 1), 200));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function show(Payslip $payslip): JsonResponse
    {
        $this->authorizePayslip($payslip);

        return response()->json(['data' => $payslip->load(['employee', 'payrollRun'])]);
    }

    public function pdf(Payslip $payslip, PayrollRunService $service): Response
    {
        $this->authorizePayslip($payslip);

        $path = $service->pdfPath($payslip);
        $run = $payslip->payrollRun;
        $name = "Payslip-{$payslip->employee?->employee_code}-{$run->year}-" . str_pad((string) $run->month, 2, '0', STR_PAD_LEFT) . '.pdf';

        return response(Storage::get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$name}\"",
        ]);
    }

    /** Salary visibility per policy, and never an unpublished payslip for its own employee. */
    private function authorizePayslip(Payslip $payslip): void
    {
        $user = request()->user();
        $employee = Employee::withoutGlobalScope(BranchScope::class)->findOrFail($payslip->employee_id);

        $this->authorize('viewSalary', $employee);

        $isPayrollStaff = ($user->can('payroll.view') || $user->can('payroll.manage')) && $employee->id !== $user->employee_id;
        abort_if(! $isPayrollStaff && $payslip->published_at === null, 404, 'Payslip not found.');
    }
}
