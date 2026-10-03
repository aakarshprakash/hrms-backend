<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyPunchReportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $hr;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = $this->makeBranch($this->makeCompany());
        $this->branch->update(['timezone' => 'Asia/Kolkata']);
        $this->hr = $this->makeUser('hr', $this->branch, false)['user'];
        $this->employee = $this->makeUser('employee', $this->branch)['employee'];
    }

    private function url(array $filters = [], bool $export = false): string
    {
        return '/api/attendance/reports/monthly-punches'.($export ? '/export' : '').'?'.http_build_query(array_merge([
            'month' => 9, 'year' => 2026,
        ], $filters));
    }

    private function record(array $attributes = []): Attendance
    {
        return Attendance::create(array_merge([
            'employee_id' => $this->employee->id,
            'date' => '2026-09-01',
            'status' => 'present',
            'source' => 'api',
            'check_in' => '2026-09-01 04:00:00',
            'check_out' => '2026-09-01 13:00:00',
            'worked_minutes' => 480,
        ], $attributes));
    }

    public function test_calendar_shows_local_punches_and_exact_worked_minutes(): void
    {
        $this->record(['worked_minutes' => 487]);
        $this->record(['date' => '2026-08-31']);

        $response = $this->actingAs($this->hr, 'sanctum')->getJson($this->url());

        $response->assertOk()->assertJsonCount(30, 'data')
            ->assertJsonPath('data.0.date', '2026-09-01')
            ->assertJsonPath('data.0.check_in', '09:30')
            ->assertJsonPath('data.0.check_out', '18:30')
            ->assertJsonPath('data.0.timezone', 'Asia/Kolkata')
            ->assertJsonPath('data.0.worked_minutes', 487)
            ->assertJsonPath('data.0.worked_hours', 8.12)
            ->assertJsonPath('data.1.status', 'not_recorded')
            ->assertJsonPath('data.1.check_in', null)
            ->assertJsonPath('data.1.worked_minutes', null)
            ->assertJsonPath('data.29.date', '2026-09-30');
        $this->assertSame(2, Attendance::count());
    }

    public function test_leap_month_and_zero_hours_are_preserved(): void
    {
        $this->record(['date' => '2024-02-29', 'status' => 'absent', 'check_in' => null, 'check_out' => null, 'worked_minutes' => 0]);

        $this->actingAs($this->hr, 'sanctum')->getJson($this->url(['month' => 2, 'year' => 2024]))
            ->assertOk()->assertJsonCount(29, 'data')
            ->assertJsonPath('data.28.status', 'absent')
            ->assertJsonPath('data.28.worked_minutes', 0)
            ->assertJsonPath('data.28.worked_hours', 0);
    }

    public function test_overnight_punch_out_keeps_its_next_day_date(): void
    {
        $this->record(['check_in' => '2026-09-01 16:30:00', 'check_out' => '2026-09-02 00:30:00', 'worked_minutes' => 450]);

        $this->actingAs($this->hr, 'sanctum')->getJson($this->url())
            ->assertOk()->assertJsonPath('data.0.check_in', '22:00')
            ->assertJsonPath('data.0.check_out', '06:00')
            ->assertJsonPath('data.0.check_out_at', '2026-09-02 06:00:00')
            ->assertJsonPath('data.0.worked_minutes', 450);
    }

    public function test_incomplete_punches_do_not_claim_completed_work_hours(): void
    {
        $this->record(['check_out' => null, 'worked_minutes' => 0]);

        $this->actingAs($this->hr, 'sanctum')->getJson($this->url())
            ->assertOk()->assertJsonPath('data.0.check_in', '09:30')
            ->assertJsonPath('data.0.check_out', null)
            ->assertJsonPath('data.0.worked_minutes', null)
            ->assertJsonPath('data.0.worked_hours', null);
    }

    public function test_existing_daily_report_keeps_only_recorded_days_in_local_time(): void
    {
        $this->record();

        $this->actingAs($this->hr, 'sanctum')->getJson('/api/attendance/reports/daily?month=9&year=2026')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.check_in', '09:30')
            ->assertJsonPath('data.0.worked_minutes', 480);
    }

    public function test_filters_and_historical_inactive_employees(): void
    {
        $department = Department::create(['branch_id' => $this->branch->id, 'name' => 'Workshop']);
        $this->employee->update(['department_id' => $department->id, 'status' => 'inactive']);
        $this->record();
        $other = $this->makeUser('employee', $this->branch)['employee'];

        $response = $this->actingAs($this->hr, 'sanctum')->getJson($this->url([
            'branch_id' => $this->branch->id, 'department_id' => $department->id, 'employee_id' => $this->employee->id,
        ]))->assertOk()->assertJsonCount(30, 'data');
        $this->assertSame([$this->employee->id], array_values(array_unique(array_column(array_column($response->json('data'), 'employee'), 'id'))));

        $this->getJson($this->url(['department_id' => $department->id, 'employee_id' => $other->id]))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_report_and_export_keep_other_branches_and_tenants_out(): void
    {
        $otherBranch = $this->makeBranch($this->branch->company, 'Other branch');
        $this->makeUser('employee', $otherBranch);
        $this->makeUser('employee', $this->makeBranch($this->makeCompany('Other tenant')));

        $response = $this->actingAs($this->hr, 'sanctum')->getJson($this->url())->assertOk()->assertJsonCount(30, 'data');
        $this->assertSame($this->employee->id, $response->json('data.0.employee.id'));
        $this->getJson($this->url(['branch_id' => $otherBranch->id]))->assertForbidden();
        $this->getJson($this->url(['branch_id' => $otherBranch->id], true))->assertForbidden();
    }

    public function test_csv_export_matches_filtered_local_times_and_minutes(): void
    {
        $this->record(['worked_minutes' => 487]);
        $this->makeUser('employee', $this->branch);

        $response = $this->actingAs($this->hr, 'sanctum')->get($this->url(['employee_id' => $this->employee->id], true))->assertOk();
        $csv = $response->streamedContent();
        $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\n", trim($csv)));

        $this->assertCount(31, $lines);
        $this->assertSame('Punch In', $lines[0][6]);
        $this->assertSame('2026-09-01 09:30:00', $lines[1][6]);
        $this->assertSame('2026-09-01 18:30:00', $lines[1][7]);
        $this->assertSame('Asia/Kolkata', $lines[1][8]);
        $this->assertSame('8.12', $lines[1][9]);
        $this->assertSame('487', $lines[1][10]);
        $this->assertSame('', $lines[2][10]);
    }

    public function test_permissions_and_month_validation(): void
    {
        $employeeUser = $this->employee->user;
        $this->actingAs($employeeUser, 'sanctum')->getJson($this->url())->assertForbidden();
        $this->getJson($this->url([], true))->assertForbidden();

        $this->actingAs($this->hr, 'sanctum')->getJson($this->url(['month' => 13]))
            ->assertUnprocessable()->assertJsonValidationErrors('month');
        $this->getJson($this->url(['year' => 1999]))->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->getJson($this->url(['month' => 0], true))->assertUnprocessable()->assertJsonValidationErrors('month');
    }
}
