<?php

namespace App\Jobs;

use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Processes a payroll run in the background -- for large branches on hosts
 * with a queue worker. The HTTP endpoint processes inline by default.
 */
class PayrollRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public function __construct(public PayrollRun $payrollRun, public ?int $userId = null)
    {
    }

    public function handle(PayrollRunService $service): void
    {
        // A queue worker has no request, so act explicitly as the run's tenant.
        app(TenantContext::class)->runAs($this->payrollRun->company_id, function () use ($service) {
            $by = User::find($this->userId) ?? User::where('company_id', $this->payrollRun->company_id)->first();
            $service->process($this->payrollRun->fresh(), $by);
        });
    }
}
