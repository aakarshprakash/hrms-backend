<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\NotificationLog;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * In-app notifications follow the approval steps; email / SMS / WhatsApp
 * follow the tenant's settings, each person's opt-outs and the outbox.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $hr;

    private User $manager;

    private User $staff;

    private Employee $staffEmployee;

    private LeaveType $cl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'UTC'));

        $this->branch = $this->makeBranch($this->makeCompany('Notify Motors'));
        $this->branch->update(['timezone' => 'Asia/Kolkata', 'week_off_days' => [0]]);

        $this->hr = $this->makeUser('hr', $this->branch)['user'];
        ['user' => $this->manager, 'employee' => $managerEmployee] = $this->makeUser('manager', $this->branch);
        ['user' => $this->staff, 'employee' => $this->staffEmployee] = $this->makeUser('employee', $this->branch, true, [
            'reporting_manager_id' => $managerEmployee->id, 'first_name' => 'Anu', 'last_name' => 'Joseph', 'date_of_joining' => '2025-01-01',
        ]);
        $this->staff->update(['phone' => '98470 12345']);

        $this->cl = LeaveType::create(['branch_id' => $this->branch->id, 'name' => 'Casual Leave', 'code' => 'CL',
            'days_per_year' => 12, 'accrual' => 'annual', 'paid' => true]);
        ApprovalFlow::create(['branch_id' => $this->branch->id, 'module' => 'leave',
            'steps_json' => [['step' => 1, 'approver_type' => 'manager'], ['step' => 2, 'approver_type' => 'hr']]]);
    }

    private function apply(): int
    {
        return $this->actingAs($this->staff, 'sanctum')->postJson('/api/leaves', [
            'leave_type_id' => $this->cl->id, 'start_date' => '2026-09-17', 'end_date' => '2026-09-17',
        ])->assertCreated()->json('data.id');
    }

    private function events(User $user): array
    {
        return $user->fresh()->notifications()->pluck('type')->all();
    }

    private function settings(array $attributes): NotificationSetting
    {
        $settings = new NotificationSetting($attributes);
        $settings->forceFill(['company_id' => $this->branch->company_id])->save();

        return $settings;
    }

    public function test_each_step_notifies_its_approver_and_the_decision_reaches_the_employee(): void
    {
        $leaveId = $this->apply();

        $this->assertSame(['leave.submitted'], $this->events($this->manager));
        $this->assertSame([], $this->events($this->hr)); // not their step yet

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/leaves/{$leaveId}/approve")->assertOk();
        $this->assertSame(['leave.submitted'], $this->events($this->hr));
        $this->assertSame([], $this->events($this->staff));

        $this->actingAs($this->hr, 'sanctum')->postJson("/api/leaves/{$leaveId}/approve", ['comments' => 'Enjoy'])->assertOk();
        $decided = $this->staff->fresh()->notifications()->first();
        $this->assertSame('leave.decided', $decided->type);
        $this->assertStringContainsString('approved', $decided->data['body']);
        $this->assertStringContainsString('Enjoy', $decided->data['body']);

        // Nothing leaves the building without settings: in-app only.
        $this->assertSame(0, NotificationLog::count());
    }

    public function test_bell_endpoints_only_touch_my_own_notifications(): void
    {
        $this->apply();
        $managerNotification = $this->manager->fresh()->notifications()->first();

        $this->actingAs($this->manager, 'sanctum')->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 1);
        $this->actingAs($this->staff, 'sanctum')->postJson("/api/notifications/{$managerNotification->id}/read")->assertNotFound();

        $list = $this->actingAs($this->manager, 'sanctum')->getJson('/api/notifications')->assertOk();
        $this->assertSame('/approvals', $list->json('data.0.link'));

        $this->actingAs($this->manager, 'sanctum')->postJson('/api/notifications/read-all')->assertOk();
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/notifications/unread-count')->assertJsonPath('data.unread', 0);
    }

    public function test_email_and_sms_follow_settings_and_personal_opt_outs(): void
    {
        $this->settings([
            'email_enabled' => true, 'sms_enabled' => true, 'sms_provider' => 'log',
            'events' => ['leave.decided' => ['channels' => ['in_app', 'email', 'sms']]],
        ]);

        $this->actingAs($this->hr, 'sanctum')->postJson('/api/leaves', [
            'employee_id' => $this->staffEmployee->id, 'leave_type_id' => $this->cl->id,
            'start_date' => '2026-09-17', 'end_date' => '2026-09-17', 'approve_now' => true,
        ])->assertCreated();

        $logs = NotificationLog::orderBy('channel')->get();
        $this->assertSame(['email', 'sms'], $logs->pluck('channel')->all());
        $this->assertSame(['sent', 'sent'], $logs->pluck('status')->all()); // delivered right after the response
        $this->assertSame('+919847012345', $logs->firstWhere('channel', 'sms')->recipient);
        $this->assertSame(['Anu', 'Casual Leave', '17 Sep 2026', 'approved'], $logs->first()->variables['params']);

        // Anu turns SMS off for herself.
        $this->actingAs($this->staff, 'sanctum')->putJson('/api/me/notification-preferences', ['sms' => false])->assertOk();
        $this->actingAs($this->hr, 'sanctum')->postJson('/api/leaves', [
            'employee_id' => $this->staffEmployee->id, 'leave_type_id' => $this->cl->id,
            'start_date' => '2026-09-18', 'end_date' => '2026-09-18', 'approve_now' => true,
        ])->assertCreated();
        $this->assertSame(['email'], NotificationLog::where('id', '>', $logs->max('id'))->pluck('channel')->all());
    }

    public function test_template_providers_receive_variables_in_catalog_order(): void
    {
        Http::fake([
            'control.msg91.com/*' => Http::response(['type' => 'success', 'message' => 'req-123']),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]]),
        ]);
        $this->settings([
            'sms_enabled' => true, 'sms_provider' => 'msg91', 'sms_credentials' => ['auth_key' => 'k-1', 'sender_id' => 'VMOTOR'],
            'whatsapp_enabled' => true, 'whatsapp_provider' => 'meta', 'whatsapp_credentials' => ['access_token' => 't-1', 'phone_number_id' => '555'],
            'events' => ['leave.decided' => ['channels' => ['sms', 'whatsapp'], 'sms_template' => 'flow-9', 'whatsapp_template' => 'leave_status']],
        ]);

        $this->actingAs($this->hr, 'sanctum')->postJson('/api/leaves', [
            'employee_id' => $this->staffEmployee->id, 'leave_type_id' => $this->cl->id,
            'start_date' => '2026-09-17', 'end_date' => '2026-09-17', 'approve_now' => true,
        ])->assertCreated();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'msg91') && $r->header('authkey')[0] === 'k-1'
            && $r['template_id'] === 'flow-9' && $r['recipients'][0]['mobiles'] === '919847012345'
            && $r['recipients'][0]['VAR1'] === 'Anu' && $r['recipients'][0]['VAR4'] === 'approved');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com/v20.0/555/messages')
            && $r['template']['name'] === 'leave_status' && $r['to'] === '919847012345'
            && $r['template']['components'][0]['parameters'][1]['text'] === 'Casual Leave');
        $this->assertSame(['sent', 'sent'], NotificationLog::pluck('status')->all());
    }

    public function test_failures_retry_with_backoff_then_give_up(): void
    {
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'error', 'message' => 'Invalid auth key'], 401)]);
        $this->settings([
            'sms_enabled' => true, 'sms_provider' => 'msg91', 'sms_credentials' => ['auth_key' => 'bad'],
            'events' => ['leave.decided' => ['channels' => ['sms'], 'sms_template' => 'flow-9']],
        ]);

        $this->actingAs($this->hr, 'sanctum')->postJson('/api/leaves', [
            'employee_id' => $this->staffEmployee->id, 'leave_type_id' => $this->cl->id,
            'start_date' => '2026-09-17', 'end_date' => '2026-09-17', 'approve_now' => true,
        ])->assertCreated();

        $log = NotificationLog::first();
        $this->assertSame(['queued', 1], [$log->status, $log->attempts]);
        $this->assertStringContainsString('Invalid auth key', $log->error);

        $dispatcher = app(NotificationDispatcher::class);
        for ($i = 0; $i < 6; $i++) {
            $this->travel(40)->minutes();
            $dispatcher->deliverDue();
        }

        $this->assertSame(['failed', NotificationDispatcher::MAX_ATTEMPTS], [$log->fresh()->status, $log->fresh()->attempts]);
    }

    public function test_settings_api_keeps_secrets_write_only(): void
    {
        $admin = $this->makeUser('tenant_admin', $this->branch, false)['user'];

        $this->actingAs($admin, 'sanctum')->putJson('/api/notification-settings', [
            'sms_enabled' => true, 'sms_provider' => 'msg91', 'sms_credentials' => ['auth_key' => 'secret-auth-key-1234', 'sender_id' => 'VMOTOR'],
        ])->assertOk();

        $shown = $this->actingAs($admin, 'sanctum')->getJson('/api/notification-settings')->assertOk();
        $this->assertStringNotContainsString('secret-auth-key', $shown->getContent());
        $this->assertTrue($shown->json('data.sms_credentials.auth_key.set'));
        $this->assertSame('VMOTOR', $shown->json('data.sms_credentials.sender_id.value'));

        // Saving the form again with the secret left blank keeps it.
        $this->actingAs($admin, 'sanctum')->putJson('/api/notification-settings', ['sms_credentials' => ['auth_key' => '', 'sender_id' => 'VMOTRS']])->assertOk();
        $this->assertSame('secret-auth-key-1234', NotificationSetting::first()->credentialsFor('sms')['auth_key']);

        // Only notification managers get in.
        $this->actingAs($this->staff, 'sanctum')->getJson('/api/notification-settings')->assertForbidden();
    }
}
