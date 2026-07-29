<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\BiometricConfig;
use App\Models\BiometricSyncLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Pulls punch data from the branch's configured biometric device provider
 * (see BiometricConfig) and turns it into Attendance records.
 *
 * Contract (per the provider's "fetch-punches" API):
 *   POST {api_url}  Authorization: Bearer {api_token}
 *   body: { start_date, end_date, ins_code }
 *   response: [{ id, emp_code, punch_date_time, punch_date, punch_time }, ...]
 *
 * Matching: punches carry the device's own emp_code, which rarely matches our
 * employee_code, so employees must be pre-mapped via
 * Employee::biometric_emp_code. Unmatched codes are reported back rather than
 * silently dropped so an admin can map them.
 */
class BiometricAttendanceService
{
    public function __construct(
        private readonly AttendanceStatusResolver $statusResolver,
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

        // Group punches by device emp_code + calendar date, then take the
        // earliest as check-in and the latest as check-out for that day.
        $grouped = [];
        foreach ($punches as $punch) {
            $empCode = (string) ($punch['emp_code'] ?? '');
            $date = $punch['punch_date'] ?? null;
            $dateTime = $punch['punch_date_time'] ?? null;

            if ($empCode === '' || ! $date || ! $dateTime) {
                continue;
            }

            $grouped[$empCode][$date][] = $dateTime;
        }

        $employeesByCode = Employee::where('branch_id', $branch->id)
            ->whereNotNull('biometric_emp_code')
            ->whereIn('biometric_emp_code', array_keys($grouped))
            ->get()
            ->keyBy('biometric_emp_code');

        $matchedCount = 0;
        $unmatchedCodes = [];

        foreach ($grouped as $empCode => $dates) {
            $employee = $employeesByCode->get($empCode);

            if (! $employee) {
                $unmatchedCodes[] = $empCode;
                continue;
            }

            foreach ($dates as $date => $timestamps) {
                sort($timestamps);
                // The device stamps punches in the branch's own local time,
                // not UTC — parse as that zone, then convert to UTC so it's
                // the actual value written to the database.
                $checkIn = Carbon::parse($timestamps[0], $branch->timezone)->utc();
                $checkOut = count($timestamps) > 1 ? Carbon::parse($timestamps[count($timestamps) - 1], $branch->timezone)->utc() : null;

                $existing = Attendance::where('employee_id', $employee->id)
                    ->where('date', $date)
                    ->first();

                // Never clobber a manual HR correction with device data.
                if ($existing && $existing->source === 'manual') {
                    continue;
                }

                $resolved = $this->statusResolver->resolve($employee, $date, $checkIn, $checkOut);

                Attendance::updateOrCreate(
                    ['employee_id' => $employee->id, 'date' => $date],
                    [
                        'check_in' => $checkIn,
                        'check_out' => $checkOut,
                        'shift_id' => $resolved['shift_id'],
                        'status' => $resolved['status'],
                        'late_by_minutes' => $resolved['late_by_minutes'],
                        'early_by_minutes' => $resolved['early_by_minutes'],
                        'worked_minutes' => $resolved['worked_minutes'],
                        'source' => 'api',
                    ]
                );

                $matchedCount++;
            }
        }

        $config->update([
            'last_synced_at' => now(),
            'last_sync_status' => 'success',
            'last_sync_message' => "{$matchedCount} day(s) synced, " . count($unmatchedCodes) . ' unmatched code(s).',
        ]);

        return BiometricSyncLog::create([
            'branch_id' => $branch->id,
            'triggered_by' => $triggeredBy?->id,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'total_fetched' => count($punches),
            'matched_count' => $matchedCount,
            'unmatched_count' => count($unmatchedCodes),
            'unmatched_codes' => array_values(array_unique($unmatchedCodes)),
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
