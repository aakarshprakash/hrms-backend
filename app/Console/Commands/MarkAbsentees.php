<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use App\Services\Attendance\AttendanceProcessor;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Nightly close of the attendance day: processes "yesterday" for every
 * branch, in that branch's own timezone, so anyone with no punches on a
 * working day becomes absent (and weekly offs / holidays / leave are filled
 * in). Runs hourly from the scheduler; each run only acts on branches whose
 * local day has rolled over, and processing is idempotent.
 *
 * Kept under its original name for the existing scheduler/cron entries.
 */
class MarkAbsentees extends Command
{
    protected $signature = 'attendance:mark-absentees {--date=} {--from=} {--to=} {--branch_id=}';

    protected $description = 'Close attendance days: derive absent / weekly-off / holiday / leave rows (per branch timezone)';

    public function handle(AttendanceProcessor $processor, TenantContext $context): int
    {
        $branches = Branch::withoutGlobalScope(BranchScope::class)
            ->when($this->option('branch_id'), fn ($q, $id) => $q->where('id', $id))
            ->whereHas('company', fn ($q) => $q->where('status', 'active'))
            ->get();

        if ($branches->isEmpty()) {
            $this->error('No matching branch found.');

            return self::FAILURE;
        }

        $total = 0;

        $unattended = ! $this->option('date') && ! $this->option('from') && ! $this->option('branch_id');

        foreach ($branches as $branch) {
            [$from, $to] = $this->rangeFor($branch);

            // Scheduled hourly so each branch closes soon after its own
            // midnight -- but each branch-day only needs closing once.
            if ($unattended && ! Cache::add("attendance-closed:{$branch->id}:{$to}", true, now()->addDays(2))) {
                continue;
            }

            $total += $context->runAs($branch->company_id, function () use ($processor, $branch, $from, $to) {
                $employees = AttendanceProcessor::employeesQuery($branch->id)->get();

                return $processor->processEmployees($employees, $from, $to)['processed'];
            });
        }

        $this->info("Processed {$total} employee-day(s).");

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string} */
    private function rangeFor(Branch $branch): array
    {
        if ($this->option('from') && $this->option('to')) {
            return [Carbon::parse($this->option('from'))->toDateString(), Carbon::parse($this->option('to'))->toDateString()];
        }

        if ($this->option('date')) {
            $d = Carbon::parse($this->option('date'))->toDateString();

            return [$d, $d];
        }

        $yesterday = Carbon::now($branch->timezone ?: 'UTC')->subDay()->toDateString();

        return [$yesterday, $yesterday];
    }
}
