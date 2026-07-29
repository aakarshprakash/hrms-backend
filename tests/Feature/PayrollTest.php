<?php

namespace Tests\Feature;

use App\Jobs\PayrollRunJob;
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
use App\Services\PayrollCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
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

        Role::firstOrCreate(['name' => 'hr', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'employee', 'guard_name' => 'web']);

        $this->company = Company::factory()->create();
        $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);

        $this->hrUser = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->hrUser->assignRole('hr');
    }

    public function test_payroll_run_job_is_dispatched_when_run_endpoint_called(): void
    {
        Bus::fake();

        $run = PayrollRun::create([
            'branch_id' => $this->branch->id,
            'month' => 6,
            'year' => 2026,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson("/api/payroll-runs/{$run->id}/run");

        $response->assertStatus(200);
        Bus::assertDispatched(PayrollRunJob::class, function ($job) use ($run) {
            return $job->payrollRun->id === $run->id;
        });
    }

    public function test_payroll_run_creates_payslips_for_active_employees_only(): void
    {
        $employees = Employee::factory(3)->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        // Inactive employee — should NOT get payslip
        Employee::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'inactive',
        ]);

        $basicComponent = SalaryComponent::create([
            'branch_id' => $this->branch->id,
            'name' => 'Basic',
            'type' => 'earning',
            'calculation_type' => 'fixed',
        ]);

        foreach ($employees as $emp) {
            SalaryStructure::create([
                'employee_id' => $emp->id,
                'component_id' => $basicComponent->id,
                'amount' => 30000,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ]);
        }

        $run = PayrollRun::create([
            'branch_id' => $this->branch->id,
            'month' => 6,
            'year' => 2026,
            'status' => 'draft',
        ]);

        $job = new PayrollRunJob($run);
        $job->handle(app(PayrollCalculationService::class));

        $run->refresh();
        $this->assertEquals('completed', $run->status);
        $this->assertCount(3, Payslip::where('payroll_run_id', $run->id)->get());
    }

    public function test_payroll_run_includes_approved_ot_pay(): void
    {
        $employee = Employee::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $basicComponent = SalaryComponent::create([
            'branch_id' => $this->branch->id,
            'name' => 'Basic',
            'type' => 'earning',
            'calculation_type' => 'fixed',
        ]);

        SalaryStructure::create([
            'employee_id' => $employee->id,
            'component_id' => $basicComponent->id,
            'amount' => 26000,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        OvertimeRule::create([
            'branch_id' => $this->branch->id,
            'daily_threshold_hours' => 8,
            'weekly_threshold_hours' => 48,
            'rate_multiplier' => 1.5,
        ]);

        // Approved OT: 4 hours in June 2026
        OvertimeRequest::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-15',
            'hours' => 4,
            'reason' => 'Test',
            'status' => 'approved',
        ]);

        $run = PayrollRun::create([
            'branch_id' => $this->branch->id,
            'month' => 6,
            'year' => 2026,
            'status' => 'draft',
        ]);

        $service = app(PayrollCalculationService::class);
        $result = $service->computeForEmployee($employee, $run);

        $this->assertGreaterThan(0, $result['breakdown_json']['ot_pay'], 'OT pay should be > 0');
        // net = gross + ot - deductions
        $expectedNet = round($result['gross_pay'] + $result['breakdown_json']['ot_pay'] - $result['total_deductions'], 2);
        $this->assertEqualsWithDelta($expectedNet, (float) $result['net_pay'], 0.01);
    }

    public function test_one_earning_one_deduction_computes_net_pay_correctly(): void
    {
        $employee = Employee::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $earnComponent = SalaryComponent::create([
            'branch_id' => $this->branch->id,
            'name' => 'Basic',
            'type' => 'earning',
            'calculation_type' => 'fixed',
        ]);

        $deductComponent = SalaryComponent::create([
            'branch_id' => $this->branch->id,
            'name' => 'Professional Tax',
            'type' => 'deduction',
            'calculation_type' => 'fixed',
        ]);

        SalaryStructure::create([
            'employee_id' => $employee->id,
            'component_id' => $earnComponent->id,
            'amount' => 50000,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        SalaryStructure::create([
            'employee_id' => $employee->id,
            'component_id' => $deductComponent->id,
            'amount' => 200,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $run = PayrollRun::create([
            'branch_id' => $this->branch->id,
            'month' => 6,
            'year' => 2026,
            'status' => 'draft',
        ]);

        $service = app(PayrollCalculationService::class);
        $result = $service->computeForEmployee($employee, $run);

        $this->assertEqualsWithDelta(50000.00, (float) $result['gross_pay'], 0.01);
        $this->assertEqualsWithDelta(200.00, (float) $result['total_deductions'], 0.01);
        $this->assertEqualsWithDelta(49800.00, (float) $result['net_pay'], 0.01);
    }

    public function test_branch_a_payroll_run_does_not_include_branch_b_employees(): void
    {
        $companyB = Company::factory()->create();
        $branchB = Branch::factory()->create(['company_id' => $companyB->id]);

        $employeesA = Employee::factory(2)->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        // Branch B employees — should not be in Branch A payroll
        Employee::factory(3)->create([
            'branch_id' => $branchB->id,
            'status' => 'active',
        ]);

        $basicComponent = SalaryComponent::create([
            'branch_id' => $this->branch->id,
            'name' => 'Basic',
            'type' => 'earning',
            'calculation_type' => 'fixed',
        ]);

        foreach ($employeesA as $emp) {
            SalaryStructure::create([
                'employee_id' => $emp->id,
                'component_id' => $basicComponent->id,
                'amount' => 20000,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ]);
        }

        $run = PayrollRun::create([
            'branch_id' => $this->branch->id,
            'month' => 6,
            'year' => 2026,
            'status' => 'draft',
        ]);

        $job = new PayrollRunJob($run);
        $job->handle(app(PayrollCalculationService::class));

        $payslips = Payslip::where('payroll_run_id', $run->id)->get();
        $this->assertCount(2, $payslips);

        $employeeIds = $payslips->pluck('employee_id')->all();
        $branchAEmployeeIds = $employeesA->pluck('id')->all();
        foreach ($employeeIds as $id) {
            $this->assertContains($id, $branchAEmployeeIds);
        }
    }

    public function test_cannot_create_duplicate_payroll_run_for_same_branch_month_year(): void
    {
        $payload = [
            'branch_id' => $this->branch->id,
            'month' => 6,
            'year' => 2026,
        ];

        $this->actingAs($this->hrUser, 'sanctum')->postJson('/api/payroll-runs', $payload)->assertStatus(201);
        $this->actingAs($this->hrUser, 'sanctum')->postJson('/api/payroll-runs', $payload)->assertStatus(422);
    }
}
