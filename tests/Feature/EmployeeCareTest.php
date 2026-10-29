<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CareTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Helpdesk tickets, anonymous grievances and announcements. */
class EmployeeCareTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $hr;

    private User $staff;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = $this->makeBranch($this->makeCompany('Care Motors'));
        $this->hr = $this->makeUser('hr', $this->branch)['user'];
        $this->staff = $this->makeUser('employee', $this->branch, true, ['first_name' => 'Meera', 'last_name' => 'Nair'])['user'];
        $this->colleague = $this->makeUser('employee', $this->branch)['user'];
    }

    public function test_a_ticket_goes_from_raised_to_rated_with_response_targets(): void
    {
        $ticket = $this->actingAs($this->staff, 'sanctum')->postJson('/api/helpdesk/tickets', [
            'type' => 'request', 'category' => 'payroll', 'subject' => 'Wrong PF in September', 'description' => 'My PF deduction looks too high this month.',
        ])->assertCreated()->json('data');
        $this->assertSame('HD-00001', $ticket['number']);
        $this->assertSame('normal', $ticket['priority']);

        // HR is notified and sees it; a colleague can't.
        $this->assertSame(1, $this->hr->fresh()->notifications()->count());
        $this->actingAs($this->colleague, 'sanctum')->getJson("/api/helpdesk/tickets/{$ticket['id']}")->assertNotFound();
        $this->actingAs($this->colleague, 'sanctum')->putJson("/api/helpdesk/tickets/{$ticket['id']}", ['status' => 'closed'])->assertForbidden();
        $this->assertSame(1, $this->actingAs($this->hr, 'sanctum')->getJson('/api/helpdesk/meta')->json('data.stats.unassigned'));

        // An internal note stays internal; a reply counts as the first response and assigns the ticket.
        $this->actingAs($this->hr, 'sanctum')->postJson("/api/helpdesk/tickets/{$ticket['id']}/messages", ['body' => 'Check the arrears line', 'internal' => true])->assertOk();
        $this->actingAs($this->hr, 'sanctum')->postJson("/api/helpdesk/tickets/{$ticket['id']}/messages", ['body' => 'It includes July arrears — looking into it.'])
            ->assertOk()->assertJsonPath('data.status', 'in_progress');
        $t = CareTicket::find($ticket['id']);
        $this->assertNotNull($t->first_response_at);
        $this->assertSame($this->hr->id, $t->assigned_to);

        $mine = $this->actingAs($this->staff, 'sanctum')->getJson("/api/helpdesk/tickets/{$ticket['id']}")->assertOk();
        $this->assertCount(1, $mine->json('data.messages')); // the internal note is hidden

        $this->actingAs($this->staff, 'sanctum')->postJson("/api/helpdesk/tickets/{$ticket['id']}/rate", ['rating' => 5])->assertStatus(422); // not resolved yet
        $this->actingAs($this->hr, 'sanctum')->putJson("/api/helpdesk/tickets/{$ticket['id']}", ['status' => 'resolved'])->assertOk();
        $this->actingAs($this->staff, 'sanctum')->postJson("/api/helpdesk/tickets/{$ticket['id']}/rate", ['rating' => 4, 'comment' => 'Quick'])
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertEquals(4, $this->actingAs($this->hr, 'sanctum')->getJson('/api/helpdesk/meta')->json('data.stats.avg_rating'));
    }

    public function test_an_anonymous_grievance_hides_who_raised_it(): void
    {
        $id = $this->actingAs($this->staff, 'sanctum')->postJson('/api/helpdesk/tickets', [
            'type' => 'grievance', 'category' => 'conduct', 'subject' => 'Behaviour of a supervisor', 'description' => 'Repeated shouting in the workshop.', 'anonymous' => true,
        ])->assertCreated()->assertJsonPath('data.priority', 'high')->json('data.id');

        $list = $this->actingAs($this->hr, 'sanctum')->getJson('/api/helpdesk/tickets?scope=all')->assertOk();
        $this->assertSame('Anonymous', $list->json('data.0.employee.name'));
        $this->assertArrayNotHasKey('id', $list->json('data.0.employee'));

        $this->actingAs($this->staff, 'sanctum')->postJson("/api/helpdesk/tickets/{$id}/messages", ['body' => 'It happened again today.'])->assertOk();
        $thread = $this->actingAs($this->hr, 'sanctum')->getJson("/api/helpdesk/tickets/{$id}")->assertOk();
        $this->assertSame('Employee (anonymous)', $thread->json('data.messages.0.author'));
        $this->assertStringNotContainsString('Meera', json_encode($thread->json()));

        // The employee still sees it as theirs.
        $this->assertSame('Meera Nair', $this->actingAs($this->staff, 'sanctum')->getJson("/api/helpdesk/tickets/{$id}")->json('data.employee.name'));
    }

    public function test_announcements_reach_their_audience_and_track_acknowledgement(): void
    {
        $this->actingAs($this->staff, 'sanctum')->postJson('/api/announcements', ['title' => 'x', 'body' => 'y', 'category' => 'general'])->assertForbidden();

        $id = $this->actingAs($this->hr, 'sanctum')->postJson('/api/announcements', [
            'title' => 'New leave policy', 'body' => '<p>Please read.</p><script>alert(1)</script>', 'category' => 'policy', 'requires_ack' => true, 'pinned' => true, 'publish' => true,
        ])->assertCreated()->json('data.id');
        $draft = $this->actingAs($this->hr, 'sanctum')->postJson('/api/announcements', ['title' => 'Draft', 'body' => 'Later', 'category' => 'general'])->json('data.id');

        $feed = $this->actingAs($this->staff, 'sanctum')->getJson('/api/announcements')->assertOk();
        $this->assertSame([$id], array_column($feed->json('data'), 'id')); // drafts aren't shown
        $this->assertStringNotContainsString('<script>', $feed->json('data.0.body'));
        $this->assertSame(1, $feed->json('pending_ack'));
        $this->assertGreaterThan(0, $this->staff->fresh()->notifications()->count());
        $this->actingAs($this->staff, 'sanctum')->postJson("/api/announcements/{$draft}/read")->assertNotFound();

        $this->actingAs($this->staff, 'sanctum')->postJson("/api/announcements/{$id}/acknowledge")->assertOk();
        $this->assertSame(0, $this->actingAs($this->staff, 'sanctum')->getJson('/api/announcements')->json('pending_ack'));

        $readers = collect($this->actingAs($this->hr, 'sanctum')->getJson("/api/announcements/{$id}/readers")->assertOk()->json('data'));
        $this->assertNotNull($readers->firstWhere('user_id', $this->staff->id)['acknowledged_at']);
        $this->assertNull($readers->firstWhere('user_id', $this->colleague->id)['read_at']);
        $manage = collect($this->actingAs($this->hr, 'sanctum')->getJson('/api/announcements/manage')->json('data'))->firstWhere('id', $id);
        $this->assertSame(1, $manage['acknowledged_count']);
        $this->assertSame('live', $manage['state']);
    }
}
