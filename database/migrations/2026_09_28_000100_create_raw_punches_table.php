<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every punch exactly as it arrived -- from the biometric API poll, the web /
 * mobile punch button, a kiosk, an approved regularization or an import.
 * Daily attendance is *derived* from these by AttendanceProcessor, so it can
 * be recomputed (new shift rules, a corrected roster, a late employee-code
 * mapping) without re-fetching anything from the device provider.
 *
 * Existing attendance check-in/out times are backfilled as punches so that
 * reprocessing historical days reproduces what is there today instead of
 * turning them into absences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('biometric_config_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_emp_code', 50)->nullable();
            $table->dateTime('punched_at');                // UTC instant
            $table->dateTime('punched_at_local');          // branch wall-clock, as reported
            $table->string('source', 20);                  // biometric|web|mobile|kiosk|regularization|import|backfill
            $table->string('direction', 5)->nullable();    // in|out when the device knows
            $table->string('external_id', 100)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->date('attendance_date')->nullable();   // day it was attributed to when processed
            $table->timestamp('processed_at')->nullable();
            $table->char('dedupe_hash', 40);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'dedupe_hash']);
            $table->index(['employee_id', 'punched_at']);
            $table->index(['branch_id', 'device_emp_code', 'punched_at']);
            $table->index(['company_id', 'processed_at']);
        });

        $this->backfillFromAttendance();
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_punches');
    }

    private function backfillFromAttendance(): void
    {
        $sourceMap = ['api' => 'biometric', 'web' => 'web', 'mobile' => 'mobile', 'kiosk' => 'kiosk'];
        $timezones = DB::table('branches')->pluck('timezone', 'id');
        $now = now();

        DB::table('attendances')
            ->join('employees', 'employees.id', '=', 'attendances.employee_id')
            ->whereNotNull('attendances.check_in')
            ->where('attendances.source', '!=', 'manual')
            ->select('attendances.id', 'attendances.company_id', 'attendances.employee_id', 'attendances.date',
                'attendances.check_in', 'attendances.check_out', 'attendances.source',
                'attendances.latitude', 'attendances.longitude', 'employees.branch_id', 'employees.biometric_emp_code')
            ->orderBy('attendances.id')
            ->chunk(500, function ($rows) use ($sourceMap, $timezones, $now) {
                $insert = [];

                foreach ($rows as $row) {
                    $tz = $timezones[$row->branch_id] ?? 'UTC';
                    $source = $sourceMap[$row->source] ?? 'backfill';

                    foreach (array_filter([$row->check_in, $row->check_out]) as $i => $instant) {
                        $utc = Carbon::parse($instant, 'UTC');
                        $insert[] = [
                            'company_id' => $row->company_id,
                            'branch_id' => $row->branch_id,
                            'employee_id' => $row->employee_id,
                            'device_emp_code' => $row->biometric_emp_code,
                            'punched_at' => $utc->toDateTimeString(),
                            'punched_at_local' => $utc->copy()->setTimezone($tz)->toDateTimeString(),
                            'source' => $source,
                            'direction' => $i === 0 ? 'in' : 'out',
                            'latitude' => $i === 0 ? $row->latitude : null,
                            'longitude' => $i === 0 ? $row->longitude : null,
                            'attendance_date' => $row->date,
                            'processed_at' => $now,
                            'dedupe_hash' => sha1("backfill|{$row->employee_id}|{$utc->toDateTimeString()}"),
                            'payload' => json_encode(['backfilled_from_attendance_id' => $row->id]),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                DB::table('raw_punches')->insertOrIgnore($insert);
            });
    }
};
