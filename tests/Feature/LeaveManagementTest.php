<?php

namespace Tests\Feature;

use App\Models\ApprovalAction;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveTransaction;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Services\Attendance\AttendanceProcessor;
use App\Services\Attendance\PunchRecorder;
use App\Services\Leave\LeaveAccrualService;
use App\Services\Leave\LeaveLedger;
use App\Services\Payroll\AttendanceSummarizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Leave on a ledger: quotes by the employee's own schedule, policy rules,
 * multi-step approval, accrual / carry forward, and what half-day leave
 * does to attendance and payroll.
 *
 * "Today" is Tuesday 15 Sep 2026.
 */
class LeaveManagementTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $hr;

    private User $manager;

    private User $staff;

    private Employee $staffEmployee;

    private LeaveType $cl;

    private LeaveType $el;

    private LeaveType $lop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'UTC'));

        $this->branch = $this->makeBranch($this->makeCompany('Leave Motors'));
        $this->branch->update(['timezone' => 'Asia/Kolkata', 'week_off_days' => [0]]);

        $this->hr = $this->makeUser('hr', $this->branch)['user'];
        ['user' => $this->manager, 'employee' => $managerEmployee] = $this->makeUser('manager', $this->branch, true, ['date_of_joining' => '2020-01-01']);
        ['user' => $this->staff, 'employee' => $this->staffEmployee] = $this->makeUser('employee', $this->branch, true, [
            'reporting_manager_id' => $managerEmployee->id, 'gender' => 'female', 'date_of_joining' => '2025-01-01',
        ]);

        $this->cl = $this->type(['name' => 'Casual Leave', 'code' => 'CL', 'days_per_year' => 12, 'accrual' => 'annual', 'max_consecutive_days' => 3]);
        $this->el = $this->type(['name' => 'Earned Leave', 'code' => 'EL', 'days_per_year' => 15, 'accrual' => 'monthly',
            'sandwich_rule' => true, 'min_notice_days' => 3, 'carry_forward' => true, 'max_carry_forward' => 5]);
        $this->lop = $this->type(['name' => 'Loss of Pay', 'code' => 'LOP', 'days_per_year' => 0, 'accrual' => 'none', 'paid' => false, 'allow_negative' => true]);

        ApprovalFlow::create(['branch_id' => $this->branch->id, 'module' => 'leave',
            'steps_json' => [['step' => 1, 'approver_type' => 'manager'], ['step' => 2, 'approver_type' => 'hr']]]);
    }

    private function type(array $attributes): LeaveType
    {
        return LeaveType::create(array_merge(['branch_id' => $this->branch->id, 'paid' => true, 'allow_half_day' => true], $attributes));
    }

    private function quote(User $as, LeaveType $type, string $from, string $to, array $extra = []): array
    {
        return $this->actingAs($as, 'sanctum')->getJson('/api/leaves/quote?' . http_build_query(array_merge([
            'leave_type_id' => $type->id, 'start_date' => $from, 'end_date' => $to,
        ], $extra)))->assertOk()->json('data');
    }

    private function apply(User $as, LeaveType $type, string $from, string $to, array $extra = [])
    {
        return $this->actingAs($as, 'sanctum')->postJson('/api/leaves', array_merge([
            'leave_type_id' => $type->id, 'start_date' => $from, 'end_date' => $to, 'reason' => 'Personal work',
        ], $extra));
    }

    private function balance(Employee $employee, LeaveType $type, int $year = 2026): ?LeaveBalance
    {
        return LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $year)->first();
    }

    private function assertLedgerAddsUp(LeaveBalance $balance): void
    {
        $this->assertEqualsWithDelta((float) $balance->fresh()->balance,
            (float) LeaveTransaction::where('leave_balance_id', $balance->id)->sum('days'), 0.001, 'ledger must add up to the balance');
    }

    public function test_quote_counts_the_employees_own_working_days(): void
    {
        // Their weekly off is Wednesday (not the branch's Sunday); Thursday 24th is a holiday.
        $this->staffEmployee->update(['weekly_off_days' => [3]]);
        Holiday::create(['branch_id' => $this->branch->id, 'name' => 'Sree Narayana Guru Samadhi', 'date' => '2026-09-24']);

        $q = $this->quote($this->staff, $this->cl, '2026-09-22', '2026-09-25');
        $this->assertTrue($q['ok'], implode(' ', $q['errors']));
        $this->assertEquals(2, $q['days']); // Tue + Fri
        $this->assertSame(['2026-09-22', '2026-09-25'], array_keys($q['dates']));

        $this->assertFalse($this->quote($this->staff, $this->cl, '2026-09-23', '2026-09-23')['ok']);

        // Starting on the off day: trimmed to the first working day.
        $trimmed = $this->quote($this->staff, $this->cl, '2026-09-23', '2026-09-25');
        $this->assertSame('2026-09-25', $trimmed['start_date']);
        $this->assertEquals(1, $trimmed['days']);
        $this->assertNotEmpty($trimmed['warnings']);
    }

    public function test_sandwich_rule_charges_enclosed_weekly_offs(): void
    {
        // Saturday to Monday around a Sunday off.
        $el = $this->quote($this->staff, $this->el, '2026-09-26', '2026-09-28');
        $this->assertTrue($el['ok'], implode(' ', $el['errors']));
        $this->assertEquals(3, $el['days']);
        $this->assertSame(1, $el['sandwiched_days']);

        $this->assertEquals(2, $this->quote($this->staff, $this->cl, '2026-09-26', '2026-09-28')['days']);
    }

    public function test_half_days_and_overlaps(): void
    {
        $half = $this->quote($this->staff, $this->cl, '2026-09-17', '2026-09-17', ['half_day_session' => 'first_half']);
        $this->assertEquals(0.5, $half['days']);

        $this->assertFalse($this->quote($this->staff, $this->cl, '2026-09-17', '2026-09-18', ['half_day_session' => 'first_half'])['ok']);

        $noHalf = $this->type(['name' => 'Bereavement', 'code' => 'BL', 'days_per_year' => 3, 'accrual' => 'annual', 'allow_half_day' => false]);
        $this->assertFalse($this->quote($this->staff, $noHalf, '2026-09-17', '2026-09-17', ['half_day_session' => 'first_half'])['ok']);

        $this->apply($this->staff, $this->cl, '2026-09-17', '2026-09-17', ['half_day_session' => 'first_half'])->assertCreated();

        // The other half of the same day is free; the same half, or a full day, is not.
        $this->assertTrue($this->quote($this->staff, $this->cl, '2026-09-17', '2026-09-17', ['half_day_session' => 'second_half'])['ok']);
        $this->assertFalse($this->quote($this->staff, $this->cl, '2026-09-17', '2026-09-17', ['half_day_session' => 'first_half'])['ok']);
        $this->assertFalse($this->quote($this->staff, $this->cl, '2026-09-16', '2026-09-17')['ok']);
    }

    public function test_policy_rules(): void
    {
        // EL needs three days' notice -- unless HR records it for them.
        $late = $this->quote($this->staff, $this->el, '2026-09-16', '2026-09-16');
        $this->assertStringContainsString('in advance', implode(' ', $late['errors']));
        $this->assertTrue($this->quote($this->hr, $this->el, '2026-09-16', '2026-09-16', ['employee_id' => $this->staffEmployee->id])['ok']);

        // CL at most three days at a time.
        $this->assertStringContainsString('at most 3', implode(' ', $this->quote($this->staff, $this->cl, '2026-09-21', '2026-09-24')['errors']));

        // Gender and minimum service.
        $ptl = $this->type(['name' => 'Paternity Leave', 'code' => 'PTL', 'days_per_year' => 5, 'accrual' => 'annual', 'applicable_gender' => 'male']);
        $this->assertFalse($this->quote($this->staff, $ptl, '2026-09-21', '2026-09-21')['ok']);

        $ml = $this->type(['name' => 'Maternity Leave', 'code' => 'ML', 'days_per_year' => 182, 'accrual' => 'annual',
            'applicable_gender' => 'female', 'min_service_days' => 80]);
        $this->staffEmployee->update(['date_of_joining' => '2026-08-01']);
        $this->assertStringContainsString('80 days of service', implode(' ', $this->quote($this->staff, $ml, '2026-09-21', '2026-09-21')['errors']));
    }

    public function test_pending_requests_hold_their_days(): void
    {
        $special = $this->type(['name' => 'Special', 'code' => 'SP', 'days_per_year' => 3, 'accrual' => 'annual']);

        $this->apply($this->staff, $special, '2026-09-21', '2026-09-22')->assertCreated();

        $q = $this->quote($this->staff, $special, '2026-09-24', '2026-09-25');
        $this->assertFalse($q['ok']);
        $this->assertEquals(['balance' => 3.0, 'pending' => 2.0, 'available' => 1.0, 'after' => -1.0], $q['balance']);

        // Loss of pay never needs a balance.
        $this->assertTrue($this->quote($this->staff, $this->lop, '2026-09-24', '2026-09-25')['ok']);
    }

    public function test_manager_then_hr_approval_debits_the_ledger_and_cancelling_restores_it(): void
    {
        $leaveId = $this->apply($this->staff, $this->cl, '2026-09-17', '2026-09-17')->assertCreated()->json('data.id');

        // Step 1 waits for the reporting manager: it's in their inbox.
        $inbox = $this->actingAs($this->manager, 'sanctum')->getJson('/api/approvals')->assertOk()->json('data.leave');
        $this->assertSame([$leaveId], array_column($inbox, 'id'));
        $this->assertSame('Reporting manager', $inbox[0]['step']['waiting_for']);
        $this->assertTrue($inbox[0]['designated']);

        // HR could step in, but it isn't theirs yet: not in their badge count.
        $hrCount = $this->actingAs($this->hr, 'sanctum')->getJson('/api/approvals/count')->json('data');
        $this->assertSame([0, 1], [$hrCount['leave'], $hrCount['all']]);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/leaves/{$leaveId}/approve")->assertOk();
        $this->assertSame('pending', Leave::find($leaveId)->status);

        // Step 2 is HR's: the manager can't approve it too.
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/leaves/{$leaveId}/approve")->assertForbidden();
        $this->assertSame(1, $this->actingAs($this->hr, 'sanctum')->getJson('/api/approvals/count')->json('data.leave'));
        $this->actingAs($this->hr, 'sanctum')->postJson("/api/leaves/{$leaveId}/approve", ['comments' => 'Enjoy'])->assertOk();
        $this->assertSame('approved', Leave::find($leaveId)->status);

        $balance = $this->balance($this->staffEmployee, $this->cl);
        $this->assertEquals(11, (float) $balance->balance);
        $this->assertEquals(1, (float) $balance->used);
        $this->assertLedgerAddsUp($balance);

        $timeline = $this->actingAs($this->staff, 'sanctum')->getJson("/api/leaves/{$leaveId}")->assertOk()->json('timeline');
        $this->assertSame(['approved', 'approved'], array_column($timeline, 'status'));

        $types = array_column($this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/leave-balances/{$balance->id}/transactions")->assertOk()->json('data'), 'type');
        $this->assertSame(['accrual', 'availed'], $types);

        // Not started yet: the employee can cancel it, and the day comes back.
        $this->actingAs($this->staff, 'sanctum')->postJson("/api/leaves/{$leaveId}/cancel", ['reason' => 'Plans changed'])->assertOk();
        $this->assertEquals(12, (float) $balance->fresh()->balance);
        $this->assertLedgerAddsUp($balance);
    }

    public function test_hr_can_act_for_the_manager_but_not_approve_two_steps(): void
    {
        $leaveId = $this->apply($this->staff, $this->cl, '2026-09-17', '2026-09-17')->assertCreated()->json('data.id');

        $this->actingAs($this->hr, 'sanctum')->postJson("/api/leaves/{$leaveId}/approve")->assertOk();
        $this->actingAs($this->hr, 'sanctum')->postJson("/api/leaves/{$leaveId}/approve")->assertForbidden();
        $this->assertSame('pending', Leave::find($leaveId)->status);
    }

    public function test_manager_step_goes_to_hr_when_there_is_no_manager(): void
    {
        $this->staffEmployee->update(['reporting_manager_id' => null]);
        ApprovalFlow::where('branch_id', $this->branch->id)->where('module', 'leave')
            ->update(['steps_json' => json_encode([['step' => 1, 'approver_type' => 'manager']])]);

        $leaveId = $this->apply($this->staff, $this->cl, '2026-09-17', '2026-09-17')->assertCreated()->json('data.id');

        $this->assertSame(['hr'], ApprovalAction::where('requestable_id', $leaveId)->pluck('approver_type')->all());
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/leaves/{$leaveId}/approve")->assertNotFound(); // not their team
        $this->actingAs($this->hr, 'sanctum')->postJson("/api/leaves/{$leaveId}/approve")->assertOk();
    }

    public function test_started_leave_is_cancelled_by_an_approver_and_never_inside_finalized_payroll(): void
    {
        $past = $this->apply($this->hr, $this->cl, '2026-09-14', '2026-09-14', ['employee_id' => $this->staffEmployee->id, 'approve_now' => true])
            ->assertCreated()->json('data');
        $this->assertSame('approved', $past['status']);

        $this->actingAs($this->staff, 'sanctum')->postJson("/api/leaves/{$past['id']}/cancel")->assertStatus(422);
        $this->actingAs($this->hr, 'sanctum')->postJson("/api/leaves/{$past['id']}/cancel")->assertOk();

        $locked = $this->apply($this->hr, $this->cl, '2026-09-10', '2026-09-10', ['employee_id' => $this->staffEmployee->id, 'approve_now' => true])
            ->assertCreated()->json('data.id');
        Attendance::where('employee_id', $this->staffEmployee->id)->whereDate('date', '2026-09-10')->update(['locked_at' => now()]);

        $this->actingAs($this->hr, 'sanctum')->postJson("/api/leaves/{$locked}/cancel")->assertStatus(422);
        $this->assertStringContainsString('finalized', implode(' ', $this->quote($this->staff, $this->cl, '2026-09-10', '2026-09-10')['errors']));
    }

    public function test_monthly_and_annual_accrual_prorate_joiners_and_never_double_credit(): void
    {
        $this->staffEmployee->update(['date_of_joining' => '2026-03-16']);
        $accrual = app(LeaveAccrualService::class);

        $accrual->syncEmployee($this->staffEmployee->fresh(), CarbonImmutable::parse('2026-06-10'));
        $accrual->syncEmployee($this->staffEmployee->fresh(), CarbonImmutable::parse('2026-06-10'));

        // EL: March from the 16th (16/31 of 1.25 = 0.65) + April, May, June.
        $el = $this->balance($this->staffEmployee, $this->el);
        $this->assertEquals(4.40, (float) $el->balance);
        $this->assertSame(4, LeaveTransaction::where('leave_balance_id', $el->id)->count());

        // CL: joined after the 15th, so April-December = 9 months of 12.
        $this->assertEquals(9, (float) $this->balance($this->staffEmployee, $this->cl)->balance);

        // Twelve monthly credits add up to exactly the year's entitlement.
        $accrual->syncEmployee($this->manager->employee, CarbonImmutable::parse('2026-12-01'));
        $this->assertEquals(15, (float) $this->balance($this->manager->employee, $this->el)->balance);
    }

    public function test_year_end_carries_forward_up_to_the_cap_and_lapses_the_rest(): void
    {
        $ledger = app(LeaveLedger::class);
        $employee = $this->staffEmployee;

        $el2025 = $ledger->balanceFor($employee, $this->el, 2025);
        $ledger->post($el2025, 'adjustment', 8, ['note' => 'test']);
        $el2025->update(['accrued_through' => '2025-12-31']);
        $cl2025 = $ledger->balanceFor($employee, $this->cl, 2025);
        $ledger->post($cl2025, 'adjustment', 4, ['note' => 'test']);
        $cl2025->update(['accrued_through' => '2025-12-31']);

        $accrual = app(LeaveAccrualService::class);
        $accrual->syncEmployee($employee, CarbonImmutable::parse('2026-01-02'));
        $accrual->syncEmployee($employee, CarbonImmutable::parse('2026-01-02'));

        $this->assertEquals(0, (float) $el2025->fresh()->balance);
        $this->assertEquals(5, (float) $el2025->fresh()->carried_forward);
        $this->assertEquals(3, (float) $el2025->fresh()->lapsed);
        $this->assertNotNull($el2025->fresh()->closed_at);
        $this->assertEquals(6.25, (float) $this->balance($employee, $this->el)->balance); // 5 carried + January

        $this->assertEquals(4, (float) $cl2025->fresh()->lapsed);
        $this->assertEquals(12, (float) $this->balance($employee, $this->cl)->balance);

        foreach ([$el2025, $cl2025, $this->balance($employee, $this->el)] as $b) {
            $this->assertLedgerAddsUp($b);
        }
    }

    public function test_adjustments_are_for_leave_managers_and_types_with_history_are_kept(): void
    {
        $co = $this->type(['name' => 'Compensatory Off', 'code' => 'CO', 'days_per_year' => 0, 'accrual' => 'none']);
        $payload = ['employee_id' => $this->staffEmployee->id, 'leave_type_id' => $co->id, 'days' => 1, 'note' => 'Worked on 13 Sep (Sunday)'];

        $this->actingAs($this->staff, 'sanctum')->postJson('/api/leave-balances/adjust', $payload)->assertForbidden();
        $this->actingAs($this->hr, 'sanctum')->postJson('/api/leave-balances/adjust', $payload)->assertOk();
        $this->assertEquals(1, (float) $this->balance($this->staffEmployee, $co)->balance);

        $this->apply($this->staff, $co, '2026-09-21', '2026-09-21')->assertCreated();
        $this->actingAs($this->hr, 'sanctum')->deleteJson("/api/leave-types/{$co->id}")->assertStatus(422);

        $unused = $this->type(['name' => 'Unused', 'code' => 'UN', 'days_per_year' => 2, 'accrual' => 'annual']);
        $this->actingAs($this->hr, 'sanctum')->deleteJson("/api/leave-types/{$unused->id}")->assertOk();
    }

    public function test_half_day_leave_in_attendance_and_payroll(): void
    {
        $shift = Shift::create(['branch_id' => $this->branch->id, 'name' => 'Day', 'start_time' => '09:30:00', 'end_time' => '18:30:00',
            'break_minutes' => 60, 'grace_minutes' => 10, 'absent_threshold_minutes' => 120]);
        $employee = $this->staffEmployee;
        EmployeeShift::create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'effective_from' => '2026-01-01']);

        // Tue 8th: first half on leave, came in after lunch. Wed 9th: second half on
        // leave but never came. Thu 10th: a full day of loss of pay.
        foreach ([['2026-09-08', 'first_half'], ['2026-09-09', 'second_half']] as [$date, $half]) {
            $this->apply($this->hr, $this->cl, $date, $date, ['employee_id' => $employee->id, 'half_day_session' => $half, 'approve_now' => true])->assertCreated();
        }
        $this->apply($this->hr, $this->lop, '2026-09-10', '2026-09-10', ['employee_id' => $employee->id, 'approve_now' => true])->assertCreated();

        $recorder = app(PunchRecorder::class);
        $recorder->recordForEmployee($employee, 'web', CarbonImmutable::parse('2026-09-08 14:00', 'Asia/Kolkata'));
        $recorder->recordForEmployee($employee, 'web', CarbonImmutable::parse('2026-09-08 18:35', 'Asia/Kolkata'));
        app(AttendanceProcessor::class)->processEmployee($employee->fresh(), '2026-09-08', '2026-09-10');

        $day = fn (string $date) => Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->first();
        $this->assertSame('present', $day('2026-09-08')->status); // not late: the morning was leave
        $this->assertNull($day('2026-09-08')->late_by_minutes);
        $this->assertSame('Half-day leave (first half)', $day('2026-09-08')->remarks);
        $this->assertSame('absent', $day('2026-09-09')->status);
        $this->assertSame('on_leave', $day('2026-09-10')->status);

        $summary = app(AttendanceSummarizer::class)->summarize($employee->fresh(), $this->branch->fresh(),
            CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));

        // 0 (worked the other half) + 0.5 (absent the other half) + 1 (unpaid).
        $this->assertEquals(1.5, $summary['lop_days']);
        $this->assertEquals(1.0, $summary['paid_leave_days']);
        $this->assertEquals(1.0, $summary['unpaid_leave_days']);
        $this->assertEquals(11, (float) $this->balance($employee, $this->cl)->balance);
    }
}
