<?php

namespace App\Services\Attendance;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\RawPunch;
use App\Models\Scopes\BranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The one way punches enter the system. Idempotent: the same punch (same
 * source, employee/device code and instant -- or the provider's own record
 * id) is stored once however many times it is received, so re-polling a
 * device for a day it already synced is harmless.
 */
class PunchRecorder
{
    /**
     * A punch from inside the app (web / mobile / kiosk / regularization),
     * where the employee is known and the instant is "now" or given.
     */
    public function recordForEmployee(Employee $employee, string $source, ?CarbonImmutable $at = null, array $extra = []): RawPunch
    {
        $branch = $employee->branch ?? Branch::find($employee->branch_id);
        $at ??= CarbonImmutable::now('UTC');
        $utc = $at->utc();

        $attributes = array_merge([
            'branch_id' => $employee->branch_id,
            'employee_id' => $employee->id,
            'device_emp_code' => $employee->biometric_emp_code,
            'punched_at' => $utc,
            'punched_at_local' => $utc->setTimezone($branch?->timezone ?: 'UTC'),
            'source' => $source,
        ], $extra);

        $attributes['dedupe_hash'] = sha1(implode('|', [$source, $employee->id, $utc->format('Y-m-d H:i:s'), $extra['external_id'] ?? '']));

        return RawPunch::firstOrCreate(
            ['company_id' => $employee->company_id, 'dedupe_hash' => $attributes['dedupe_hash']],
            $attributes
        );
    }

    /**
     * Bulk-store punches from a biometric provider. Device timestamps are
     * branch wall-clock; employees are matched by their mapped device code
     * (unmatched punches are kept with employee_id NULL and linked later,
     * once someone maps the code -- see relinkUnmatched()).
     *
     * @param  iterable<array{emp_code: string, punch_date_time: string, id?: string|int, direction?: string}>  $punches
     * @return array{stored: int, duplicates: int, unmatched_codes: list<string>, affected: array<int, array<int, string>>}
     */
    public function recordFromDevice(Branch $branch, iterable $punches, ?int $biometricConfigId = null): array
    {
        $tz = $branch->timezone ?: 'UTC';
        $rows = [];
        $codes = [];

        foreach ($punches as $punch) {
            $code = trim((string) ($punch['emp_code'] ?? ''));
            $stamp = $punch['punch_date_time'] ?? null;
            if ($code === '' || ! $stamp) {
                continue;
            }

            $local = CarbonImmutable::parse($stamp, $tz);
            $external = isset($punch['id']) ? (string) $punch['id'] : null;
            $rows[] = [$code, $local, $external, $punch];
            $codes[$code] = true;
        }

        $employees = Employee::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branch->id)
            ->whereIn('biometric_emp_code', array_map('strval', array_keys($codes)))
            ->get(['id', 'biometric_emp_code'])
            ->keyBy('biometric_emp_code');

        $now = now();
        $insert = [];
        $unmatched = [];
        $affected = [];

        foreach ($rows as [$code, $local, $external, $raw]) {
            $utc = $local->utc();
            $employeeId = $employees->get($code)?->id;

            if ($employeeId === null) {
                $unmatched[$code] = true;
            } else {
                $affected[$employeeId][$local->toDateString()] = true;
            }

            $insert[] = [
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
                'employee_id' => $employeeId,
                'biometric_config_id' => $biometricConfigId,
                'device_emp_code' => $code,
                'punched_at' => $utc->format('Y-m-d H:i:s'),
                'punched_at_local' => $local->format('Y-m-d H:i:s'),
                'source' => 'biometric',
                'direction' => isset($raw['direction']) ? substr((string) $raw['direction'], 0, 5) : null,
                'external_id' => $external,
                'dedupe_hash' => sha1(implode('|', ['biometric', $branch->id, $code, $utc->format('Y-m-d H:i:s')])),
                'payload' => json_encode($raw),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $stored = 0;
        foreach (array_chunk($insert, 500) as $chunk) {
            $stored += DB::table('raw_punches')->insertOrIgnore($chunk);
        }

        return [
            'stored' => $stored,
            'duplicates' => count($insert) - $stored,
            // Numeric codes become int array keys in PHP; keep them strings.
            'unmatched_codes' => array_map('strval', array_keys($unmatched)),
            'affected' => array_map(fn ($dates) => array_keys($dates), $affected),
        ];
    }

    /**
     * After an admin maps a device code to an employee, attach that code's
     * orphaned punches to them. Returns the employee-days to reprocess.
     *
     * @return array<string> dates
     */
    public function relinkUnmatched(Employee $employee): array
    {
        if (! $employee->biometric_emp_code) {
            return [];
        }

        $query = RawPunch::whereNull('employee_id')
            ->where('branch_id', $employee->branch_id)
            ->where('device_emp_code', $employee->biometric_emp_code);

        $dates = (clone $query)->pluck('punched_at_local')->map(fn ($d) => $d->toDateString())->unique()->values()->all();
        $query->update(['employee_id' => $employee->id, 'processed_at' => null]);

        return $dates;
    }
}
