<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\ApprovalFlow;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Spatie\Permission\Models\Role;

class LeaveLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $hrUser;
    protected User $employeeUser;
    protected Employee $employee;
    protected LeaveType $leaveType;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        $hrRole = Role::firstOrCreate(['name' => 'hr', 'guard_name' => 'web']);
        $employeeRole = Role::firstOrCreate(['name' => 'employee', 'guard_name' => 'web']);

        // Create company and branch
        $company = Company::factory()->create();
        $this->branch = Branch::factory()->create(['company_id' => $company->id]);

        // HR user
        $this->hrUser = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->hrUser->assignRole('hr');

        // Employee user
        $this->employeeUser = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employeeUser->assignRole('employee');
        $this->employee = Employee::factory()->create([
            'user_id' => $this->employeeUser->id,
            'branch_id' => $this->branch->id,
        ]);

        // Leave type
        $this->leaveType = LeaveType::factory()->create(['branch_id' => $this->branch->id, 'days_per_year' => 12]);

        // Setup approval flow
        ApprovalFlow::create([
            'branch_id' => $this->branch->id,
            'module' => 'leave',
            'steps_json' => [['step' => 1, 'approver_role' => 'hr']],
        ]);
    }

    public function test_employee_can_submit_leave_and_balance_is_deducted_on_approval(): void
    {
        LeaveBalance::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'year' => now()->year,
            'allocated' => 10,
            'used' => 0,
            'balance' => 10,
        ]);

        // Pick the next Monday to guarantee it's a weekday
        $nextMonday = now()->next('Monday')->toDateString();

        $response = $this->actingAs($this->employeeUser, 'sanctum')->postJson('/api/leaves', [
            'leave_type_id' => $this->leaveType->id,
            'start_date' => $nextMonday,
            'end_date' => $nextMonday,
            'reason' => 'Personal',
        ]);
        $response->assertStatus(201);
        $leaveId = $response->json('data.id') ?? $response->json('id');

        $leave = Leave::find($leaveId);
        $this->assertEquals('pending', $leave->status);

        $approveResponse = $this->actingAs($this->hrUser, 'sanctum')->postJson("/api/leaves/{$leaveId}/approve");
        $approveResponse->assertStatus(200);

        $leave->refresh();
        $this->assertEquals('approved', $leave->status);

        $balance = LeaveBalance::where('employee_id', $this->employee->id)
            ->where('leave_type_id', $this->leaveType->id)
            ->where('year', now()->year)
            ->first();
        $this->assertLessThan(10, (float) $balance->balance);
    }

    public function test_leave_submission_fails_when_insufficient_balance(): void
    {
        LeaveBalance::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'year' => now()->year,
            'allocated' => 1,
            'used' => 0,
            'balance' => 1,
        ]);

        // Submit a 5-day leave (Mon-Fri next week)
        $monday = now()->next('Monday');
        $friday = $monday->copy()->addDays(4);

        $response = $this->actingAs($this->employeeUser, 'sanctum')->postJson('/api/leaves', [
            'leave_type_id' => $this->leaveType->id,
            'start_date' => $monday->toDateString(),
            'end_date' => $friday->toDateString(),
            'reason' => 'Personal',
        ]);
        $response->assertStatus(422);
    }
}
