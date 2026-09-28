<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Superseded by attendance:process (which reprocesses from raw punches with
 * the full shift rules). Kept as a thin alias so existing runbooks work.
 */
class RecomputeAttendanceStatus extends Command
{
    protected $signature = 'attendance:recompute-status {--branch_id=} {--from=} {--to=}';

    protected $description = 'Alias of attendance:process (recompute daily attendance from punches)';

    public function handle(): int
    {
        return $this->call('attendance:process', array_filter([
            '--branch' => $this->option('branch_id'),
            '--from' => $this->option('from') ?? now()->subDays(30)->toDateString(),
            '--to' => $this->option('to') ?? now()->toDateString(),
        ]));
    }
}
