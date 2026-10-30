<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The mobile app becomes a plan module. Every existing plan gets it,
 * listed right after geo-fencing, so no organisation loses it; the platform
 * admin can take it out of a plan from the plan editor.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('plans')->get(['id', 'features']) as $plan) {
            $features = json_decode($plan->features ?? '[]', true) ?: [];
            if (in_array('mobile_app', $features, true)) {
                continue;
            }
            $at = array_search('geofence', $features, true);
            array_splice($features, $at === false ? count($features) : $at + 1, 0, ['mobile_app']);
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode(array_values($features))]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->get(['id', 'features']) as $plan) {
            $features = array_values(array_diff(json_decode($plan->features ?? '[]', true) ?: [], ['mobile_app']));
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features)]);
        }
    }
};
