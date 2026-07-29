<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Payslip;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class PayslipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Payslip::with(['employee', 'payrollRun']);

        if ($request->filled('payroll_run_id')) {
            $query->where('payroll_run_id', $request->integer('payroll_run_id'));
        }

        if ($request->filled('employee_id')) {
            // Scoped lookup: 404s for a branch admin/HR reaching across branches.
            $employee = Employee::findOrFail($request->integer('employee_id'));
            $this->authorize('viewSalary', $employee);
            $query->where('employee_id', $employee->id);
        } else {
            // Unfiltered listing is a payroll-admin view, not for self-service.
            $user = $request->user();
            abort_unless(
                $user->is_super_admin || $user->hasAnyRole(['super_admin', 'branch_admin', 'hr']) || $user->can('payroll.view'),
                403
            );
        }

        $paginator = $query->orderByDesc('created_at')->paginate(20);

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
        $payslip->load(['employee', 'payrollRun']);
        $this->authorize('viewSalary', $this->employeeFor($payslip));
        return response()->json(['data' => $payslip]);
    }

    public function pdf(Payslip $payslip): Response
    {
        $payslip->load(['employee.branch', 'employee.designation', 'employee.department', 'payrollRun']);
        $this->authorize('viewSalary', $this->employeeFor($payslip));

        // Generate if pdf_path is null or file doesn't exist
        if (!$payslip->pdf_path || !Storage::exists($payslip->pdf_path)) {
            $pdf = Pdf::loadView('payslip', [
                'payslip' => $payslip,
                'employee' => $payslip->employee,
                'run' => $payslip->payrollRun,
            ]);

            $fileName = "payslips/{$payslip->payrollRun->year}/{$payslip->payrollRun->month}/payslip_{$payslip->employee?->employee_code}_{$payslip->payrollRun->year}_{$payslip->payrollRun->month}.pdf";
            Storage::put($fileName, $pdf->output());
            $payslip->pdf_path = $fileName;
            $payslip->save();

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"payslip.pdf\"",
            ]);
        }

        $content = Storage::get($payslip->pdf_path);
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"payslip.pdf\"",
        ]);
    }

    /**
     * Fetches the employee unscoped so authorization sees a real branch
     * mismatch (and can 403 it) instead of the belongsTo relation silently
     * filtering it to null via Employee's branch scope and crashing the
     * policy check.
     */
    private function employeeFor(Payslip $payslip): Employee
    {
        return Employee::withoutGlobalScopes()->findOrFail($payslip->employee_id);
    }
}
