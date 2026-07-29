<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\PayrollCalculationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PayrollRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public PayrollRun $payrollRun) {}

    public function handle(PayrollCalculationService $calculationService): void
    {
        $run = $this->payrollRun;

        try {
            $run->status = 'processing';
            $run->save();

            // Get all active employees for the branch
            $employees = Employee::withoutGlobalScopes()
                ->where('branch_id', $run->branch_id)
                ->where('status', 'active')
                ->get();

            foreach ($employees as $employee) {
                $result = $calculationService->computeForEmployee($employee, $run);

                $payslip = Payslip::updateOrCreate(
                    [
                        'payroll_run_id' => $run->id,
                        'employee_id' => $employee->id,
                    ],
                    [
                        'gross_pay' => $result['gross_pay'],
                        'total_deductions' => $result['total_deductions'],
                        'net_pay' => $result['net_pay'],
                        'currency_code' => $employee->branch?->currency_code ?? 'INR',
                        'breakdown_json' => $result['breakdown_json'],
                    ]
                );

                // Generate PDF
                try {
                    $pdfPath = $this->generatePayslipPdf($payslip, $employee, $run);
                    $payslip->pdf_path = $pdfPath;
                    $payslip->save();
                } catch (\Throwable $e) {
                    Log::warning("Failed to generate PDF for payslip {$payslip->id}: " . $e->getMessage());
                }
            }

            $run->status = 'completed';
            $run->run_at = now();
            $run->save();
        } catch (\Throwable $e) {
            Log::error("PayrollRunJob failed for run #{$run->id}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            $run->status = 'draft';
            $run->save();
            throw $e;
        }
    }

    private function generatePayslipPdf(Payslip $payslip, Employee $employee, PayrollRun $run): string
    {
        $payslip->load(['employee.branch', 'employee.designation', 'employee.department', 'payrollRun']);

        $pdf = Pdf::loadView('payslip', [
            'payslip' => $payslip,
            'employee' => $employee->load(['branch', 'designation', 'department']),
            'run' => $run,
        ]);

        $fileName = "payslips/{$run->year}/{$run->month}/payslip_{$employee->employee_code}_{$run->year}_{$run->month}.pdf";

        Storage::put($fileName, $pdf->output());

        return $fileName;
    }
}
