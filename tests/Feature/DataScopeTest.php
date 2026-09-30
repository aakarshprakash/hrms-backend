<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\Branch;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inside one organisation: permissions decide what a role can do, the data
 * scope (company / branch / team / self) decides whose records it sees.
 */
class DataScopeTest extends TestCase
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
    }

    public function test_each_role_sees_only_its_scope_of_employees(): void
    {
        ['user' => $manager, 'employee' => $mgrEmp] = $this->makeUser('manager', $this->branch1);
        ['user' => $employee, 'employee' => $emp] = $this->makeUser('employee', $this->branch1, true, ['reporting_manager_id' => $mgrEmp->id]);
        $this->makeUser('employee', $this->branch1);                       // same branch, not in the team
        $this->makeUser('employee', $this->branch2);                       // other branch
        ['user' => $hr] = $this->makeUser('hr', $this->branch1);
        ['user' => $admin] = $this->makeUser('tenant_admin', $this->branch1, false);

        $total = fn (User $u) => $this->actingAs($u, 'sanctum')->getJson('/api/employees')->json('meta.total');

        $this->assertSame(1, $total($employee), 'employee: self only');
        $this->assertSame(2, $total($manager), 'manager: self + reporting team');
        $this->assertSame(4, $total($hr), 'hr: every employee in branch 1');
        $this->assertSame(5, $total($admin), 'tenant admin: everyone');
    }

    public function test_employee_cannot_see_coworkers_profile(): void
    {
        ['user' => $employee] = $this->makeUser('employee', $this->branch1);
        ['employee' => $coworker] = $this->makeUser('employee', $this->branch1);

        $this->actingAs($employee, 'sanctum')->getJson("/api/employees/{$coworker->id}")->assertForbidden();
    }

    public function test_sensitive_fields_are_masked_without_the_permission(): void
    {
        ['user' => $manager, 'employee' => $mgrEmp] = $this->makeUser('manager', $this->branch1);
        ['user' => $employee, 'employee' => $emp] = $this->makeUser('employee', $this->branch1, true, [
            'reporting_manager_id' => $mgrEmp->id, 'tax_id' => 'ABCDE1234F', 'bank_account_number' => '001122334455',
        ]);
        ['user' => $hr] = $this->makeUser('hr', $this->branch1);

        $this->actingAs($manager, 'sanctum')->getJson("/api/employees/{$emp->id}")
            ->assertJsonPath('data.tax_id', 'XXXXXX234F')
            ->assertJsonPath('data.bank_account_number', 'XXXXXXXX4455');
        $this->actingAs($hr, 'sanctum')->getJson("/api/employees/{$emp->id}")->assertJsonPath('data.tax_id', 'ABCDE1234F');
        $this->actingAs($employee, 'sanctum')->getJson("/api/employees/{$emp->id}")->assertJsonPath('data.tax_id', 'ABCDE1234F');

        // And encrypted at rest.
        $raw = \DB::table('employees')->where('id', $emp->id)->value('tax_id');
        $this->assertNotSame('ABCDE1234F', $raw);
    }

    public function test_leave_listing_and_approval_respect_scope(): void
    {
        ['user' => $manager, 'employee' => $mgrEmp] = $this->makeUser('manager', $this->branch1);
        ['user' => $employee, 'employee' => $emp] = $this->makeUser('employee', $this->branch1, true, ['reporting_manager_id' => $mgrEmp->id]);
        ['employee' => $outsider] = $this->makeUser('employee', $this->branch1);
        ['user' => $otherBranchHr] = $this->makeUser('hr', $this->branch2);

        $type = LeaveType::create(['branch_id' => $this->branch1->id, 'name' => 'Casual', 'days_per_year' => 12, 'paid' => true]);
        ApprovalFlow::create(['branch_id' => $this->branch1->id, 'module' => 'leave', 'steps_json' => [['step' => 1, 'approver_role' => 'manager']]]);

        $service = app(ApprovalWorkflowService::class);
        $mine = Leave::create(['employee_id' => $emp->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'days' => 1]);
        $service->submitForApproval($mine, 'leave', $this->branch1->id);
        $theirs = Leave::create(['employee_id' => $outsider->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-06', 'end_date' => '2026-10-06', 'days' => 1]);
        $service->submitForApproval($theirs, 'leave', $this->branch1->id);

        // Employees list only their own leave, and can never approve.
        $this->assertSame([$mine->id], collect($this->actingAs($employee, 'sanctum')->getJson('/api/leaves')->json('data'))->pluck('id')->all());
        $this->actingAs($employee, 'sanctum')->postJson("/api/leaves/{$mine->id}/approve")->assertForbidden();

        // The manager approves their report, but not someone outside their team.
        $this->actingAs($manager, 'sanctum')->postJson("/api/leaves/{$theirs->id}/approve")->assertNotFound();
        $this->actingAs($manager, 'sanctum')->postJson("/api/leaves/{$mine->id}/approve")->assertOk();
        $this->assertSame('approved', $mine->fresh()->status);

        // HR of another branch can't touch branch 1 requests.
        $this->actingAs($otherBranchHr, 'sanctum')->postJson("/api/leaves/{$theirs->id}/reject")->assertNotFound();
    }

    public function test_nobody_approves_their_own_request(): void
    {
        ['user' => $hr, 'employee' => $hrEmp] = $this->makeUser('hr', $this->branch1);
        $type = LeaveType::create(['branch_id' => $this->branch1->id, 'name' => 'Casual', 'days_per_year' => 12, 'paid' => true]);
        ApprovalFlow::create(['branch_id' => $this->branch1->id, 'module' => 'leave', 'steps_json' => [['step' => 1, 'approver_role' => 'hr']]]);

        $leave = Leave::create(['employee_id' => $hrEmp->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'days' => 1]);
        app(ApprovalWorkflowService::class)->submitForApproval($leave, 'leave', $this->branch1->id);

        $this->actingAs($hr, 'sanctum')->postJson("/api/leaves/{$leave->id}/approve")->assertForbidden();
    }

    public function test_writes_require_permission_and_branch_access(): void
    {
        ['user' => $employee] = $this->makeUser('employee', $this->branch1);
        ['user' => $branchAdmin2] = $this->makeUser('branch_admin', $this->branch2, false);

        $this->actingAs($employee, 'sanctum')->postJson('/api/branches', ['name' => 'X'])->assertForbidden();
        $this->actingAs($employee, 'sanctum')->postJson('/api/salary-components', [
            'branch_id' => $this->branch1->id, 'name' => 'X', 'type' => 'earning', 'calculation_type' => 'fixed',
        ])->assertForbidden();

        $holiday = ['name' => 'Local Fest', 'date' => '2026-11-11'];
        $this->actingAs($branchAdmin2, 'sanctum')->postJson('/api/holidays', $holiday + ['branch_id' => $this->branch1->id])->assertForbidden();
        $this->actingAs($branchAdmin2, 'sanctum')->postJson('/api/holidays', $holiday + ['branch_id' => $this->branch2->id])->assertCreated();
    }

    public function test_admins_cannot_grant_more_access_than_they_have(): void
    {
        ['user' => $branchAdmin] = $this->makeUser('branch_admin', $this->branch1, false);

        $payload = ['name' => 'New', 'email' => 'new@x.test', 'password' => 'Password123', 'user_type' => 'system'];

        $this->actingAs($branchAdmin, 'sanctum')->postJson('/api/users', $payload + ['role' => 'tenant_admin'])->assertForbidden();
        $this->actingAs($branchAdmin, 'sanctum')->postJson('/api/users', $payload + ['role' => 'super_admin'])->assertStatus(422);
        $this->actingAs($branchAdmin, 'sanctum')->postJson('/api/users', $payload + ['role' => 'hr', 'branch_id' => $this->branch2->id])->assertForbidden();
        $this->actingAs($branchAdmin, 'sanctum')->postJson('/api/users', $payload + ['role' => 'hr'])->assertCreated()
            ->assertJsonPath('data.branch_id', $this->branch1->id);
    }
}
