<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Geo-fencing with several sites per branch and per-employee rules.
 * - geo_sites: named points + radius belonging to a branch (showroom, workshop, warehouse…);
 * - employees.geofence_mode: branch (any site of their branch, the default) | anywhere
 *   (field staff: not restricted, location still recorded) | sites (only the assigned ones);
 * - employee_geo_site: the assigned sites, which may belong to other branches.
 * A branch's existing single geo-fence becomes its first site, "Main premises".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_m')->default(200);
            $table->string('address', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'branch_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('geofence_mode', 10)->default('branch')->after('weekly_off_days');
        });

        Schema::create('employee_geo_site', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('geo_site_id')->constrained('geo_sites')->cascadeOnDelete();
            $table->primary(['employee_id', 'geo_site_id']);
        });

        $now = now();
        foreach (DB::table('branches')->whereNotNull('attendance_settings')->get(['id', 'company_id', 'attendance_settings', 'address']) as $branch) {
            $g = json_decode($branch->attendance_settings, true)['geofence'] ?? null;
            if (isset($g['latitude'], $g['longitude'])) {
                DB::table('geo_sites')->insert([
                    'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'name' => 'Main premises',
                    'latitude' => $g['latitude'], 'longitude' => $g['longitude'], 'radius_m' => (int) ($g['radius_m'] ?? 200),
                    'address' => $branch->address, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_geo_site');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('geofence_mode');
        });
        Schema::dropIfExists('geo_sites');
    }
};
