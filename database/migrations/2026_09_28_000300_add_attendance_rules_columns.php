<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable (per tenant, per shift) attendance rules instead of hardcoded
 * behaviour, plus the per-employee data payroll needs:
 *
 * shifts        early-exit grace, absent / OT thresholds, the punch window
 *               that attributes punches to a shift day (night shifts cross
 *               midnight), colour for the roster grid.
 * shift_rosters a day can be a rostered day off (rotational weekly offs).
 * branches      a default shift, and branch-level attendance settings.
 * employees     personal weekly-off pattern (showrooms stagger offs), and
 *               statutory identifiers + tax regime for payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->after('name');
            $table->unsignedSmallInteger('early_exit_grace_minutes')->default(0)->after('grace_minutes');
            $table->unsignedSmallInteger('absent_threshold_minutes')->nullable()->after('half_day_threshold_minutes');
            $table->unsignedSmallInteger('ot_threshold_minutes')->nullable()->after('absent_threshold_minutes');
            $table->unsignedSmallInteger('punch_window_before_minutes')->default(180);
            $table->unsignedSmallInteger('punch_window_after_minutes')->default(360);
            $table->string('color', 9)->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::table('shift_rosters', function (Blueprint $table) {
            $table->boolean('is_off')->default(false)->after('shift_id');
            $table->string('note', 255)->nullable()->after('is_off');
        });
        Schema::table('shift_rosters', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->change();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('default_shift_id')->nullable()->after('week_off_days')->constrained('shifts')->nullOnDelete();
            $table->string('state', 100)->nullable()->after('city');
            $table->json('attendance_settings')->nullable()->after('default_shift_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->json('weekly_off_days')->nullable()->after('work_location');
            $table->string('uan', 20)->nullable()->after('tax_id');
            $table->string('pf_number', 30)->nullable()->after('uan');
            $table->string('esi_number', 20)->nullable()->after('pf_number');
            $table->boolean('pf_opted_out')->default(false)->after('esi_number');
            $table->boolean('pt_exempt')->default(false)->after('pf_opted_out');
            $table->string('tax_regime', 5)->default('new')->after('pt_exempt');
            $table->decimal('declared_deductions', 12, 2)->default(0)->after('tax_regime');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['weekly_off_days', 'uan', 'pf_number', 'esi_number', 'pf_opted_out', 'pt_exempt', 'tax_regime', 'declared_deductions']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_shift_id');
            $table->dropColumn(['state', 'attendance_settings']);
        });

        Schema::table('shift_rosters', function (Blueprint $table) {
            $table->dropColumn(['is_off', 'note']);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['code', 'early_exit_grace_minutes', 'absent_threshold_minutes', 'ot_threshold_minutes',
                'punch_window_before_minutes', 'punch_window_after_minutes', 'color', 'is_active']);
        });
    }
};
