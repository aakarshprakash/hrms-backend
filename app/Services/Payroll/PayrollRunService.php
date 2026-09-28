<?php

namespace App\Services\Payroll;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\Notifications\NotificationMessages;
use App\Services\Notifications\Notifier;
use App\Support\Tenancy\TenantStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Payroll run lifecycle:
 *
 *   draft ──process──▶ processed ──finalize──▶ finalized ──mark paid──▶ paid
 *     ▲                   │  ▲                     │
 *     └──(edit inputs)────┘  └──────reopen─────────┘
 *
 * processed  payslips computed and reviewable; employees can't see them yet;
 *            re-processing replaces them.
 * finalized  the period's attendance is locked (reprocessing and manual edits
 *            skip it) and payslips are published to employees.
 * paid       salaries disbursed; the run is closed.
 */
class PayrollRunService
{
    public const EDITABLE = ['draft', 'processed'];

    public function __construct(
        private readonly PayrollEngine $engine,
        private readonly PayslipPresenter $presenter,
    ) {
    }

    /** Everyone employed at the branch at any point in the run's month. */
    public function employeesFor(PayrollRun $run): Collection
    {
        [$start, $end] = $this->period($run);

        return Employee::withoutGlobalScope(BranchScope::class)
            ->with('branch')
            ->where('branch_id', $run->branch_id)
            ->where(fn ($q) => $q->whereNull('date_of_joining')->orWhere('date_of_joining', '<=', $end))
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('status', 'active')->where(fn ($l) => $l->whereNull('date_of_leaving')->orWhere('date_of_leaving', '>=', $start)))
                ->orWhere(fn ($t) => $t->whereNotNull('date_of_leaving')->where('date_of_leaving', '>=', $start)))
            ->orderBy('first_name')
            ->get();
    }

    /** Compute everyone without saving anything. */
    public function preview(PayrollRun $run): array
    {
        $context = $this->engine->contextFor($run);

        $rows = $this->employeesFor($run)->map(fn (Employee $e) => ['employee' => $e] + $this->engine->compute($e, $run, $context));

        return ['rows' => $rows, 'totals' => $this->totals($rows)];
    }

    public function process(PayrollRun $run, User $by): PayrollRun
    {
        $this->assertStatus($run, self::EDITABLE, 'Only a draft or processed run can be (re)processed.');

        $previous = $run->status;
        $claimed = PayrollRun::whereKey($run->id)->whereIn('status', self::EDITABLE)->update(['status' => 'processing']);
        abort_if($claimed === 0, 409, 'This payroll run is already being processed.');

        try {
            $context = $this->engine->contextFor($run);
            $employees = $this->employeesFor($run);
            $rows = collect();
            // Collected before recomputing: the upserts below clear pdf_path.
            $stalePdfs = Payslip::where('payroll_run_id', $run->id)->whereNotNull('pdf_path')->pluck('pdf_path');

            DB::transaction(function () use ($run, $employees, $context, &$rows) {
                foreach ($employees as $employee) {
                    $result = $this->engine->compute($employee, $run, $context);
                    $rows->push($result);

                    Payslip::updateOrCreate(
                        ['payroll_run_id' => $run->id, 'employee_id' => $employee->id],
                        array_merge(array_diff_key($result, ['warnings' => true]), ['pdf_path' => null, 'published_at' => null])
                    );
                }

                // A re-run after someone left the period removes their stale payslip.
                Payslip::where('payroll_run_id', $run->id)->whereNotIn('employee_id', $employees->pluck('id'))->delete();
            });

            foreach ($stalePdfs as $path) {
                Storage::delete($path);
            }

            $run->forceFill([
                'status' => 'processed',
                'run_by' => $by->id,
                'run_at' => now(),
                'processed_at' => now(),
                'totals' => $this->totals($rows),
            ])->save();
        } catch (\Throwable $e) {
            PayrollRun::whereKey($run->id)->update(['status' => $previous]);
            throw $e;
        }

        return $run->fresh();
    }

    public function finalize(PayrollRun $run, User $by): PayrollRun
    {
        $this->assertStatus($run, ['processed'], 'Process the payroll run before finalizing it.');
        abort_if(Payslip::where('payroll_run_id', $run->id)->doesntExist(), 422, 'This run has no payslips.');

        [$start, $end] = $this->period($run);

        DB::transaction(function () use ($run, $by, $start, $end) {
            Attendance::whereIn('employee_id', Payslip::where('payroll_run_id', $run->id)->select('employee_id'))
                ->whereBetween('date', [$start, $end])
                ->update(['locked_at' => now()]);

            Payslip::where('payroll_run_id', $run->id)->update(['published_at' => now()]);

            $run->forceFill(['status' => 'finalized', 'finalized_at' => now(), 'finalized_by' => $by->id])->save();
        });

        activity('payroll')->performedOn($run)->causedBy($by)->event('finalized')
            ->withProperties(['attributes' => ['status' => 'finalized'] + ($run->totals ?? [])])->log('Payroll finalized');

        $this->notifyPayslips($run);

        return $run->fresh();
    }

    /** "Your payslip is ready" to everyone in a finalized run. */
    private function notifyPayslips(PayrollRun $run): void
    {
        try {
            $payslips = Payslip::with('employee:id,first_name,last_name')->where('payroll_run_id', $run->id)->get();
            $users = User::query()->whereIn('employee_id', $payslips->pluck('employee_id'))->where('is_active', true)->get()->keyBy('employee_id');
            $messages = app(NotificationMessages::class);
            $notifier = app(Notifier::class);

            foreach ($payslips as $payslip) {
                if (($user = $users->get($payslip->employee_id)) && $payslip->employee) {
                    $notifier->send($user, $messages->payslipPublished($payslip, $run, $payslip->employee));
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Back to processed for corrections -- only before salaries are paid. */
    public function reopen(PayrollRun $run, User $by): PayrollRun
    {
        $this->assertStatus($run, ['finalized'], 'Only a finalized (unpaid) run can be reopened.');
        [$start, $end] = $this->period($run);

        DB::transaction(function () use ($run, $start, $end) {
            Attendance::whereIn('employee_id', Payslip::where('payroll_run_id', $run->id)->select('employee_id'))
                ->whereBetween('date', [$start, $end])
                ->update(['locked_at' => null]);

            Payslip::where('payroll_run_id', $run->id)->update(['published_at' => null]);

            $run->forceFill(['status' => 'processed', 'finalized_at' => null, 'finalized_by' => null])->save();
        });

        activity('payroll')->performedOn($run)->causedBy($by)->event('reopened')->log('Payroll reopened');

        return $run->fresh();
    }

    public function markPaid(PayrollRun $run, User $by, ?string $reference, ?string $paidOn): PayrollRun
    {
        $this->assertStatus($run, ['finalized'], 'Finalize the run before marking it paid.');

        $run->forceFill([
            'status' => 'paid',
            'paid_at' => $paidOn ? CarbonImmutable::parse($paidOn) : now(),
            'payment_reference' => $reference,
        ])->save();

        activity('payroll')->performedOn($run)->causedBy($by)->event('paid')
            ->withProperties(['attributes' => ['payment_reference' => $reference]])->log('Payroll marked paid');

        return $run->fresh();
    }

    /** Generate (or reuse) a payslip's PDF; returns the storage path. */
    public function pdfPath(Payslip $payslip): string
    {
        if ($payslip->pdf_path && Storage::exists($payslip->pdf_path)) {
            return $payslip->pdf_path;
        }

        $run = $payslip->payrollRun;
        $pdf = Pdf::loadView('payslip', ['p' => $this->presenter->present($payslip)])->setPaper('a4');
        $path = TenantStorage::path($payslip->company_id,
            "payslips/{$run->year}/" . str_pad((string) $run->month, 2, '0', STR_PAD_LEFT) . "/payslip_{$payslip->employee?->employee_code}_{$payslip->id}.pdf");

        Storage::put($path, $pdf->output());
        $payslip->forceFill(['pdf_path' => $path])->saveQuietly();

        return $path;
    }

    public function totals(Collection $rows): array
    {
        $sum = fn (string $key) => round((float) $rows->sum($key), 2);

        return [
            'employees' => $rows->count(),
            'gross' => $sum('gross_pay'),
            'deductions' => $sum('total_deductions'),
            'net' => $sum('net_pay'),
            'employer_cost' => $sum('employer_cost'),
            'pf_employee' => $sum('pf_employee'),
            'pf_employer' => round($sum('pf_employer') + $sum('eps_employer'), 2),
            'esi_employee' => $sum('esi_employee'),
            'esi_employer' => $sum('esi_employer'),
            'professional_tax' => $sum('professional_tax'),
            'tds' => $sum('tds'),
            'lop_days' => $sum('lop_days'),
            'warnings' => $rows->sum(fn ($r) => count($r['warnings'] ?? [])),
        ];
    }

    /** @return array{0: string, 1: string} */
    public function period(PayrollRun $run): array
    {
        $start = CarbonImmutable::create($run->year, $run->month, 1);

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }

    private function assertStatus(PayrollRun $run, array $allowed, string $message): void
    {
        if (! in_array($run->status, $allowed, true)) {
            throw new HttpException(422, $message);
        }
    }

}
