<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * attendances becomes attendance_daily: one processed row per employee per
 * day, derived from raw_punches + shift rules (or entered manually by HR).
 * Foreign keys pointing at it (regularizations, leave conversions) follow
 * the rename automatically.
 *
 * New: weekly_off / holiday statuses so a month's register is complete, the
 * processing trail (punch_count, anomaly, processed_at) and a payroll lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('attendances', 'attendance_daily');

        Schema::table('attendance_daily', function (Blueprint $table) {
            $table->unsignedSmallInteger('punch_count')->default(0)->after('check_out');
            $table->unsignedInteger('overtime_minutes')->nullable()->after('worked_minutes');
            $table->boolean('is_weekly_off')->default(false)->after('overtime_minutes');
            $table->boolean('is_holiday')->default(false)->after('is_weekly_off');
            $table->string('anomaly', 30)->nullable()->after('is_holiday');
            $table->string('remarks', 255)->nullable()->after('source');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('locked_at')->nullable();
        });

        DB::statement("ALTER TABLE attendance_daily MODIFY status ENUM('present','absent','half_day','late','on_leave','weekly_off','holiday') NOT NULL DEFAULT 'present'");

        DB::table('attendance_daily')->whereNotNull('check_in')->update([
            'punch_count' => DB::raw('CASE WHEN check_out IS NULL THEN 1 ELSE 2 END'),
        ]);
    }

    public function down(): void
    {
        DB::table('attendance_daily')->whereIn('status', ['weekly_off', 'holiday'])->delete();
        DB::statement("ALTER TABLE attendance_daily MODIFY status ENUM('present','absent','half_day','late','on_leave') NOT NULL DEFAULT 'present'");

        Schema::table('attendance_daily', function (Blueprint $table) {
            $table->dropColumn(['punch_count', 'overtime_minutes', 'is_weekly_off', 'is_holiday', 'anomaly', 'remarks', 'processed_at', 'locked_at']);
        });

        Schema::rename('attendance_daily', 'attendances');
    }
};
