<?php

namespace App\Services;

use App\Models\BiometricConfig;
use App\Models\BiometricSyncLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\Attendance\AttendanceProcessor;
use App\Services\Attendance\PunchRecorder;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Polls a branch's biometric provider and feeds the punch pipeline:
 *
 *   provider  --fetch-->  raw_punches (idempotent)  --process-->  attendance_daily
 *
 * Contract (the provider's "fetch-punches" API):
 *   POST {api_url}  Authorization: Bearer {api_token}
 *   body: { start_date, end_date, ins_code }
 *   response: [{ id, emp_code, punch_date_time, punch_date, punch_time }, ...]
 *
 * Punches carry the device's own emp_code, mapped to employees through
 * Employee::biometric_emp_code. Unmapped codes are stored anyway and
 * reported; once mapped, their punches are linked and processed without
 * fetching again.
 */
class BiometricAttendanceService
{
    public function __construct(
        private readonly PunchRecorder $recorder,
        private readonly AttendanceProcessor $processor,
    ) {
    }

    public function sync(Branch $branch, string $dateFrom, string $dateTo, ?User $triggeredBy = null): BiometricSyncLog
    {
        $config = BiometricConfig::where('branch_id', $branch->id)->first();

        if (! $config) {
            throw new RuntimeException('Biometric integration is not configured for this branch.');
        }

        if (! $config->enabled) {
            throw new RuntimeException('Biometric integration is disabled for this branch. Enable it first.');
        }

        try {
            $response = Http::withToken($config->api_token)
                ->timeout(30)
                ->retry(2, 1000, throw: false)
                ->acceptJson()
                ->post($config->api_url, [
                    'start_date' => $dateFrom,
                    'end_date' => $dateTo,
                    'ins_code' => $config->ins_code,
                ]);
        } catch (\Throwable $e) {
            return $this->logFailure($branch, $dateFrom, $dateTo, $triggeredBy, $config,
                'Could not reach the biometric provider: ' . $e->getMessage());
        }

        if ($response->failed()) {
            return $this->logFailure($branch, $dateFrom, $dateTo, $triggeredBy, $config,
                "Provider returned HTTP {$response->status()}.");
        }

        $punches = $response->json();

        if (! is_array($punches)) {
            return $this->logFailure($branch, $dateFrom, $dateTo, $triggeredBy, $config,
                'Unexpected response format from the biometric provider.');
        }

        $result = $this->recorder->recordFromDevice($branch, $punches, $config->id);
        $this->processor->processAffected($result['affected']);

        $daysProcessed = array_sum(array_map('count', $result['affected']));

        $config->update([
            'last_synced_at' => now(),
            'last_sync_status' => 'success',
            'last_sync_message' => "{$result['stored']} new punch(es), {$daysProcessed} employee-day(s) processed, "
                . count($result['unmatched_codes']) . ' unmatched code(s).',
        ]);

        return BiometricSyncLog::create([
            'branch_id' => $branch->id,
            'triggered_by' => $triggeredBy?->id,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'total_fetched' => count($punches),
            'matched_count' => $daysProcessed,
            'unmatched_count' => count($result['unmatched_codes']),
            'unmatched_codes' => $result['unmatched_codes'],
            'status' => 'success',
        ]);
    }

    private function logFailure(Branch $branch, string $dateFrom, string $dateTo, ?User $triggeredBy, BiometricConfig $config, string $message): never
    {
        $config->update([
            'last_synced_at' => now(),
            'last_sync_status' => 'failed',
            'last_sync_message' => $message,
        ]);

        BiometricSyncLog::create([
            'branch_id' => $branch->id,
            'triggered_by' => $triggeredBy?->id,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'status' => 'failed',
            'error_message' => $message,
        ]);

        throw new RuntimeException($message);
    }
}
