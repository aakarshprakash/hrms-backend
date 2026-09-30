<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Models\StatutoryRule;
use App\Services\Attendance\AttendanceProcessor;
use App\Services\Payroll\PayrollEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Attendance-linked pay, statutory deductions and the payroll run lifecycle.
 */
class PayrollEngineTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private array $components = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = $this->makeBranch($this->makeCompany('Engine Motors'));
        $this->branch->update(['payroll_days_in_month' => 30, 'state' => 'Karnataka']);

        $c = fn (array $a) => SalaryComponent::create(['branch_id' => $this->branch->id] + $a);
        $this->components = [
            'basic' => $c(['name' => 'Basic', 'code' => 'BASIC', 'type' => 'earning', 'calculation_type' => 'fixed', 'is_basic' => true, 'pf_applicable' => true]),
            'hra' => $c(['name' => 'HRA', 'code' => 'HRA', 'type' => 'earning', 'calculation_type' => 'percentage', 'percentage_of' => 'basic']),
            'special' => $c(['name' => 'Special Allowance', 'code' => 'SPL', 'type' => 'earning', 'calculation_type' => 'fixed']),
            'incentive' => $c(['name' => 'Sales Incentive', 'code' => 'INC', 'type' => 'earning', 'calculation_type' => 'fixed', 'prorate' => false, 'is_variable' => true]),
        ];

        foreach (['PF' => config('statutory.pf'), 'ESI' => config('statutory.esi'), 'PT' => ['state' => 'Karnataka'], 'TAX' => ['mode' => 'income_tax']] as $type => $config) {
            StatutoryRule::create(['branch_id' => $this->branch->id, 'rule_type' => $type, 'config_json' => $config, 'is_active' => true, 'country' => 'IN']);
        }
    }

    private function employeeWithSalary(array $amounts, array $attrs = [])
    {
        $employee = $this->makeUser('employee', $this->branch, true, array_merge(['date_of_joining' => '2025-01-01'], $attrs))['employee'];
        foreach ($amounts as $key => $amount) {
            SalaryStructure::create(['employee_id' => $employee->id, 'component_id' => $this->components[$key]->id, 'amount' => $amount, 'effective_from' => '2025-01-01']);
        }

        return $employee->fresh('branch');
    }

    private function makeRun(int $month = 6): PayrollRun
    {
        return PayrollRun::create(['branch_id' => $this->branch->id, 'month' => $month, 'year' => 2026]);
    }

    public function test_absences_prorate_earnings_and_statutory_follows_earned_wages(): void
    {
        $emp = $this->employeeWithSalary(['basic' => 20000, 'hra' => 40, 'special' => 5000, 'incentive' => 3000]);

        foreach (['2026-06-10' => 'absent', '2026-06-11' => 'absent', '2026-06-12' => 'half_day'] as $date => $status) {
            Attendance::create(['employee_id' => $emp->id, 'date' => $date, 'status' => $status, 'source' => 'system']);
        }

        $r = app(PayrollEngine::class)->compute($emp, $this->makeRun());

        $this->assertEquals(2.5, $r['lop_days']);
        $this->assertEquals(27.5, $r['payable_days']);

        $earned = collect($r['breakdown_json']['earnings'])->keyBy('code');
        $this->assertEqualsWithDelta(18333.33, $earned['BASIC']['earned'], 0.01);   // 20,000 x 27.5/30
        $this->assertEqualsWithDelta(7333.33, $earned['HRA']['earned'], 0.01);      // 40% of basic, pro-rated
        $this->assertEqualsWithDelta(4583.33, $earned['SPL']['earned'], 0.01);
        $this->assertEquals(3000, $earned['INC']['earned']);                        // variable: not pro-rated
        $this->assertEqualsWithDelta(33249.99, $r['gross_pay'], 0.02);

        $this->assertEquals(1800, $r['pf_employee']);        // 12% of min(18,333, 15,000)
        $this->assertEquals(0, $r['esi_employee']);          // fixed wage 33,000 > 21,000: not covered
        $this->assertEquals(200, $r['professional_tax']);    // Karnataka, >= 25,000
        $this->assertEquals(0, $r['tds']);                   // well under the rebate limit
        $this->assertEqualsWithDelta($r['gross_pay'] - 2000, $r['net_pay'], 0.01);
        $this->assertEqualsWithDelta($r['gross_pay'] + 550 + 1250 + 75 + 75, $r['employer_cost'], 0.01);
    }

    public function test_esi_applies_to_lower_wages(): void
    {
        $emp = $this->employeeWithSalary(['basic' => 12000, 'special' => 4000]);

        $r = app(PayrollEngine::class)->compute($emp, $this->makeRun());

        $this->assertEquals(120, $r['esi_employee']);   // 0.75% of 16,000
        $this->assertEquals(520, $r['esi_employer']);   // 3.25% of 16,000
        $this->assertEquals(0, $r['professional_tax']); // below Karnataka threshold
    }

    public function test_mid_month_joiner_is_paid_for_days_employed(): void
    {
        $emp = $this->employeeWithSalary(['basic' => 30000], ['date_of_joining' => '2026-06-16']);

        $r = app(PayrollEngine::class)->compute($emp, $this->makeRun());

        $this->assertEquals(15, $r['payable_days']);
        $this->assertEqualsWithDelta(15000, $r['gross_pay'], 0.01);
    }

    public function test_legacy_custom_tax_slabs_keep_their_original_result(): void
    {
        StatutoryRule::where('rule_type', 'TAX')->update(['config_json' => json_encode(['slabs' => [
            ['up_to' => 250000, 'rate' => 0], ['up_to' => 500000, 'rate' => 5], ['up_to' => 1000000, 'rate' => 20],
        ]])]);
        $emp = $this->employeeWithSalary(['basic' => 50000]);

        $r = app(PayrollEngine::class)->compute($emp, $this->makeRun());

        // Annualised 6L: 12,500 + 20% of 1L = 32,500 / 12
        $this->assertEqualsWithDelta(2708.33, $r['tds'], 0.01);
    }

    public function test_full_lifecycle_with_maker_checker_publication_and_attendance_lock(): void
    {
        ['user' => $hr] = $this->makeUser('hr', $this->branch);
        ['user' => $admin] = $this->makeUser('branch_admin', $this->branch, false);
        ['user' => $staff, 'employee' => $emp] = $this->makeUser('employee', $this->branch, true, ['date_of_joining' => '2025-01-01']);
        SalaryStructure::create(['employee_id' => $emp->id, 'component_id' => $this->components['basic']->id, 'amount' => 25000, 'effective_from' => '2025-01-01']);
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-06-05', 'status' => 'absent', 'source' => 'system']);

        $runId = $this->actingAs($hr, 'sanctum')
            ->postJson('/api/payroll-runs', ['branch_id' => $this->branch->id, 'month' => 6, 'year' => 2026])
            ->assertCreated()->json('data.id');

        $this->actingAs($hr, 'sanctum')->getJson("/api/payroll-runs/{$runId}/preview")->assertOk()
            ->assertJsonPath('data.totals.employees', 2); // HR + staff (the branch admin has no employee record)
        $this->actingAs($hr, 'sanctum')->postJson("/api/payroll-runs/{$runId}/run")->assertOk();

        // Not visible to the employee until finalized.
        $slip = Payslip::where('payroll_run_id', $runId)->where('employee_id', $emp->id)->firstOrFail();
        $this->assertEquals(1, (float) $slip->lop_days);
        $this->actingAs($staff, 'sanctum')->getJson('/api/payslips')->assertJsonPath('meta.total', 0);
        $this->actingAs($staff, 'sanctum')->getJson("/api/payslips/{$slip->id}")->assertNotFound();

        // HR prepares; only a checker with payroll.finalize publishes.
        $this->actingAs($hr, 'sanctum')->postJson("/api/payroll-runs/{$runId}/finalize")->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson("/api/payroll-runs/{$runId}/finalize")->assertOk();

        $this->actingAs($staff, 'sanctum')->getJson('/api/payslips')->assertJsonPath('meta.total', 1);
        $pdf = $this->actingAs($staff, 'sanctum')->get("/api/payslips/{$slip->id}/pdf");
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));

        // The month's attendance is now locked against reprocessing.
        $this->assertNotNull(Attendance::where('employee_id', $emp->id)->whereDate('date', '2026-06-05')->value('locked_at'));
        $stats = app(AttendanceProcessor::class)->processEmployee($emp->fresh(), '2026-06-05', '2026-06-05');
        $this->assertSame(1, $stats['skipped']);

        // A finalized run can't be re-run or deleted, only reopened (then paid runs are closed for good).
        $this->actingAs($hr, 'sanctum')->postJson("/api/payroll-runs/{$runId}/run")->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->postJson("/api/payroll-runs/{$runId}/mark-paid", ['payment_reference' => 'NEFT-0626'])->assertOk();
        $this->assertSame('paid', PayrollRun::find($runId)->status);
        $this->actingAs($admin, 'sanctum')->postJson("/api/payroll-runs/{$runId}/reopen")->assertStatus(422);

        $this->actingAs($hr, 'sanctum')->get("/api/payroll-runs/{$runId}/bank-export")->assertOk();
    }

    public function test_reopening_unpublishes_payslips_and_unlocks_attendance(): void
    {
        ['user' => $admin] = $this->makeUser('branch_admin', $this->branch, false);
        $emp = $this->employeeWithSalary(['basic' => 20000]);
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-06-05', 'status' => 'absent', 'source' => 'system']);
        $run = $this->makeRun();

        $this->actingAs($admin, 'sanctum')->postJson("/api/payroll-runs/{$run->id}/run")->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/payroll-runs/{$run->id}/finalize")->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/payroll-runs/{$run->id}/reopen")->assertOk();

        $this->assertSame('processed', $run->fresh()->status);
        $this->assertNull(Payslip::where('payroll_run_id', $run->id)->value('published_at'));
        $this->assertNull(Attendance::where('employee_id', $emp->id)->value('locked_at'));
    }
}
