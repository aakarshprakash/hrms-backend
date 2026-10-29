<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\EmployeeLoan;
use App\Models\LoanRecovery;
use App\Models\PayrollRun;
use App\Models\PayrollRunAdjustment;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Models\StatutoryRule;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Loans & advances, statutory bonus, arrears and income-tax declarations. */
class PayrollExtrasTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $payroll;

    private array $components;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 15));

        $this->branch = $this->makeBranch($this->makeCompany('Extras Motors'));
        $this->branch->update(['payroll_days_in_month' => 30, 'state' => 'Kerala']);
        $this->payroll = $this->makeUser('tenant_admin', $this->branch, false)['user'];

        $c = fn (array $a) => SalaryComponent::create(['branch_id' => $this->branch->id] + $a);
        $this->components = [
            'basic' => $c(['name' => 'Basic Salary', 'code' => 'BASIC', 'type' => 'earning', 'calculation_type' => 'fixed', 'is_basic' => true, 'pf_applicable' => true]),
            'hra' => $c(['name' => 'HRA', 'code' => 'HRA', 'type' => 'earning', 'calculation_type' => 'percentage', 'percentage_of' => 'basic']),
            'special' => $c(['name' => 'Special Allowance', 'code' => 'SPL', 'type' => 'earning', 'calculation_type' => 'fixed']),
        ];
        StatutoryRule::create(['branch_id' => $this->branch->id, 'rule_type' => 'PF', 'config_json' => config('statutory.pf'), 'is_active' => true, 'country' => 'IN']);
        StatutoryRule::create(['branch_id' => $this->branch->id, 'rule_type' => 'TAX', 'config_json' => ['mode' => 'income_tax'], 'is_active' => true, 'country' => 'IN']);
    }

    private function employee(array $salary, string $from = '2026-01-01'): array
    {
        $made = $this->makeUser('employee', $this->branch, true, ['date_of_joining' => '2025-01-01']);
        foreach ($salary as $key => $amount) {
            SalaryStructure::create(['employee_id' => $made['employee']->id, 'component_id' => $this->components[$key]->id, 'amount' => $amount, 'effective_from' => $from]);
        }

        return $made;
    }

    private function processMonth(int $month, int $year = 2026): PayrollRun
    {
        $run = PayrollRun::create(['branch_id' => $this->branch->id, 'month' => $month, 'year' => $year]);

        return app(PayrollRunService::class)->process($run, $this->payroll);
    }

    private function deduction(PayrollRun $run, int $employeeId, string $code): ?array
    {
        $p = Payslip::where('payroll_run_id', $run->id)->where('employee_id', $employeeId)->firstOrFail();

        return collect($p->breakdown_json['deductions'])->firstWhere('code', $code);
    }

    public function test_a_loan_is_recovered_through_payroll_until_settled(): void
    {
        ['employee' => $emp, 'user' => $staff] = $this->employee(['basic' => 30000]);

        $quote = $this->actingAs($this->payroll, 'sanctum')->postJson('/api/loans/quote', ['principal' => 30000, 'interest_rate' => 10, 'tenure_months' => 3])->assertOk();
        $this->assertEquals(30750, $quote->json('data.total_payable')); // 10% a year × 3 months, flat
        $this->assertEquals(10250, $quote->json('data.emi'));

        $loanId = $this->actingAs($this->payroll, 'sanctum')->postJson('/api/loans', [
            'employee_id' => $emp->id, 'type' => 'loan', 'principal' => 30000, 'interest_rate' => 10, 'tenure_months' => 3, 'start_month' => 7, 'start_year' => 2026,
        ])->assertCreated()->json('data.id');

        $june = $this->processMonth(6);
        $this->assertNull($this->deduction($june, $emp->id, 'LOAN')); // not started yet

        $july = $this->processMonth(7);
        $this->assertEquals(10250, $this->deduction($july, $emp->id, 'LOAN')['amount']);
        app(PayrollRunService::class)->process($july->fresh(), $this->payroll); // reprocessing doesn't double-count
        $this->assertSame(1, LoanRecovery::where('employee_loan_id', $loanId)->count());

        // Part-paid in cash, so August takes the EMI and September only the remainder.
        $this->actingAs($this->payroll, 'sanctum')->postJson("/api/loans/{$loanId}/repay", ['amount' => 5000, 'method' => 'cash'])->assertOk();
        $this->assertEquals(10250, $this->deduction($this->processMonth(8), $emp->id, 'LOAN')['amount']);
        $this->assertEquals(5250, $this->deduction($this->processMonth(9), $emp->id, 'LOAN')['amount']);
        $this->assertNull($this->deduction($this->processMonth(10), $emp->id, 'LOAN'));

        $loan = $this->actingAs($this->payroll, 'sanctum')->getJson("/api/loans/{$loanId}")->assertOk();
        $this->assertSame('settled', $loan->json('data.state'));
        $this->assertEquals(0, $loan->json('data.outstanding'));

        // The employee sees their own loan, not others'; they can't create one.
        $this->assertCount(1, $this->actingAs($staff, 'sanctum')->getJson('/api/loans')->assertOk()->json('data'));
        ['user' => $other] = $this->employee(['basic' => 10000]);
        $this->assertCount(0, $this->actingAs($other, 'sanctum')->getJson('/api/loans')->json('data'));
        $this->actingAs($staff, 'sanctum')->postJson('/api/loans', ['employee_id' => $emp->id])->assertForbidden();
    }

    public function test_statutory_bonus_follows_the_act(): void
    {
        ['employee' => $low] = $this->employee(['basic' => 12000, 'special' => 3000]);
        ['employee' => $high] = $this->employee(['basic' => 25000]);
        foreach ([4, 5, 6] as $m) {
            $this->processMonth($m);
        }
        $target = PayrollRun::create(['branch_id' => $this->branch->id, 'month' => 10, 'year' => 2026]);

        $q = ['branch_id' => $this->branch->id, 'financial_year' => '2026-27', 'rate' => 8.33];
        $result = $this->actingAs($this->payroll, 'sanctum')->getJson('/api/payroll/bonus?' . http_build_query($q))->assertOk();
        $rows = collect($result->json('data.rows'))->keyBy('employee_id');

        $this->assertTrue($rows[$low->id]['eligible']);
        $this->assertEquals(21000, $rows[$low->id]['bonus_wages']);   // 3 months × ₹7,000 ceiling
        $this->assertEquals(1749, $rows[$low->id]['bonus']);          // 8.33% of 21,000
        $this->assertFalse($rows[$high->id]['eligible']);             // basic above ₹21,000
        $this->assertEquals(0, $rows[$high->id]['bonus']);

        $this->actingAs($this->payroll, 'sanctum')->postJson('/api/payroll/bonus/apply', $q + ['payroll_run_id' => $target->id])->assertOk();
        $this->actingAs($this->payroll, 'sanctum')->postJson('/api/payroll/bonus/apply', $q + ['payroll_run_id' => $target->id])->assertOk(); // re-apply replaces
        $this->assertSame(1, PayrollRunAdjustment::where('payroll_run_id', $target->id)->count());

        $processed = app(PayrollRunService::class)->process($target, $this->payroll);
        $bonus = collect(Payslip::where('payroll_run_id', $processed->id)->where('employee_id', $low->id)->first()->breakdown_json['earnings'])->firstWhere('code', 'BONUS');
        $this->assertEquals(1749, $bonus['earned']);
    }

    public function test_a_backdated_revision_pays_arrears_with_pf_on_the_basic_part(): void
    {
        ['employee' => $emp] = $this->employee(['basic' => 10000, 'hra' => 40]);
        $this->processMonth(7);
        $this->processMonth(8);

        // Revised from 1 July: basic 12,000.
        SalaryStructure::where('employee_id', $emp->id)->update(['effective_to' => '2026-06-30']);
        SalaryStructure::create(['employee_id' => $emp->id, 'component_id' => $this->components['basic']->id, 'amount' => 12000, 'effective_from' => '2026-07-01']);
        SalaryStructure::create(['employee_id' => $emp->id, 'component_id' => $this->components['hra']->id, 'amount' => 40, 'effective_from' => '2026-07-01']);

        $target = PayrollRun::create(['branch_id' => $this->branch->id, 'month' => 9, 'year' => 2026]);
        $q = ['payroll_run_id' => $target->id, 'from' => '2026-07', 'to' => '2026-08'];
        $preview = $this->actingAs($this->payroll, 'sanctum')->getJson('/api/payroll/arrears?' . http_build_query($q))->assertOk();
        $row = $preview->json('data.rows.0');
        $this->assertEquals(5600, $row['arrears']);   // (2,000 basic + 800 HRA) × 2 months
        $this->assertEquals(4000, $row['pf_part']);   // the basic part, within the PF ceiling

        $this->actingAs($this->payroll, 'sanctum')->postJson('/api/payroll/arrears/apply', $q)->assertOk();
        $processed = app(PayrollRunService::class)->process($target, $this->payroll);
        $slip = Payslip::where('payroll_run_id', $processed->id)->where('employee_id', $emp->id)->first();
        $this->assertEquals(12000 + 4800 + 5600, (float) $slip->gross_pay);
        // PF on September's 12,000 plus PF on the 4,000 arrears -- not squeezed under September's own ceiling.
        $this->assertEquals(1440 + 480, (float) $slip->pf_employee);
        $this->assertEquals(480, $this->deduction($processed, $emp->id, 'PF_ARR')['amount']);
    }

    public function test_pf_on_arrears_respects_the_ceiling_of_each_month(): void
    {
        ['employee' => $crossing] = $this->employee(['basic' => 14000]);  // revised to 16,000: crosses ₹15,000
        ['employee' => $above] = $this->employee(['basic' => 20000]);     // revised to 25,000: above it throughout
        $this->processMonth(7);
        $this->processMonth(8);

        foreach ([[$crossing, 16000], [$above, 25000]] as [$emp, $basic]) {
            SalaryStructure::where('employee_id', $emp->id)->update(['effective_to' => '2026-06-30']);
            SalaryStructure::create(['employee_id' => $emp->id, 'component_id' => $this->components['basic']->id, 'amount' => $basic, 'effective_from' => '2026-07-01']);
        }

        $target = PayrollRun::create(['branch_id' => $this->branch->id, 'month' => 9, 'year' => 2026]);
        $q = ['payroll_run_id' => $target->id, 'from' => '2026-07', 'to' => '2026-08'];
        $rows = collect($this->actingAs($this->payroll, 'sanctum')->getJson('/api/payroll/arrears?' . http_build_query($q))->json('data.rows'))->keyBy('employee_id');
        $this->assertEquals(4000, $rows[$crossing->id]['arrears']);
        $this->assertEquals(2000, $rows[$crossing->id]['pf_part']);  // only up to 15,000 in July and August
        $this->assertEquals(10000, $rows[$above->id]['arrears']);
        $this->assertEquals(0, $rows[$above->id]['pf_part']);        // already at the ceiling

        $this->actingAs($this->payroll, 'sanctum')->postJson('/api/payroll/arrears/apply', $q)->assertOk();
        $processed = app(PayrollRunService::class)->process($target, $this->payroll);

        $this->assertEquals(240, $this->deduction($processed, $crossing->id, 'PF_ARR')['amount']); // 12% of 2,000
        $this->assertEquals(1800 + 240, (float) Payslip::where('payroll_run_id', $processed->id)->where('employee_id', $crossing->id)->value('pf_employee'));
        $this->assertNull($this->deduction($processed, $above->id, 'PF_ARR'));
        $this->assertEquals(1800, (float) Payslip::where('payroll_run_id', $processed->id)->where('employee_id', $above->id)->value('pf_employee'));
    }

    public function test_an_approved_old_regime_declaration_reduces_tds(): void
    {
        // ₹3L a month: ~₹18L projected for the year, taxed under the new regime.
        ['employee' => $emp, 'user' => $staff] = $this->employee(['basic' => 200000, 'hra' => 50]);
        $before = $this->processMonth(10);
        $tdsBefore = (float) Payslip::where('payroll_run_id', $before->id)->where('employee_id', $emp->id)->value('tds');
        $this->assertGreaterThan(0, $tdsBefore);

        $items = [['section' => '80C', 'amount' => 200000], ['section' => '80D_SELF', 'amount' => 30000], ['section' => 'HRA', 'amount' => 1200000], ['section' => '24B', 'amount' => 150000]];
        $preview = $this->actingAs($staff, 'sanctum')->postJson('/api/me/tax-declaration/preview', ['regime' => 'old', 'metro' => true, 'items' => $items])->assertOk();
        $lines = collect($preview->json('data.evaluation.lines'))->keyBy('section');
        $this->assertEquals(150000, $lines['80C']['eligible']);       // capped
        $this->assertEquals(25000, $lines['80D_SELF']['eligible']);   // capped
        $this->assertEquals(960000, $lines['HRA']['eligible']);       // min(HRA 12L, rent 12L − 10% of basic 2.4L, 50% of basic 12L)
        $this->assertSame('old', $preview->json('data.comparison.better'));

        $this->actingAs($staff, 'sanctum')->putJson('/api/me/tax-declaration', ['regime' => 'old', 'metro' => true, 'items' => $items, 'submit' => true])->assertOk()
            ->assertJsonPath('data.declaration.status', 'submitted');
        $id = $this->actingAs($this->payroll, 'sanctum')->getJson('/api/tax-declarations')->assertOk()->json('data.0.id');

        $this->actingAs($this->payroll, 'sanctum')->postJson("/api/tax-declarations/{$id}/review", ['decision' => 'reject'])->assertStatus(422); // a reason is needed
        $this->actingAs($this->payroll, 'sanctum')->postJson("/api/tax-declarations/{$id}/review", ['decision' => 'approve'])->assertOk();

        $emp->refresh();
        $this->assertSame('old', $emp->tax_regime);
        $this->assertEquals(1285000, (float) $emp->declared_deductions);
        $this->actingAs($staff, 'sanctum')->putJson('/api/me/tax-declaration', ['regime' => 'new', 'items' => []])->assertStatus(422); // locked once approved

        $after = app(PayrollRunService::class)->process($before->fresh(), $this->payroll);
        $this->assertLessThan($tdsBefore, (float) Payslip::where('payroll_run_id', $after->id)->where('employee_id', $emp->id)->value('tds'));
    }
}
