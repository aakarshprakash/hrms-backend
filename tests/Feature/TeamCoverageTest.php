<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\CoverageAlert;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Minimum staffing per team: today's coverage, the coming days, leave impact and the short-staffing alert. */
class TeamCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $hr;

    private Department $sales;

    /** @var list<Employee> */
    private array $team = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 28 Oct 2026, 11:00 in Kolkata.
        $this->travelTo(CarbonImmutable::parse('2026-10-28 11:00:00', 'Asia/Kolkata'));
        $this->branch = $this->makeBranch($this->makeCompany('Coverage Motors'));
        $this->branch->update(['timezone' => 'Asia/Kolkata', 'week_off_days' => [0]]);
        $this->hr = $this->makeUser('hr', $this->branch)['user'];
        $this->sales = Department::create(['branch_id' => $this->branch->id, 'name' => 'Sales']);
        foreach (range(1, 5) as $i) {
            $this->team[] = $this->makeUser('employee', $this->branch, true, ['department_id' => $this->sales->id, 'first_name' => "Seller{$i}"])['employee'];
        }
    }

    private function punchIn(Employee $e, string $date = '2026-10-28'): void
    {
        Attendance::create(['employee_id' => $e->id, 'date' => $date, 'check_in' => "{$date} 09:30:00", 'status' => 'present']);
    }

    private function leave(Employee $e, string $from, string $to, string $status = 'approved'): Leave
    {
        $type = LeaveType::firstOrCreate(['branch_id' => $this->branch->id, 'code' => 'CL'], ['name' => 'Casual Leave', 'paid' => true]);

        return Leave::create(['employee_id' => $e->id, 'leave_type_id' => $type->id, 'start_date' => $from, 'end_date' => $to,
            'days' => CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1, 'status' => $status, 'leave_year' => 2026]);
    }

    private function rule(int $min = 4): int
    {
        return $this->actingAs($this->hr, 'sanctum')->postJson('/api/coverage/rules', [
            'name' => 'Sales floor', 'branch_id' => $this->branch->id, 'department_id' => $this->sales->id, 'min_present' => $min, 'check_at' => '10:30',
        ])->assertCreated()->json('data.id');
    }

    public function test_today_shows_a_team_short_after_the_check_time_and_alerts_once(): void
    {
        $this->rule(4);
        $this->punchIn($this->team[0]);
        $this->punchIn($this->team[1]);
        $this->leave($this->team[2], '2026-10-28', '2026-10-28');

        $today = $this->actingAs($this->hr, 'sanctum')->getJson('/api/coverage/today?branch_id=' . $this->branch->id)->assertOk();
        $sales = collect($today->json('data.teams'))->firstWhere('name', 'Sales floor');
        $this->assertSame('short', $sales['status']);
        $this->assertSame(2, $sales['present']);
        $this->assertSame(1, $sales['on_leave']);
        $this->assertSame(2, $sales['not_in']);
        $this->assertSame(1, $today->json('data.summary.short'));

        $this->artisan('attendance:check-coverage')->assertSuccessful();
        $this->artisan('attendance:check-coverage')->assertSuccessful();
        $this->assertSame(1, CoverageAlert::count());
        $this->assertSame('attendance.coverage_short', $this->hr->fresh()->notifications()->first()->type);
        $this->assertStringContainsString('short by 2', $this->hr->fresh()->notifications()->first()->data['title']);
    }

    public function test_before_the_check_time_a_team_is_only_at_risk_and_no_alert_goes_out(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 09:00:00', 'Asia/Kolkata'));
        $this->rule(4);
        $this->leave($this->team[0], '2026-10-28', '2026-10-28');
        $this->leave($this->team[1], '2026-10-28', '2026-10-28');

        $sales = collect($this->actingAs($this->hr, 'sanctum')->getJson('/api/coverage/today')->json('data.teams'))->firstWhere('name', 'Sales floor');
        $this->assertSame('at_risk', $sales['status']);   // 3 can still come in, the minimum is 4
        $this->artisan('attendance:check-coverage')->assertSuccessful();
        $this->assertSame(0, CoverageAlert::count());
    }

    public function test_forecast_and_leave_impact_count_pending_leave(): void
    {
        $this->rule(4);
        $this->leave($this->team[0], '2026-10-30', '2026-10-30');               // Friday: approved
        $request = $this->leave($this->team[1], '2026-10-30', '2026-10-31', 'pending');

        $forecast = $this->actingAs($this->hr, 'sanctum')->getJson('/api/coverage/forecast?days=7')->assertOk();
        $friday = $forecast->json('data.rules.0.days.2026-10-30');
        $this->assertSame(3, $friday['expected']);
        $this->assertSame('short', $friday['status']);
        $this->assertTrue($forecast->json('data.rules.0.days.2026-11-01.off_day')); // Sunday

        // Approving the pending request takes Friday to 3 of 4; Saturday stays at 4.
        $impact = $this->actingAs($this->hr, 'sanctum')->getJson("/api/leaves/{$request->id}/coverage")->assertOk()->json('data');
        $this->assertCount(1, $impact);
        $this->assertSame('2026-10-30', $impact[0]['date']);
        $this->assertSame(3, $impact[0]['expected']);
    }

    public function test_a_rule_needs_a_team_and_only_attendance_managers_can_set_it(): void
    {
        $this->actingAs($this->hr, 'sanctum')->postJson('/api/coverage/rules', ['name' => 'Everyone', 'min_present' => 3, 'check_at' => '10:00'])->assertStatus(422);
        $staff = User::find($this->team[0]->user_id);
        $this->actingAs($staff, 'sanctum')->postJson('/api/coverage/rules', ['name' => 'X', 'department_id' => $this->sales->id, 'min_present' => 1, 'check_at' => '10:00'])->assertForbidden();
    }
}
