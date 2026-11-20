<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Quick password reset for admins and branch admins: who may do it, and what it does. */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch1;

    private Branch $branch2;

    protected function setUp(): void
    {
        parent::setUp();

        $company = $this->makeCompany();
        $this->branch1 = $this->makeBranch($company, 'Branch 1');
        $this->branch2 = $this->makeBranch($company, 'Branch 2');
        Mail::fake();
    }

    private function login(string $email, string $password)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function reset(User $actor, int $userId, array $body = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($actor, 'sanctum')->postJson("/api/users/{$userId}/reset-password", $body);
    }

    public function test_an_admin_resets_a_password_and_the_person_must_change_it(): void
    {
        ['user' => $admin] = $this->makeUser('tenant_admin', $this->branch1, false);
        ['user' => $employee] = $this->makeUser('employee', $this->branch1);
        $employee->createToken('phone');
        $employee->createToken('laptop');

        $response = $this->reset($admin, $employee->id)->assertOk();
        $temporary = $response->json('credentials.password');

        $this->assertSame(10, strlen($temporary));
        $this->assertMatchesRegularExpression('/[A-Z]/', $temporary);
        $this->assertMatchesRegularExpression('/[a-z]/', $temporary);
        $this->assertMatchesRegularExpression('/\d/', $temporary);
        $this->assertTrue($response->json('credentials.email_sent'));

        $employee->refresh();
        $this->assertTrue($employee->must_change_password);
        $this->assertNotNull($employee->password_changed_at);
        $this->assertSame(0, $employee->tokens()->count(), 'every session is ended');

        $this->login($employee->email, 'password')->assertStatus(422);              // old password no longer works
        $this->login($employee->email, $temporary)->assertOk()->assertJsonPath('data.user.must_change_password', true);

        Mail::assertSent(NotificationMail::class, fn ($mail) => $mail->hasTo($employee->email));

        $entry = Activity::where('event', 'password_reset')->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertStringNotContainsString($temporary, json_encode($entry->properties), 'the password is never audited');
    }

    public function test_an_admin_can_type_the_password_instead_and_it_is_not_echoed_back(): void
    {
        ['user' => $admin] = $this->makeUser('tenant_admin', $this->branch1, false);
        ['user' => $employee] = $this->makeUser('employee', $this->branch1);

        $this->reset($admin, $employee->id, ['mode' => 'manual', 'password' => 'short'])->assertStatus(422);
        $this->reset($admin, $employee->id, ['mode' => 'manual'])->assertStatus(422);

        $this->reset($admin, $employee->id, ['mode' => 'manual', 'password' => 'Chosen2026'])
            ->assertOk()->assertJsonPath('credentials.password', null);

        $this->login($employee->email, 'Chosen2026')->assertOk();
    }

    public function test_the_email_is_optional(): void
    {
        ['user' => $admin] = $this->makeUser('tenant_admin', $this->branch1, false);
        ['user' => $employee] = $this->makeUser('employee', $this->branch1);

        $this->reset($admin, $employee->id, ['send_email' => false])
            ->assertOk()->assertJsonPath('credentials.email_sent', false);

        Mail::assertNothingSent();
    }

    public function test_a_branch_admin_resets_only_people_in_their_own_branch(): void
    {
        ['user' => $branchAdmin] = $this->makeUser('branch_admin', $this->branch1, false);
        ['user' => $mine] = $this->makeUser('employee', $this->branch1);
        ['user' => $theirs] = $this->makeUser('employee', $this->branch2);

        $this->reset($branchAdmin, $mine->id)->assertOk();
        $this->reset($branchAdmin, $theirs->id)->assertNotFound();

        $this->login($theirs->email, 'password')->assertOk();                          // untouched
    }

    public function test_only_a_tenant_admin_can_reset_a_tenant_admin(): void
    {
        ['user' => $branchAdmin] = $this->makeUser('branch_admin', $this->branch1, false);
        ['user' => $owner] = $this->makeUser('tenant_admin', $this->branch1, false);
        ['user' => $otherOwner] = $this->makeUser('tenant_admin', $this->branch1, false);

        $this->reset($branchAdmin, $owner->id)->assertForbidden();
        $this->reset($otherOwner, $owner->id)->assertOk();
    }

    public function test_you_cannot_reset_your_own_password_here(): void
    {
        ['user' => $admin] = $this->makeUser('tenant_admin', $this->branch1, false);

        $this->reset($admin, $admin->id)->assertStatus(422);
    }

    public function test_people_without_user_management_cannot_reset_anyone(): void
    {
        ['user' => $manager] = $this->makeUser('manager', $this->branch1);
        ['user' => $hr] = $this->makeUser('hr', $this->branch1);
        ['user' => $employee] = $this->makeUser('employee', $this->branch1);

        $this->reset($manager, $employee->id)->assertForbidden();
        $this->reset($hr, $employee->id)->assertForbidden();
        $this->reset($employee, $manager->id)->assertForbidden();
    }

    public function test_from_an_employee_profile_it_resets_an_existing_login(): void
    {
        ['user' => $branchAdmin] = $this->makeUser('branch_admin', $this->branch1, false);
        ['user' => $employee, 'employee' => $record] = $this->makeUser('employee', $this->branch1);

        $this->app['auth']->forgetGuards();
        $response = $this->actingAs($branchAdmin, 'sanctum')->postJson("/api/employees/{$record->id}/reset-password")
            ->assertOk()->assertJsonPath('credentials.created', false);

        $this->login($employee->email, $response->json('credentials.password'))->assertOk();
    }

    public function test_from_an_employee_profile_it_creates_a_login_when_there_is_none(): void
    {
        ['user' => $admin] = $this->makeUser('tenant_admin', $this->branch1, false);
        ['employee' => $record] = $this->makeUser('employee', $this->branch1, true, ['email' => 'new.hire@test.com', 'user_id' => null]);
        User::where('email', $record->getOriginal('email'))->delete();
        $record->forceFill(['user_id' => null])->save();

        $this->app['auth']->forgetGuards();
        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/employees/{$record->id}/reset-password")
            ->assertOk()->assertJsonPath('credentials.created', true)->assertJsonPath('message', 'Login created.');

        $user = User::where('email', 'new.hire@test.com')->firstOrFail();
        $this->assertTrue($user->hasRole('employee'));
        $this->assertTrue($user->must_change_password);
        $this->assertSame($record->id, $user->employee_id);
        $this->assertSame($user->id, $record->fresh()->user_id);

        $this->login('new.hire@test.com', $response->json('credentials.password'))->assertOk();
    }

    public function test_a_branch_admin_cannot_reach_another_branchs_employee(): void
    {
        ['user' => $branchAdmin] = $this->makeUser('branch_admin', $this->branch1, false);
        ['employee' => $stranger] = $this->makeUser('employee', $this->branch2);

        $this->app['auth']->forgetGuards();
        $this->actingAs($branchAdmin, 'sanctum')->postJson("/api/employees/{$stranger->id}/reset-password")->assertNotFound();
    }
}
