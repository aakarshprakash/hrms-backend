<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\DeviceToken;
use App\Models\EmployeeShift;
use App\Models\LeaveType;
use App\Models\RawPunch;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What the staff mobile app relies on: registering phones for push,
 * notifications mirrored as pushes, and punches queued while offline.
 */
class MobileAppTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'ExponentPushToken[abc123XYZ]';

    private Branch $branch;

    private User $manager;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'UTC'));

        $this->branch = $this->makeBranch($this->makeCompany('Mobile Motors'));
        $this->branch->update(['timezone' => 'Asia/Kolkata', 'week_off_days' => [0]]);

        ['user' => $this->manager, 'employee' => $managerEmployee] = $this->makeUser('manager', $this->branch);
        ['user' => $this->staff] = $this->makeUser('employee', $this->branch, true, [
            'reporting_manager_id' => $managerEmployee->id, 'first_name' => 'Anu', 'last_name' => 'Joseph', 'date_of_joining' => '2025-01-01',
        ]);
    }

    private function register(User $user, string $token = self::TOKEN)
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/me/devices', [
            'token' => $token, 'platform' => 'android', 'device_name' => 'Pixel 7', 'app_version' => '1.0.0',
        ]);
    }

    private function applyForLeave(): void
    {
        $cl = LeaveType::create(['branch_id' => $this->branch->id, 'name' => 'Casual Leave', 'code' => 'CL',
            'days_per_year' => 12, 'accrual' => 'annual', 'paid' => true]);
        ApprovalFlow::create(['branch_id' => $this->branch->id, 'module' => 'leave',
            'steps_json' => [['step' => 1, 'approver_type' => 'manager']]]);

        $this->actingAs($this->staff, 'sanctum')->postJson('/api/leaves', [
            'leave_type_id' => $cl->id, 'start_date' => '2026-09-17', 'end_date' => '2026-09-17',
        ])->assertCreated();
    }

    public function test_a_phone_registers_once_and_follows_whoever_signed_in_last(): void
    {
        $this->register($this->staff)->assertCreated();
        $this->register($this->staff)->assertCreated();
        $this->assertSame(1, DeviceToken::count());

        // Same phone, different person signs in: the token moves to them.
        $this->register($this->manager)->assertCreated();
        $this->assertSame($this->manager->id, DeviceToken::sole()->user_id);

        $this->actingAs($this->manager, 'sanctum')->deleteJson('/api/me/devices', ['token' => self::TOKEN])->assertOk();
        $this->assertSame(0, DeviceToken::count());
    }

    public function test_only_expo_push_tokens_are_accepted(): void
    {
        $this->register($this->staff, 'not-a-token')->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->actingAs($this->staff, 'sanctum')->postJson('/api/me/devices', ['token' => self::TOKEN, 'platform' => 'windows'])
            ->assertUnprocessable()->assertJsonValidationErrors('platform');
    }

    public function test_in_app_notifications_are_pushed_to_the_recipients_phones(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);
        $this->register($this->manager)->assertCreated();

        $this->applyForLeave();

        Http::assertSent(function (HttpRequest $request) {
            $message = $request->data()[0] ?? [];

            return str_contains($request->url(), 'exp.host')
                && $message['to'] === self::TOKEN
                && $message['data']['event'] === 'leave.submitted'
                && str_contains($message['body'], 'Anu Joseph');
        });
    }

    public function test_people_who_turn_push_off_get_no_pushes(): void
    {
        Http::fake();
        $this->register($this->manager)->assertCreated();
        $this->actingAs($this->manager, 'sanctum')->putJson('/api/me/notification-preferences', ['push' => false])->assertOk();

        $prefs = $this->actingAs($this->manager, 'sanctum')->getJson('/api/me/notification-preferences')->assertOk()->json('data');
        $push = collect($prefs)->firstWhere('channel', 'push');
        $this->assertTrue($push['available']);
        $this->assertFalse($push['enabled']);

        $this->applyForLeave();

        Http::assertNothingSent();
        $this->assertSame(1, $this->manager->fresh()->notifications()->count()); // the bell still gets it
    }

    public function test_a_phone_that_uninstalled_the_app_is_forgotten(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [
            ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']],
        ]])]);
        $this->register($this->manager)->assertCreated();

        $this->applyForLeave();

        $this->assertSame(0, DeviceToken::count());
    }

    public function test_a_punch_taken_offline_counts_from_when_it_was_taken(): void
    {
        ['user' => $user, 'employee' => $emp] = $this->makeUser('employee', $this->branch, true, ['date_of_joining' => '2026-01-01']);
        $shift = Shift::create(['branch_id' => $this->branch->id, 'name' => 'Showroom', 'start_time' => '09:30:00',
            'end_time' => '18:30:00', 'break_minutes' => 60, 'grace_minutes' => 10]);
        EmployeeShift::create(['employee_id' => $emp->id, 'shift_id' => $shift->id, 'effective_from' => '2026-01-01']);

        // Punched at 09:31 with no signal, synced at 11:00: on time, not late.
        $this->travelTo(CarbonImmutable::parse('2026-09-15 11:00:00', 'Asia/Kolkata'));
        $this->actingAs($user, 'sanctum')->postJson('/api/attendance/check-in', [
            'source' => 'mobile', 'captured_at' => CarbonImmutable::parse('2026-09-15 09:31:00', 'Asia/Kolkata')->toIso8601String(),
        ])->assertOk();

        $day = Attendance::where('employee_id', $emp->id)->whereDate('date', '2026-09-15')->sole();
        $this->assertSame('present', $day->status);
        $this->assertSame('2026-09-15 04:01:00', $day->check_in->utc()->format('Y-m-d H:i:s'));

        $punch = RawPunch::withoutGlobalScopes()->where('employee_id', $emp->id)->sole();
        $this->assertSame('mobile', $punch->source);
        $this->assertTrue($punch->payload['captured_offline']);
    }

    public function test_checking_out_straight_after_checking_in_is_refused_clearly(): void
    {
        ['user' => $user] = $this->makeUser('employee', $this->branch, true, ['date_of_joining' => '2026-01-01']);

        $this->actingAs($user, 'sanctum')->postJson('/api/attendance/check-in', ['source' => 'mobile'])->assertOk();
        $this->travel(1)->minutes();
        $this->actingAs($user, 'sanctum')->postJson('/api/attendance/check-out', ['source' => 'mobile'])
            ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'less than 2 minutes'));

        $this->travel(5)->minutes();
        $this->actingAs($user, 'sanctum')->postJson('/api/attendance/check-out', ['source' => 'mobile'])->assertOk();
    }

    public function test_offline_punches_must_be_recent_and_never_in_the_future(): void
    {
        ['user' => $user] = $this->makeUser('employee', $this->branch, true, ['date_of_joining' => '2026-01-01']);

        foreach (['-30 hours', '+10 minutes'] as $offset) {
            $this->actingAs($user, 'sanctum')->postJson('/api/attendance/check-in', [
                'source' => 'mobile', 'captured_at' => now()->modify($offset)->toIso8601String(),
            ])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'correction'));
        }

        $this->assertSame(0, RawPunch::withoutGlobalScopes()->count());
    }
}
