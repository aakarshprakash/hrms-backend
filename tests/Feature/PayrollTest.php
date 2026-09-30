<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\OvertimeRule;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $hrUser;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);

        $this->hrUser = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->hrUser->assignRole('hr');
    }

    private function basic(): SalaryComponent
    {
        return SalaryComponent::create([
            'branch_id' => $this->branch->id, 'name' => 'Basic', 'type' => 'earning', 'calculation_type' => 'fixed', 'is_basic' => true,
        ]);
    }

    private function makeRun(int $month = 6): PayrollRun
    {
        return PayrollRun::create(['branch_id' => $this->branch->id, 'month' => $month, 'year' => 2026, 'status' => 'draft']);
    }

    public function test_run_endpoint_processes_payroll(): void
    {
        $employee = Employee::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        SalaryStructure::create(['employee_id' => $employee->id, 'component_id' => $this->basic()->id, 'amount' => 30000, 'effective_from' => '2026-01-01']);
        $run = $this->makeRun();

        $this->actingAs($this->hrUser, 'sanctum')->postJson("/api/payroll-runs/{$run->id}/run")->assertOk();

        $this->assertSame('processed', $run->fresh()->status);
        $this->assertSame(1, Payslip::where('payroll_run_id', $run->id)->count());
    }

    public function test_payroll_run_creates_payslips_for_active_employees_only(): void
    {
        $employees = Employee::factory(3)->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        Employee::factory()->create(['branch_id' => $this->branch->id, 'status' => 'inactive']);

        $basic = $this->basic();
        foreach ($employees as $emp) {
            SalaryStructure::create(['employee_id' => $emp->id, 'component_id' => $basic->id, 'amount' => 30000, 'effective_from' => '2026-01-01']);
        }

        $run = app(PayrollRunService::class)->process($this->makeRun(), $this->hrUser);

        $this->assertSame('processed', $run->status);
        $this->assertCount(3, Payslip::where('payroll_run_id', $run->id)->get());
    }

    public function test_payroll_run_includes_approved_ot_pay(): void
    {
        $employee = Employee::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        SalaryStructure::create(['employee_id' => $employee->id, 'component_id' => $this->basic()->id, 'amount' => 26000, 'effective_from' => '2026-01-01']);
        OvertimeRule::create(['branch_id' => $this->branch->id, 'daily_threshold_hours' => 8, 'weekly_threshold_hours' => 48, 'rate_multiplier' => 1.5]);
        OvertimeRequest::create(['employee_id' => $employee->id, 'date' => '2026-06-15', 'hours' => 4, 'reason' => 'Test', 'status' => 'approved']);

        $result = app(PayrollEngine::class)->compute($employee->fresh('branch'), $this->makeRun());

        $ot = collect($result['breakdown_json']['earnings'])->firstWhere('code', 'OT');
        $this->assertNotNull($ot, 'Overtime should appear as an earning');
        $this->assertEqualsWithDelta(26000 / (26 * 8) * 4 * 1.5, $ot['earned'], 0.01);
        $this->assertEqualsWithDelta($result['gross_pay'] - $result['total_deductions'], $result['net_pay'], 0.01);
    }

    public function test_one_earning_one_deduction_computes_net_pay_correctly(): void
    {
        $employee = Employee::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $deduction = SalaryComponent::create(['branch_id' => $this->branch->id, 'name' => 'Canteen', 'type' => 'deduction', 'calculation_type' => 'fixed']);
        SalaryStructure::create(['employee_id' => $employee->id, 'component_id' => $this->basic()->id, 'amount' => 50000, 'effective_from' => '2026-01-01']);
        SalaryStructure::create(['employee_id' => $employee->id, 'component_id' => $deduction->id, 'amount' => 200, 'effective_from' => '2026-01-01']);

        $result = app(PayrollEngine::class)->compute($employee->fresh('branch'), $this->makeRun());

        $this->assertEqualsWithDelta(50000.00, $result['gross_pay'], 0.01);
        $this->assertEqualsWithDelta(200.00, $result['total_deductions'], 0.01);
        $this->assertEqualsWithDelta(49800.00, $result['net_pay'], 0.01);
    }

    public function test_branch_a_payroll_run_does_not_include_branch_b_employees(): void
    {
        $companyB = Company::factory()->create();
        $branchB = Branch::factory()->create(['company_id' => $companyB->id]);
        $employeesA = Employee::factory(2)->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        Employee::factory(3)->create(['branch_id' => $branchB->id, 'status' => 'active']);

        $basic = $this->basic();
        foreach ($employeesA as $emp) {
            SalaryStructure::create(['employee_id' => $emp->id, 'component_id' => $basic->id, 'amount' => 20000, 'effective_from' => '2026-01-01']);
        }

        $run = app(PayrollRunService::class)->process($this->makeRun(), $this->hrUser);

        $this->assertEqualsCanonicalizing($employeesA->pluck('id')->all(), Payslip::where('payroll_run_id', $run->id)->pluck('employee_id')->all());
    }

    public function test_cannot_create_duplicate_payroll_run_for_same_branch_month_year(): void
    {
        $payload = ['branch_id' => $this->branch->id, 'month' => 6, 'year' => 2026];

        $this->actingAs($this->hrUser, 'sanctum')->postJson('/api/payroll-runs', $payload)->assertStatus(201);
        $this->actingAs($this->hrUser, 'sanctum')->postJson('/api/payroll-runs', $payload)->assertStatus(422);
    }
}
