<?php

namespace App\Console\Commands;

use App\Models\BiometricConfig;
use App\Services\BiometricAttendanceService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncBiometricAttendance extends Command
{
    protected $signature = 'biometric:sync {--date= : Date to sync (YYYY-MM-DD), defaults to today}';

    protected $description = 'Pull attendance punches from each enabled branch biometric device provider';

    public function handle(BiometricAttendanceService $service, \App\Support\Tenancy\TenantContext $context): int
    {
        // Yesterday too: late uploads from the device and night-shift
        // out-punches after midnight still land on the right day.
        $to = $this->option('date') ?: Carbon::today()->toDateString();
        $from = $this->option('date') ?: Carbon::yesterday()->toDateString();

        $configs = BiometricConfig::where('enabled', true)
            ->whereHas('branch.company', fn ($q) => $q->where('status', 'active'))
            ->with('branch')
            ->get();

        if ($configs->isEmpty()) {
            $this->info('No branch has biometric sync enabled.');
            return self::SUCCESS;
        }

        foreach ($configs as $config) {
            try {
                $log = $context->runAs($config->company_id, fn () => $service->sync($config->branch, $from, $to));
                $this->info("[{$config->branch->name}] {$log->total_fetched} punch(es) fetched, {$log->matched_count} employee-day(s) processed, {$log->unmatched_count} unmatched code(s).");
            } catch (\Throwable $e) {
                $this->error("[{$config->branch->name}] failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
