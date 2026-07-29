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

    public function handle(BiometricAttendanceService $service): int
    {
        $date = $this->option('date') ?: Carbon::today()->toDateString();

        $configs = BiometricConfig::where('enabled', true)->with('branch')->get();

        if ($configs->isEmpty()) {
            $this->info('No branch has biometric sync enabled.');
            return self::SUCCESS;
        }

        foreach ($configs as $config) {
            try {
                $log = $service->sync($config->branch, $date, $date);
                $this->info("[{$config->branch->name}] synced: {$log->matched_count} day(s), {$log->unmatched_count} unmatched code(s).");
            } catch (\Throwable $e) {
                $this->error("[{$config->branch->name}] failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
