<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceRegularization;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ApprovalFlow;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Spatie\Permission\Models\Role;

class RegularizationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'hr', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'employee', 'guard_name' => 'web']);
    }

    private function createBranchWithUsers(Company $company): array
    {
        $branch = Branch::factory()->create(['company_id' => $company->id]);
        $hrUser = User::factory()->create(['branch_id' => $branch->id]);
        $hrUser->assignRole('hr');
        $employeeUser = User::factory()->create(['branch_id' => $branch->id]);
        $employeeUser->assignRole('employee');
        $employee = Employee::factory()->create([
            'user_id' => $employeeUser->id,
            'branch_id' => $branch->id,
        ]);
        ApprovalFlow::create([
            'branch_id' => $branch->id,
            'module' => 'regularization',
            'steps_json' => [['step' => 1, 'approver_role' => 'hr']],
        ]);
        return [$branch, $hrUser, $employeeUser, $employee];
    }

    public function test_regularization_approval_updates_attendance_record(): void
    {
        $company = Company::factory()->create();
        [$branch, $hrUser, $employeeUser, $employee] = $this->createBranchWithUsers($company);

        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => now()->toDateString(),
            'check_in' => now()->setTime(9, 30),
            'check_out' => now()->setTime(17, 0),
            'status' => 'present',
            'source' => 'web',
        ]);

        $response = $this->actingAs($employeeUser, 'sanctum')->postJson('/api/attendance/regularizations', [
            'attendance_id' => $attendance->id,
            'reason' => 'Arrived earlier',
            'requested_check_in' => now()->setTime(9, 0)->format('Y-m-d H:i:s'),
            'requested_check_out' => now()->setTime(18, 0)->format('Y-m-d H:i:s'),
        ]);
        $response->assertStatus(201);
        $regId = $response->json('data.id') ?? $response->json('id');

        $approveResponse = $this->actingAs($hrUser, 'sanctum')->postJson("/api/attendance/regularizations/{$regId}/approve");
        $approveResponse->assertStatus(200);

        $attendance->refresh();
        $this->assertEquals('09:00:00', $attendance->check_in->format('H:i:s'));
        $this->assertEquals('18:00:00', $attendance->check_out->format('H:i:s'));
    }

    public function test_approval_service_scopes_approvers_to_branch(): void
    {
        $company = Company::factory()->create();
        [$branch1, $hrUser1, $employeeUser1, $employee1] = $this->createBranchWithUsers($company);
        [$branch2, $hrUser2, $employeeUser2, $employee2] = $this->createBranchWithUsers($company);

        $leaveType = \App\Models\LeaveType::factory()->create(['branch_id' => $branch1->id]);
        LeaveBalance::create([
            'employee_id' => $employee1->id,
            'leave_type_id' => $leaveType->id,
            'year' => now()->year,
            'allocated' => 10,
            'used' => 0,
            'balance' => 10,
        ]);

        ApprovalFlow::create([
            'branch_id' => $branch1->id,
            'module' => 'leave',
            'steps_json' => [['step' => 1, 'approver_role' => 'hr']],
        ]);

        $leave = Leave::create([
            'employee_id' => $employee1->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(1)->toDateString(),
            'days' => 1,
            'reason' => 'Test',
            'status' => 'pending',
        ]);

        $service = new ApprovalWorkflowService();
        $service->submitForApproval($leave, 'leave', $branch1->id);
        $leave->refresh();

        $nextApprover = $service->getNextApprover($leave);
        $this->assertNotNull($nextApprover);
        $this->assertEquals($hrUser1->id, $nextApprover->id);
        $this->assertNotEquals($hrUser2->id, $nextApprover->id);
    }
}
