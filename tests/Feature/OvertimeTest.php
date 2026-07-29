<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\OvertimeRule;
use App\Models\User;
use App\Services\OvertimeCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OvertimeTest extends TestCase
{
    use RefreshDatabase;

    protected User $hrUser;
    protected User $employeeUser;
    protected Employee $employee;
    protected Branch $branch;
    protected OvertimeRule $otRule;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'hr', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'employee', 'guard_name' => 'web']);

        $company = Company::factory()->create();
        $this->branch = Branch::factory()->create(['company_id' => $company->id]);

        $this->hrUser = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->hrUser->assignRole('hr');

        $this->employeeUser = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employeeUser->assignRole('employee');

        $this->employee = Employee::factory()->create([
            'user_id' => $this->employeeUser->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $this->otRule = OvertimeRule::factory()->create([
            'branch_id' => $this->branch->id,
            'daily_threshold_hours' => 8.00,
            'rate_multiplier' => 1.50,
        ]);

        ApprovalFlow::create([
            'branch_id' => $this->branch->id,
            'module' => 'overtime',
            'steps_json' => [['step' => 1, 'approver_role' => 'hr']],
        ]);
    }

    public function test_calculates_overtime_hours_when_employee_works_more_than_daily_threshold(): void
    {
        $date = Carbon::today();

        // Employee works 10 hours (9:00 to 19:00) vs 8h threshold = 2h OT
        Attendance::create([
            'employee_id' => $this->employee->id,
            'date' => $date->toDateString(),
            'check_in' => $date->copy()->setTime(9, 0),
            'check_out' => $date->copy()->setTime(19, 0),
            'status' => 'present',
        ]);

        $service = app(OvertimeCalculationService::class);
        $otHours = $service->calculateForDate($this->employee, $date);

        $this->assertEqualsWithDelta(2.0, $otHours, 0.01, 'OT hours should be 2.0');
    }

    public function test_returns_zero_ot_when_employee_works_within_threshold(): void
    {
        $date = Carbon::today();

        Attendance::create([
            'employee_id' => $this->employee->id,
            'date' => $date->toDateString(),
            'check_in' => $date->copy()->setTime(9, 0),
            'check_out' => $date->copy()->setTime(17, 0),
            'status' => 'present',
        ]);

        $service = app(OvertimeCalculationService::class);
        $otHours = $service->calculateForDate($this->employee, $date);

        $this->assertEqualsWithDelta(0.0, $otHours, 0.01, 'OT hours should be 0');
    }

    public function test_overtime_request_goes_pending_and_can_be_approved_by_hr(): void
    {
        $response = $this->actingAs($this->employeeUser, 'sanctum')
            ->postJson('/api/overtime-requests', [
                'employee_id' => $this->employee->id,
                'date' => Carbon::today()->toDateString(),
                'hours' => 2.0,
                'reason' => 'Project deadline',
            ]);

        $response->assertStatus(201);

        $overtimeRequest = OvertimeRequest::first();
        $this->assertNotNull($overtimeRequest);
        $this->assertEquals('pending', $overtimeRequest->status);

        // HR approves
        $approveResponse = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson("/api/overtime-requests/{$overtimeRequest->id}/approve", [
                'comments' => 'Approved by HR',
            ]);

        $approveResponse->assertStatus(200);
        $overtimeRequest->refresh();
        $this->assertEquals('approved', $overtimeRequest->status);
        $this->assertEquals($this->hrUser->id, $overtimeRequest->approved_by);
    }

    public function test_overtime_request_can_be_rejected_by_hr(): void
    {
        $this->actingAs($this->employeeUser, 'sanctum')
            ->postJson('/api/overtime-requests', [
                'employee_id' => $this->employee->id,
                'date' => Carbon::today()->toDateString(),
                'hours' => 3.0,
                'reason' => 'Extra work',
            ]);

        $overtimeRequest = OvertimeRequest::first();

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson("/api/overtime-requests/{$overtimeRequest->id}/reject", [
                'comments' => 'Not justified',
            ]);

        $response->assertStatus(200);
        $overtimeRequest->refresh();
        $this->assertEquals('rejected', $overtimeRequest->status);
    }

    public function test_branch_isolation_ot_rule_for_branch_a_not_visible_from_branch_b(): void
    {
        $company = Company::factory()->create();
        $branchB = Branch::factory()->create(['company_id' => $company->id]);
        $userB = User::factory()->create(['branch_id' => $branchB->id]);
        $userB->assignRole('hr');

        // Branch A has an OT rule (created in setUp), Branch B has none
        $response = $this->actingAs($userB, 'sanctum')->getJson('/api/overtime-rules');
        $response->assertStatus(200);

        $rules = $response->json('data');
        $branchARules = collect($rules)->where('branch_id', $this->branch->id)->values();

        $this->assertCount(0, $branchARules, 'Branch A rule should not be visible to Branch B user');
    }
}
