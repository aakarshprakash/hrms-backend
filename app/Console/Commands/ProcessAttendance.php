<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Attendance\AttendanceProcessor;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * (Re)derive attendance_daily from raw punches and current shift rules --
 * after changing a shift, fixing a roster or mapping device codes. Never
 * re-fetches from the device provider, never touches manual or
 * payroll-locked days.
 */
class ProcessAttendance extends Command
{
    protected $signature = 'attendance:process
        {--company= : Company id or slug (default: all)}
        {--branch= : Branch id}
        {--employee= : Employee id}
        {--from= : Start date (default: yesterday)}
        {--to= : End date (default: same as --from)}';

    protected $description = 'Process raw punches into daily attendance for a date range';

    public function handle(AttendanceProcessor $processor, TenantContext $context): int
    {
        $from = $this->option('from') ?: CarbonImmutable::yesterday()->toDateString();
        $to = $this->option('to') ?: $from;

        if ($to < $from) {
            $this->error('--to must be on or after --from.');

            return self::FAILURE;
        }

        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $c) => ctype_digit((string) $c) ? $q->whereKey($c) : $q->where('slug', $c))
            ->where('status', Company::STATUS_ACTIVE)
            ->get();

        $totals = ['processed' => 0, 'skipped' => 0, 'cleared' => 0];

        foreach ($companies as $company) {
            $context->runAs($company->id, function () use ($processor, $from, $to, &$totals) {
                $employees = AttendanceProcessor::employeesQuery($this->option('branch') ? (int) $this->option('branch') : null)
                    ->when($this->option('employee'), fn ($q, $id) => $q->whereKey($id))
                    ->get();

                foreach ($processor->processEmployees($employees, $from, $to) as $k => $v) {
                    $totals[$k] += $v;
                }
            });
        }

        $this->info("Attendance {$from} → {$to}: {$totals['processed']} day(s) processed, {$totals['skipped']} protected (manual/locked), {$totals['cleared']} cleared.");

        return self::SUCCESS;
    }
}
