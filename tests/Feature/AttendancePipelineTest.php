<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\BiometricConfig;
use App\Models\Branch;
use App\Models\EmployeeShift;
use App\Models\Holiday;
use App\Models\RawPunch;
use App\Models\Shift;
use App\Models\ShiftRoster;
use App\Services\Attendance\AttendanceProcessor;
use App\Services\BiometricAttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * raw_punches -> AttendanceProcessor -> attendance_daily.
 */
class AttendancePipelineTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Shift $dayShift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = $this->makeBranch($this->makeCompany('Pipeline Motors'));
        $this->branch->update(['timezone' => 'Asia/Kolkata', 'week_off_days' => [0]]);
        $this->dayShift = Shift::create([
            'branch_id' => $this->branch->id, 'name' => 'Showroom', 'start_time' => '09:30:00', 'end_time' => '18:30:00',
            'break_minutes' => 60, 'grace_minutes' => 10,
        ]);
    }

    private function employee(string $code = '101', array $attrs = [])
    {
        $employee = $this->makeUser('employee', $this->branch, true, array_merge(['biometric_emp_code' => $code, 'date_of_joining' => '2026-01-01'], $attrs))['employee'];
        EmployeeShift::create(['employee_id' => $employee->id, 'shift_id' => $this->dayShift->id, 'effective_from' => '2026-01-01']);

        return $employee;
    }

    private function sync(array $punches, string $from = '2026-09-01', string $to = '2026-09-07')
    {
        BiometricConfig::firstOrCreate(['branch_id' => $this->branch->id], ['ins_code' => 'INS1', 'api_token' => 'secret', 'enabled' => true]);
        Http::fake(['*' => Http::response($punches)]);

        return app(BiometricAttendanceService::class)->sync($this->branch->fresh(), $from, $to);
    }

    private function day($employee, string $date): ?Attendance
    {
        return Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->first();
    }

    public function test_device_punches_become_daily_attendance_with_shift_rules(): void
    {
        $emp = $this->employee('101');

        $this->sync([
            ['id' => 1, 'emp_code' => '101', 'punch_date_time' => '2026-09-01 09:52:00'],   // 22 min late
            ['id' => 2, 'emp_code' => '101', 'punch_date_time' => '2026-09-01 18:40:00'],
            ['id' => 3, 'emp_code' => '101', 'punch_date_time' => '2026-09-02 09:30:00'],
            ['id' => 4, 'emp_code' => '101', 'punch_date_time' => '2026-09-02 12:00:00'],   // 1.5h worked -> half day
        ]);

        $d1 = $this->day($emp, '2026-09-01');
        $this->assertSame('late', $d1->status);
        $this->assertSame(22, $d1->late_by_minutes);
        $this->assertSame(468, $d1->worked_minutes); // 8h48m minus 60m break
        $this->assertSame('2026-09-01 04:22:00', $d1->check_in->toDateTimeString()); // stored in UTC
        $this->assertSame('api', $d1->source);

        $this->assertSame('half_day', $this->day($emp, '2026-09-02')->status);
        $this->assertSame(4, RawPunch::count());
    }

    public function test_resyncing_the_same_day_is_idempotent(): void
    {
        $this->employee('101');
        $punches = [
            ['id' => 1, 'emp_code' => '101', 'punch_date_time' => '2026-09-01 09:30:00'],
            ['id' => 2, 'emp_code' => '101', 'punch_date_time' => '2026-09-01 18:30:00'],
        ];

        $this->sync($punches);
        $this->sync($punches);

        $this->assertSame(2, RawPunch::count());
        $this->assertSame(1, Attendance::whereDate('date', '2026-09-01')->count());
    }

    public function test_unmatched_codes_are_kept_and_linked_once_mapped(): void
    {
        ['user' => $hr] = $this->makeUser('hr', $this->branch);
        $emp = $this->employee('');
        $emp->update(['biometric_emp_code' => null]);

        $log = $this->sync([
            ['id' => 1, 'emp_code' => '999', 'punch_date_time' => '2026-09-03 09:30:00'],
            ['id' => 2, 'emp_code' => '999', 'punch_date_time' => '2026-09-03 18:30:00'],
        ]);

        $this->assertSame(['999'], $log->unmatched_codes);
        $this->assertSame(2, RawPunch::whereNull('employee_id')->count());
        $this->assertNull($this->day($emp, '2026-09-03'));

        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/employees/{$emp->id}/map-device-code", ['device_emp_code' => '999'])
            ->assertOk()
            ->assertJsonPath('data.days_linked', 1);

        $this->assertSame('present', $this->day($emp, '2026-09-03')->status);
        $this->assertSame(0, RawPunch::whereNull('employee_id')->count());
    }

    public function test_night_shift_punches_stay_on_the_shift_day(): void
    {
        $night = Shift::create([
            'branch_id' => $this->branch->id, 'name' => 'Night', 'start_time' => '22:00:00', 'end_time' => '06:00:00',
            'break_minutes' => 30, 'grace_minutes' => 10,
        ]);
        $emp = $this->makeUser('employee', $this->branch, true, ['biometric_emp_code' => '202', 'date_of_joining' => '2026-01-01'])['employee'];
        EmployeeShift::create(['employee_id' => $emp->id, 'shift_id' => $night->id, 'effective_from' => '2026-01-01']);

        $this->sync([
            ['id' => 1, 'emp_code' => '202', 'punch_date_time' => '2026-09-01 21:55:00'],
            ['id' => 2, 'emp_code' => '202', 'punch_date_time' => '2026-09-02 06:05:00'],
        ]);

        $d1 = $this->day($emp, '2026-09-01');
        $this->assertSame('present', $d1->status);
        $this->assertSame(460, $d1->worked_minutes); // 8h10m - 30m
        $this->assertNotNull($d1->check_out);
        // The 06:05 out-punch did not start a phantom attendance day of its own.
        $this->assertNotSame('present', $this->day($emp, '2026-09-02')?->status);
    }

    public function test_days_without_punches_become_weekly_off_holiday_leave_or_absent(): void
    {
        $emp = $this->employee('303');
        Holiday::create(['branch_id' => $this->branch->id, 'name' => 'Onam', 'date' => '2026-09-04']);

        app(AttendanceProcessor::class)->processEmployee($emp, '2026-09-04', '2026-09-06');

        $this->assertSame('holiday', $this->day($emp, '2026-09-04')->status);   // Friday holiday
        $this->assertSame('absent', $this->day($emp, '2026-09-05')->status);    // Saturday working day
        $this->assertSame('weekly_off', $this->day($emp, '2026-09-06')->status); // Sunday
    }

    public function test_rostered_day_off_and_personal_weekly_off_override_branch_pattern(): void
    {
        $emp = $this->employee('404', ['weekly_off_days' => [2]]); // Tuesday off, works Sundays

        ShiftRoster::create(['branch_id' => $this->branch->id, 'employee_id' => $emp->id, 'date' => '2026-09-03', 'is_off' => true]);

        app(AttendanceProcessor::class)->processEmployee($emp, '2026-09-01', '2026-09-06');

        $this->assertSame('weekly_off', $this->day($emp, '2026-09-01')->status); // Tuesday (personal)
        $this->assertSame('weekly_off', $this->day($emp, '2026-09-03')->status); // rostered off
        $this->assertSame('absent', $this->day($emp, '2026-09-06')->status);     // Sunday is a working day for them
    }

    public function test_reprocessing_never_overwrites_manual_or_locked_days(): void
    {
        $emp = $this->employee('505');
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-09-01', 'status' => 'present', 'source' => 'manual']);
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-09-02', 'status' => 'present', 'source' => 'system', 'locked_at' => now()]);

        $stats = app(AttendanceProcessor::class)->processEmployee($emp, '2026-09-01', '2026-09-02');

        $this->assertSame(2, $stats['skipped']);
        $this->assertSame('present', $this->day($emp, '2026-09-01')->status);
        $this->assertSame('present', $this->day($emp, '2026-09-02')->status);
    }

    public function test_self_check_in_and_out_go_through_the_punch_pipeline(): void
    {
        ['user' => $user, 'employee' => $emp] = $this->makeUser('employee', $this->branch, true, ['date_of_joining' => '2026-01-01']);
        EmployeeShift::create(['employee_id' => $emp->id, 'shift_id' => $this->dayShift->id, 'effective_from' => '2026-01-01']);

        $this->travelTo(CarbonImmutable::parse('2026-09-01 09:35:00', 'Asia/Kolkata'));
        $this->actingAs($user, 'sanctum')->postJson('/api/attendance/check-in', ['source' => 'mobile'])->assertOk();
        $this->actingAs($user, 'sanctum')->postJson('/api/attendance/check-in')->assertStatus(422);

        $this->travelTo(CarbonImmutable::parse('2026-09-01 18:45:00', 'Asia/Kolkata'));
        $this->actingAs($user, 'sanctum')->postJson('/api/attendance/check-out')->assertOk();

        $day = $this->day($emp, '2026-09-01');
        $this->assertSame('present', $day->status); // 5 min late is within grace
        $this->assertSame(2, RawPunch::where('employee_id', $emp->id)->count());
        $this->assertSame('mobile', RawPunch::where('employee_id', $emp->id)->orderBy('punched_at')->first()->source);
    }

    public function test_approved_regularization_turns_an_absence_into_attendance(): void
    {
        ['user' => $user, 'employee' => $emp] = $this->makeUser('employee', $this->branch, true, ['date_of_joining' => '2026-01-01']);
        EmployeeShift::create(['employee_id' => $emp->id, 'shift_id' => $this->dayShift->id, 'effective_from' => '2026-01-01']);
        ['user' => $hr] = $this->makeUser('hr', $this->branch);

        app(AttendanceProcessor::class)->processEmployee($emp, '2026-09-02', '2026-09-02');
        $this->assertSame('absent', $this->day($emp, '2026-09-02')->status);

        $regId = $this->actingAs($user, 'sanctum')->postJson('/api/attendance/regularizations', [
            'date' => '2026-09-02', 'requested_check_in' => '09:30', 'requested_check_out' => '18:30', 'reason' => 'Device was down',
        ])->assertCreated()->json('data.id');

        $this->actingAs($hr, 'sanctum')->postJson("/api/attendance/regularizations/{$regId}/approve")->assertOk();

        $day = $this->day($emp, '2026-09-02');
        $this->assertSame('present', $day->status);
        $this->assertSame('2026-09-02 04:00:00', $day->check_in->toDateTimeString()); // 09:30 IST
        $this->assertSame('regularization', $day->source);
    }
}
