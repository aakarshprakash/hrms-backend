<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The employee's own home screen and the profile fields they may change. */
class SelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-28 04:00:00', 'UTC')); // Monday 09:30 IST

        $this->branch = $this->makeBranch($this->makeCompany('Self Motors'));
        $this->branch->update(['timezone' => 'Asia/Kolkata', 'week_off_days' => [0]]);
    }

    public function test_home_shows_my_week_leave_and_team(): void
    {
        ['user' => $managerUser, 'employee' => $manager] = $this->makeUser('manager', $this->branch);
        ['user' => $user, 'employee' => $employee] = $this->makeUser('employee', $this->branch, true, [
            'reporting_manager_id' => $manager->id, 'weekly_off_days' => [3], 'date_of_joining' => '2025-01-01',
        ]);
        $shift = Shift::create(['branch_id' => $this->branch->id, 'name' => 'Showroom', 'start_time' => '09:30:00', 'end_time' => '19:00:00']);
        EmployeeShift::create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'effective_from' => '2026-01-01']);
        Holiday::create(['branch_id' => $this->branch->id, 'name' => 'Gandhi Jayanti', 'date' => '2026-10-02']);
        LeaveType::create(['branch_id' => $this->branch->id, 'name' => 'Casual Leave', 'code' => 'CL', 'days_per_year' => 12, 'accrual' => 'annual', 'paid' => true]);

        $home = $this->actingAs($user, 'sanctum')->getJson('/api/me/home')->assertOk()->json('data');

        $this->assertSame('2026-09-28', $home['today']['date']);
        $this->assertSame('Showroom', $home['today']['schedule']['shift']['name']);
        $week = collect($home['week'])->keyBy('date');
        $this->assertTrue($week['2026-09-30']['weekly_off']); // their own Wednesday off
        $this->assertSame('Gandhi Jayanti', $week['2026-10-02']['holiday']);
        $this->assertEquals(12, $home['leave']['balances'][0]['available']);
        $this->assertNull($home['team']);

        $managerHome = $this->actingAs($managerUser, 'sanctum')->getJson('/api/me/home')->assertOk()->json('data');
        $this->assertSame(1, $managerHome['team']['size']);
    }

    public function test_employees_update_their_own_personal_details_only(): void
    {
        ['user' => $user, 'employee' => $employee] = $this->makeUser('employee', $this->branch);
        ['employee' => $colleague] = $this->makeUser('employee', $this->branch);

        $this->actingAs($user, 'sanctum')->putJson('/api/me/profile', [
            'phone' => '98470 12345', 'city' => 'Kochi', 'emergency_contact_name' => 'Anil', 'tax_regime' => 'old', 'declared_deductions' => 150000,
            // Not theirs to change: ignored.
            'status' => 'terminated', 'branch_id' => 999, 'employee_code' => 'HACKED', 'id' => $colleague->id,
        ])->assertOk();

        $fresh = $employee->fresh();
        $this->assertSame(['98470 12345', 'Kochi', 'Anil', 'old'], [$fresh->phone, $fresh->city, $fresh->emergency_contact_name, $fresh->tax_regime]);
        $this->assertSame('active', $fresh->status);
        $this->assertNotSame('HACKED', $fresh->employee_code);
        $this->assertNull($colleague->fresh()->city);

        $this->actingAs($user, 'sanctum')->putJson('/api/me/profile', ['blood_group' => 'Z+'])->assertUnprocessable();

        // HR can see who changed what.
        $this->assertTrue(Activity::query()->where('subject_type', Employee::class)->where('subject_id', $employee->id)
            ->where('causer_id', $user->id)->exists());
    }
}
