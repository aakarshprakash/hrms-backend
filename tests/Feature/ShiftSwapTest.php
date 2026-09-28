<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\EmployeeShift;
use App\Models\Shift;
use App\Models\ShiftRoster;
use App\Services\Attendance\ScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Propose → colleague accepts → approver approves → the two rosters swap. */
class ShiftSwapTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-28 04:00:00', 'UTC')); // Monday

        $this->branch = $this->makeBranch($this->makeCompany('Swap Motors'));
        $this->branch->update(['timezone' => 'Asia/Kolkata', 'week_off_days' => [0]]);
    }

    public function test_colleague_accepts_then_approval_swaps_both_days(): void
    {
        $morning = Shift::create(['branch_id' => $this->branch->id, 'name' => 'Morning', 'start_time' => '09:00:00', 'end_time' => '17:00:00']);
        $evening = Shift::create(['branch_id' => $this->branch->id, 'name' => 'Evening', 'start_time' => '13:00:00', 'end_time' => '21:00:00']);

        // Anu is off on Wednesdays, Bibin on Thursdays.
        ['user' => $anuUser, 'employee' => $anu] = $this->makeUser('employee', $this->branch, true, ['weekly_off_days' => [3], 'first_name' => 'Anu']);
        ['user' => $bibinUser, 'employee' => $bibin] = $this->makeUser('employee', $this->branch, true, ['weekly_off_days' => [4], 'first_name' => 'Bibin']);
        $hr = $this->makeUser('hr', $this->branch, false)['user'];
        EmployeeShift::create(['employee_id' => $anu->id, 'shift_id' => $morning->id, 'effective_from' => '2026-01-01']);
        EmployeeShift::create(['employee_id' => $bibin->id, 'shift_id' => $evening->id, 'effective_from' => '2026-01-01']);

        // Anu gives away Thursday 1 Oct and takes Bibin's Wednesday 30 Sep.
        $swapId = $this->actingAs($anuUser, 'sanctum')->postJson('/api/shift-swaps', [
            'with_employee_id' => $bibin->id, 'my_date' => '2026-10-01', 'their_date' => '2026-09-30', 'reason' => 'Family function',
        ])->assertCreated()->json('data.id');

        $this->assertSame(['shift_swap.requested'], $bibinUser->fresh()->notifications()->pluck('type')->all());

        // Not before Bibin agrees.
        $this->actingAs($hr, 'sanctum')->postJson("/api/shift-swaps/{$swapId}/approve")->assertStatus(422);
        $this->actingAs($anuUser, 'sanctum')->postJson("/api/shift-swaps/{$swapId}/respond", ['response' => 'accepted'])->assertForbidden();
        $this->actingAs($bibinUser, 'sanctum')->postJson("/api/shift-swaps/{$swapId}/respond", ['response' => 'accepted'])->assertOk();

        $queue = $this->actingAs($hr, 'sanctum')->getJson('/api/shift-swaps?scope=approvals')->assertOk()->json('data');
        $this->assertTrue($queue[0]['can_decide']);
        $this->actingAs($hr, 'sanctum')->postJson("/api/shift-swaps/{$swapId}/approve")->assertOk();

        $resolver = app(ScheduleResolver::class);
        $anuWeek = $resolver->forEmployee($anu->fresh(), '2026-09-30', '2026-10-01');
        $bibinWeek = $resolver->forEmployee($bibin->fresh(), '2026-09-30', '2026-10-01');

        // Wednesday: Anu works Bibin's evening shift, Bibin has Anu's day off.
        $this->assertSame('Evening', $anuWeek['2026-09-30']->shift?->name);
        $this->assertFalse($anuWeek['2026-09-30']->isWeeklyOff);
        $this->assertTrue($bibinWeek['2026-09-30']->isWeeklyOff);
        // Thursday: the other way round.
        $this->assertTrue($anuWeek['2026-10-01']->isWeeklyOff);
        $this->assertSame('Morning', $bibinWeek['2026-10-01']->shift?->name);

        $this->assertSame(4, ShiftRoster::count());
        $this->assertContains('shift_swap.decided', $anuUser->fresh()->notifications()->pluck('type')->all());
    }

    public function test_requests_that_cant_work_are_refused(): void
    {
        $shift = Shift::create(['branch_id' => $this->branch->id, 'name' => 'Day', 'start_time' => '09:00:00', 'end_time' => '17:00:00']);
        ['user' => $anuUser, 'employee' => $anu] = $this->makeUser('employee', $this->branch, true, ['weekly_off_days' => [3]]);
        ['employee' => $bibin] = $this->makeUser('employee', $this->branch);
        EmployeeShift::create(['employee_id' => $anu->id, 'shift_id' => $shift->id, 'effective_from' => '2026-01-01']);
        $otherBranch = $this->makeBranch($anu->company()->first(), 'Other');
        ['employee' => $elsewhere] = $this->makeUser('employee', $otherBranch);

        $swap = fn ($with, $mine, $theirs) => $this->actingAs($anuUser, 'sanctum')->postJson('/api/shift-swaps', [
            'with_employee_id' => $with, 'my_date' => $mine, 'their_date' => $theirs,
        ]);

        $swap($bibin->id, '2026-09-25', '2026-10-01')->assertStatus(422);   // in the past
        $swap($bibin->id, '2026-09-30', '2026-10-01')->assertStatus(422);   // her own day off: nothing to give
        $swap($elsewhere->id, '2026-10-01', '2026-10-01')->assertStatus(422); // another branch
        $swap($bibin->id, '2026-10-01', '2026-10-01')->assertCreated();
    }

    public function test_nobody_approves_a_swap_they_are_part_of(): void
    {
        $shift = Shift::create(['branch_id' => $this->branch->id, 'name' => 'Day', 'start_time' => '09:00:00', 'end_time' => '17:00:00']);
        ['user' => $hrUser, 'employee' => $hrEmployee] = $this->makeUser('hr', $this->branch);
        ['user' => $colleagueUser, 'employee' => $colleague] = $this->makeUser('employee', $this->branch);
        EmployeeShift::create(['employee_id' => $hrEmployee->id, 'shift_id' => $shift->id, 'effective_from' => '2026-01-01']);

        $swapId = $this->actingAs($hrUser, 'sanctum')->postJson('/api/shift-swaps', [
            'with_employee_id' => $colleague->id, 'my_date' => '2026-10-01', 'their_date' => '2026-10-01',
        ])->assertCreated()->json('data.id');
        $this->actingAs($colleagueUser, 'sanctum')->postJson("/api/shift-swaps/{$swapId}/respond", ['response' => 'accepted'])->assertOk();

        $this->actingAs($hrUser, 'sanctum')->postJson("/api/shift-swaps/{$swapId}/approve")->assertForbidden();
    }
}
