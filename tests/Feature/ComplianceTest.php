<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Models\StatutoryRule;
use App\Models\User;
use App\Services\Compliance\StatutoryReports;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Statutory returns come from finalized payroll only, and in the exact
 * shapes the EPFO / ESIC portals expect.
 */
class ComplianceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $hr;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = $this->makeBranch($this->makeCompany('Comply Motors'));
        $this->branch->update(['payroll_days_in_month' => 30, 'state' => 'Karnataka']);

        $basic = SalaryComponent::create(['branch_id' => $this->branch->id, 'name' => 'Basic', 'code' => 'BASIC', 'type' => 'earning',
            'calculation_type' => 'fixed', 'is_basic' => true, 'pf_applicable' => true, 'esi_applicable' => true]);
        $spl = SalaryComponent::create(['branch_id' => $this->branch->id, 'name' => 'Special Allowance', 'code' => 'SPL', 'type' => 'earning',
            'calculation_type' => 'fixed', 'esi_applicable' => true]);

        foreach (['PF' => config('statutory.pf'), 'ESI' => config('statutory.esi'), 'PT' => ['state' => 'Karnataka'], 'TAX' => ['mode' => 'income_tax']] as $type => $config) {
            StatutoryRule::create(['branch_id' => $this->branch->id, 'rule_type' => $type, 'config_json' => $config, 'is_active' => true, 'country' => 'IN']);
        }

        $this->hr = $this->makeUser('hr', $this->branch, false)['user'];
        $this->admin = $this->makeUser('branch_admin', $this->branch, false)['user'];

        // An ESI-covered technician and a salesperson above the ESI ceiling.
        $tech = $this->makeUser('employee', $this->branch, true, ['first_name' => 'Ravi', 'last_name' => 'Kumar', 'date_of_joining' => '2025-01-01',
            'uan' => '100900000001', 'esi_number' => '9900000001', 'tax_id' => 'ABCPK1234F'])['employee'];
        $sales = $this->makeUser('employee', $this->branch, true, ['first_name' => "D'Souza", 'last_name' => 'Maria', 'date_of_joining' => '2025-01-01'])['employee'];

        foreach ([[$tech, 12000, 3000], [$sales, 30000, 10000]] as [$employee, $b, $s]) {
            SalaryStructure::create(['employee_id' => $employee->id, 'component_id' => $basic->id, 'amount' => $b, 'effective_from' => '2025-01-01']);
            SalaryStructure::create(['employee_id' => $employee->id, 'component_id' => $spl->id, 'amount' => $s, 'effective_from' => '2025-01-01']);
        }
    }

    private function finalizedJune(): PayrollRun
    {
        $service = app(PayrollRunService::class);
        $run = PayrollRun::create(['branch_id' => $this->branch->id, 'month' => 6, 'year' => 2026]);
        $service->process($run, $this->hr);

        return $service->finalize($run->fresh(), $this->admin);
    }

    private function query(array $extra = []): string
    {
        return http_build_query(array_merge(['year' => 2026, 'month' => 6], $extra));
    }

    public function test_summary_totals_due_dates_and_data_gaps(): void
    {
        $this->finalizedJune();
        $slips = Payslip::all();

        $s = $this->actingAs($this->hr, 'sanctum')->getJson('/api/compliance/summary?' . $this->query())->assertOk()->json('data');

        $this->assertSame(2, $s['employees']);
        $this->assertSame(2, $s['pf']['members']);
        $this->assertEquals(round($slips->sum('pf_employee')), $s['pf']['employee_share']);
        $this->assertSame(500, $s['pf']['admin_charges']); // minimum per month
        $this->assertSame('2026-07-15', $s['pf']['due_date']);
        $this->assertCount(1, $s['pf']['missing_uan']); // the salesperson has no UAN
        $this->assertSame(1, $s['esi']['members']); // only the technician is under the ESI ceiling
        $this->assertSame('2026-07-07', $s['tds']['due_date']);
        $this->assertSame('2026-07-31', $s['tds']['return_due']); // Q1 return
        $this->assertEquals(200, $s['pt']['total']); // Karnataka PT: 200 for the salesperson only
    }

    public function test_pf_ecr_is_the_epfo_upload_format(): void
    {
        $this->finalizedJune();
        $tech = Payslip::whereHas('employee', fn ($q) => $q->where('uan', '100900000001'))->first();

        $ecr = $this->actingAs($this->hr, 'sanctum')->get('/api/compliance/pf-ecr/download?' . $this->query())->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", $ecr)));

        // One member with a UAN; 11 #~#-separated fields of whole rupees.
        $this->assertCount(1, $lines);
        $fields = explode('#~#', $lines[0]);
        $this->assertCount(11, $fields);
        $this->assertSame(['100900000001', 'RAVI KUMAR'], array_slice($fields, 0, 2));
        $this->assertSame((string) round($tech->pf_wage), $fields[3]);
        $this->assertSame((int) round($tech->pf_employee), (int) $fields[6]);
        $this->assertSame((int) round($tech->pf_employee), (int) $fields[7] + (int) $fields[8]); // EPS + EPF diff = employer 12%
    }

    public function test_esi_upload_uses_the_portal_template(): void
    {
        $this->finalizedJune();

        $csv = $this->actingAs($this->hr, 'sanctum')->get('/api/compliance/esi/download?' . $this->query())->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", trim($csv)))));

        $this->assertSame('IP Number', $rows[0][0]);
        $this->assertStringStartsWith('Reason Code for Zero workings days', $rows[0][4]);
        $this->assertSame(['9900000001', 'Ravi Kumar', '30'], array_slice($rows[1], 0, 3));
    }

    public function test_pan_is_masked_without_sensitive_access_and_names_are_csv_safe(): void
    {
        $this->finalizedJune();
        $reports = app(StatutoryReports::class);
        $slips = $reports->payslips(2026, 6, null);

        $masked = collect($reports->tds($slips, false))->firstWhere('employee_code', Payslip::whereHas('employee', fn ($q) => $q->where('uan', '100900000001'))->first()->employee->employee_code);
        $this->assertSame('XXXXXX234F', $masked['pan']);

        $register = $this->actingAs($this->hr, 'sanctum')->get('/api/compliance/salary-register/download?' . $this->query())->assertOk()->streamedContent();
        $this->assertStringContainsString('Basic', strtok($register, "\n"));
        $this->assertStringContainsString('Special Allowance', strtok($register, "\n"));
    }

    public function test_only_finalized_payroll_counts_and_access_is_limited(): void
    {
        $run = PayrollRun::create(['branch_id' => $this->branch->id, 'month' => 6, 'year' => 2026]);
        app(PayrollRunService::class)->process($run, $this->hr);

        $s = $this->actingAs($this->hr, 'sanctum')->getJson('/api/compliance/summary?' . $this->query())->assertOk()->json('data');
        $this->assertSame(0, $s['employees']);
        $this->assertSame('processed', $s['unfinalized_runs'][0]['status']);
        $this->actingAs($this->hr, 'sanctum')->get('/api/compliance/pf-ecr/download?' . $this->query())->assertStatus(422);

        $employee = $this->makeUser('employee', $this->branch)['user'];
        $this->actingAs($employee, 'sanctum')->getJson('/api/compliance/summary?' . $this->query())->assertForbidden();
    }
}
