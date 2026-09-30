<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Leave\LeaveAccrualService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Credits leave by each type's policy and closes out finished leave years,
 * tenant by tenant. Idempotent -- each balance is credited once per period
 * -- so it runs daily and can be re-run (or run for a past date to catch
 * up) without double-crediting anyone.
 */
class AccrueLeaveBalances extends Command
{
    protected $signature = 'hrms:accrue-leave-balances
                            {--company= : Only this company id}
                            {--date= : Accrue as of this date (default: today)}';

    protected $description = 'Credit leave balances by policy (annual / monthly) and carry forward at year end';

    public function handle(LeaveAccrualService $accrual, TenantContext $context): int
    {
        $companies = $context->withoutScoping(fn () => Company::query()
            ->whereNull('suspended_at')
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))
            ->get(['id', 'name', 'timezone']));

        foreach ($companies as $company) {
            $asOf = $this->option('date')
                ? CarbonImmutable::parse($this->option('date'))
                : CarbonImmutable::now($company->timezone ?: config('app.timezone'));

            $stats = $context->runAs($company->id, fn () => $accrual->syncAll($asOf));

            $this->info("{$company->name}: {$stats['employees']} employee(s), {$stats['credits']} credit(s) as of {$asOf->toDateString()}.");
        }

        return self::SUCCESS;
    }
}
