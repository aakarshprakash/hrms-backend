<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Geo-fencing gets an organisation-wide master switch (companies.settings.geofence:
 * enabled, mode, require_location, default_radius). A branch now only says whether
 * it's included and, optionally, overrides block / flag.
 *
 * Existing per-branch settings move up: the organisation is on when any branch
 * was on (taking that branch's behaviour); branches that were off stay excluded
 * only while another branch is on, otherwise they simply follow the master.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('companies')->get(['id', 'settings']) as $company) {
            $branches = DB::table('branches')->where('company_id', $company->id)->get(['id', 'attendance_settings']);
            $fences = $branches->mapWithKeys(fn ($b) => [$b->id => json_decode($b->attendance_settings ?? '[]', true)['geofence'] ?? null])->filter();
            if ($fences->isEmpty()) {
                continue;
            }

            $on = $fences->first(fn ($g) => ! empty($g['enabled']));
            $master = [
                'enabled' => (bool) $on,
                'mode' => ($on['mode'] ?? 'block') === 'flag' ? 'flag' : 'block',
                'require_location' => (bool) ($on['require_location'] ?? true),
                'default_radius' => (int) ($on['radius_m'] ?? 200),
            ];
            $settings = json_decode($company->settings ?? '[]', true) ?: [];
            $settings['geofence'] = $master;
            DB::table('companies')->where('id', $company->id)->update(['settings' => json_encode($settings)]);

            foreach ($fences as $branchId => $g) {
                $attendance = json_decode($branches->firstWhere('id', $branchId)->attendance_settings, true);
                $attendance['geofence'] = [
                    'enabled' => $master['enabled'] ? (bool) ($g['enabled'] ?? false) : true,
                    'mode' => ($g['mode'] ?? $master['mode']) !== $master['mode'] ? $g['mode'] : null,
                ];
                DB::table('branches')->where('id', $branchId)->update(['attendance_settings' => json_encode($attendance)]);
            }
        }
    }

    public function down(): void
    {
        // The branch-level shape is a subset of the old one; nothing to restore.
    }
};
