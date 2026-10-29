<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\RawPunch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The unified report engine and branch geo-fencing. */
class AttendanceReportsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $hr;

    private User $staff;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'));

        $this->branch = $this->makeBranch($this->makeCompany());
        $this->branch->update(['timezone' => 'Asia/Kolkata', 'week_off_days' => [0, 6]]);
        $this->hr = $this->makeUser('hr', $this->branch, false)['user'];
        ['user' => $this->staff, 'employee' => $this->employee] = $this->makeUser('employee', $this->branch);
        $this->employee->update(['date_of_joining' => '2026-01-01']);
    }

    private function record(string $date, string $status, array $extra = []): void
    {
        Attendance::create(array_merge([
            'employee_id' => $this->employee->id, 'date' => $date, 'status' => $status, 'source' => 'api',
            'check_in' => "{$date} 03:30:00", 'check_out' => "{$date} 12:30:00", 'worked_minutes' => 480,
        ], $extra));
    }

    private function report(string $type, array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->hr, 'sanctum')->getJson("/api/attendance/report/{$type}?" . http_build_query($query + ['from' => '2026-09-01', 'to' => '2026-09-08']));
    }

    /** Sep 2026: Tue 1 present, Wed 2 late, Thu 3 absent but on approved leave, Fri 4 half day, Sat/Sun off, Mon 7 holiday, Tue 8 no record. */
    private function week(): void
    {
        $this->record('2026-09-01', 'present');
        $this->record('2026-09-02', 'late', ['late_by_minutes' => 25]);
        $this->record('2026-09-03', 'absent', ['check_in' => null, 'check_out' => null, 'worked_minutes' => 0]);
        $this->record('2026-09-04', 'half_day', ['worked_minutes' => 240, 'early_by_minutes' => 200]);

        $type = LeaveType::forceCreate(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id, 'name' => 'Casual Leave', 'code' => 'CL', 'days_per_year' => 12, 'paid' => true]);
        Leave::forceCreate(['company_id' => $this->branch->company_id, 'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'start_date' => '2026-09-03', 'end_date' => '2026-09-03', 'days' => 1, 'status' => 'approved', 'leave_year' => 2026, 'reason' => 'Family']);
        Holiday::forceCreate(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id, 'name' => 'Onam', 'date' => '2026-09-07', 'recurring' => false]);
    }

    public function test_every_day_is_resolved_once_and_rolled_up_the_same_way(): void
    {
        $this->week();

        $row = collect($this->report('summary')->assertOk()->json('data.rows'))->firstWhere('employee_id', $this->employee->id);
        $this->assertSame(2, $row['present']);       // present + late
        $this->assertSame(1, $row['late']);
        $this->assertSame(1, $row['half_day']);
        $this->assertEquals(1, $row['leave']);       // the absent day is covered by leave
        $this->assertSame(0, $row['absent']);
        $this->assertSame(2, $row['weekly_off']);
        $this->assertSame(1, $row['holiday']);
        $this->assertSame(1, $row['not_marked']);
        $this->assertSame(5, $row['working_days']);
        $this->assertEquals(6.5, $row['payable_days']); // 2 + ½ + 2 offs + 1 holiday + 1 paid leave

        // The muster roll and daily register agree with the summary.
        $muster = collect($this->report('muster')->assertOk()->json('data.rows'))->firstWhere('employee_id', $this->employee->id);
        $this->assertSame(['P', 'P', 'L', 'HD', 'WO', 'WO', 'H', '-'], array_column(array_values($muster['cells']), 'code'));
        $this->assertEquals(6.5, $muster['payable_days']);

        $daily = $this->report('daily', ['employee_id' => $this->employee->id])->assertOk();
        $this->assertSame(8, $daily->json('data.meta.total'));
        $this->assertSame('09:00', $daily->json('data.rows.0.check_in')); // 03:30 UTC in Kolkata
        $this->assertSame('Onam', collect($daily->json('data.rows'))->firstWhere('date', '2026-09-07')['remarks']);

        $late = $this->report('late_early')->assertOk();
        $this->assertSame(1, $late->json('data.totals.late_count'));
        $this->assertSame(1, $late->json('data.totals.early_count'));

        $absent = $this->report('absent')->assertOk();
        $this->assertSame(['2026-09-08'], array_column($absent->json('data.rows'), 'date'));
    }

    public function test_days_before_joining_are_not_counted(): void
    {
        $this->employee->update(['date_of_joining' => '2026-09-07']);
        Holiday::forceCreate(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id, 'name' => 'Onam', 'date' => '2026-09-07', 'recurring' => false]);
        $row = collect($this->report('summary')->json('data.rows'))->firstWhere('employee_id', $this->employee->id);

        $this->assertSame(1, $row['holiday']);
        $this->assertSame(1, $row['not_marked']);
        $this->assertSame(0, $row['weekly_off']);
    }

    public function test_exports_and_limits(): void
    {
        $this->week();

        $csv = $this->actingAs($this->hr, 'sanctum')->get('/api/attendance/report/summary/export?format=csv&from=2026-09-01&to=2026-09-08');
        $csv->assertOk();
        $this->assertStringContainsString('Payable days', $csv->streamedContent());

        $pdf = $this->actingAs($this->hr, 'sanctum')->get('/api/attendance/report/muster/export?format=pdf&from=2026-09-01&to=2026-09-30');
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));

        $this->report('muster', ['to' => '2026-10-15'])->assertStatus(422);
        $this->report('daily', ['to' => '2026-12-31'])->assertStatus(422);
        $this->report('summary', ['to' => '2026-08-01'])->assertStatus(422);
        $this->report('nonsense')->assertNotFound();
        $this->actingAs($this->staff, 'sanctum')->getJson('/api/attendance/report/summary?from=2026-09-01&to=2026-09-08')->assertForbidden();
    }

    private ?User $admin = null;

    /** Kochi showroom site, then the fence switched on. */
    private function fence(array $settings): void
    {
        $this->admin ??= $this->makeUser('tenant_admin', $this->branch, false)['user'];
        $master = $settings + ['enabled' => true, 'mode' => 'block', 'require_location' => true, 'default_radius' => 200];
        $this->actingAs($this->hr, 'sanctum')->putJson('/api/geofence/settings', $master)->assertForbidden(); // settings.manage, not HR
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/geofence/settings', $master)->assertStatus(422); // no site yet

        $this->addSite('Showroom', 9.9816, 76.2999);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/geofence/settings', $master)->assertOk();
    }

    public function test_master_switch_branch_exclusion_and_employee_switch(): void
    {
        $this->fence([]);
        $far = [10.10, 76.40];
        $this->punch(...$far)->assertStatus(422);

        // Master off: nobody is restricted.
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/geofence/settings', ['enabled' => false, 'mode' => 'block', 'require_location' => true, 'default_radius' => 200])->assertOk();
        $this->punch(...$far)->assertOk();
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/geofence/settings', ['enabled' => true, 'mode' => 'block', 'require_location' => true, 'default_radius' => 200])->assertOk();

        // This branch excluded, then included with its own "flag" behaviour.
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/branches/{$this->branch->id}/geofence", ['enabled' => false])->assertOk();
        $this->punch(...$far)->assertOk();
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/branches/{$this->branch->id}/geofence", ['enabled' => true, 'mode' => 'flag'])->assertOk();
        $this->punch(...$far)->assertOk();
        $this->assertTrue(RawPunch::first()->payload['outside_geofence']);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/branches/{$this->branch->id}/geofence", ['enabled' => true, 'mode' => null])->assertOk();
        $this->punch(...$far)->assertStatus(422); // back to the organisation's "block"

        // Switched off for one salesperson from the employee form.
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/employees/{$this->employee->id}", ['geofence_mode' => 'anywhere'])->assertOk();
        $this->assertSame('anywhere', $this->employee->fresh()->geofence_mode);
        $this->punch(...$far)->assertOk();

        $overview = $this->actingAs($this->admin, 'sanctum')->getJson('/api/geofences')->assertOk();
        $this->assertTrue($overview->json('master.enabled'));
        $this->assertSame(1, $overview->json('master.employees.anywhere'));
    }

    private function addSite(string $name, float $lat, float $lng, ?Branch $branch = null): int
    {
        $this->admin ??= $this->makeUser('tenant_admin', $this->branch, false)['user'];

        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/branches/' . ($branch ?? $this->branch)->id . '/geo-sites', [
            'name' => $name, 'latitude' => $lat, 'longitude' => $lng, 'radius_m' => 200,
        ])->assertCreated()->json('data.id');
    }

    private function punch(float $lat, float $lng): \Illuminate\Testing\TestResponse
    {
        RawPunch::query()->delete();
        \App\Models\Attendance::query()->delete();

        return $this->actingAs($this->staff, 'sanctum')->postJson('/api/attendance/check-in', ['source' => 'mobile', 'latitude' => $lat, 'longitude' => $lng]);
    }

    public function test_any_of_a_branchs_sites_counts_and_employee_rules_override(): void
    {
        $this->fence([]);
        $workshop = $this->addSite('Workshop', 10.0159, 76.3419); // ~6 km from the showroom

        $this->punch(9.9820, 76.3001)->assertOk();                        // at the showroom
        $this->punch(10.0160, 76.3420)->assertOk();                       // at the workshop
        $this->assertSame('Workshop', RawPunch::first()->payload['site']);
        $this->punch(10.10, 76.40)->assertStatus(422);                    // neither

        // Field staff: anywhere, still recorded.
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/geofence/employees', ['employee_ids' => [$this->employee->id], 'mode' => 'anywhere'])->assertOk();
        $this->punch(10.10, 76.40)->assertOk();
        $this->assertTrue(RawPunch::first()->payload['geofence_exempt']);

        // Only a site of another branch.
        $other = $this->makeBranch($this->branch->company);
        $warehouse = $this->addSite('Warehouse', 10.10, 76.40, $other);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/geofence/employees', ['employee_ids' => [$this->employee->id], 'mode' => 'sites', 'site_ids' => [$warehouse]])->assertOk();
        $this->punch(10.10, 76.40)->assertOk();
        $this->punch(9.9820, 76.3001)->assertStatus(422)->assertJsonPath('code', 'OUTSIDE_GEOFENCE'); // their own showroom no longer counts

        $rules = $this->actingAs($this->admin, 'sanctum')->getJson('/api/geofence/employees?mode=sites')->assertOk();
        $this->assertSame([['id' => $warehouse, 'name' => 'Warehouse']], $rules->json('data.0.sites'));

        // The last site can't be removed while the fence is on.
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/geo-sites/{$workshop}")->assertOk();
        $showroom = \App\Models\GeoSite::where('name', 'Showroom')->value('id');
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/geo-sites/{$showroom}")->assertStatus(422);
    }

    public function test_an_existing_single_fence_becomes_the_first_site(): void
    {
        $migration = require database_path('migrations/2026_10_29_000400_create_geo_sites.php');
        $this->branch->update(['attendance_settings' => ['geofence' => ['enabled' => true, 'latitude' => 9.98, 'longitude' => 76.29, 'radius_m' => 300, 'mode' => 'block']]]);
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        $migration->down();
        $migration->up();
        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();

        $site = \App\Models\GeoSite::where('branch_id', $this->branch->id)->firstOrFail();
        $this->assertSame('Main premises', $site->name);
        $this->assertSame(300, $site->radius_m);
    }

    public function test_a_blocking_geofence_refuses_punches_from_elsewhere(): void
    {
        $this->branch->update(['attendance_settings' => ['late_marks_per_half_day' => 3]]);
        $this->fence([]);
        $this->assertSame(3, $this->branch->fresh()->attendanceSetting('late_marks_per_half_day')); // other settings kept

        $this->actingAs($this->staff, 'sanctum')->postJson('/api/attendance/check-in', ['source' => 'mobile'])
            ->assertStatus(422)->assertJsonPath('code', 'OUTSIDE_GEOFENCE');
        $this->actingAs($this->staff, 'sanctum')->postJson('/api/attendance/check-in', ['source' => 'mobile', 'latitude' => 10.0159, 'longitude' => 76.3419])
            ->assertStatus(422)->assertJsonPath('code', 'OUTSIDE_GEOFENCE'); // ~6 km away
        $this->assertSame(0, RawPunch::count());

        $this->actingAs($this->staff, 'sanctum')->postJson('/api/attendance/check-in', ['source' => 'mobile', 'latitude' => 9.9820, 'longitude' => 76.3001])
            ->assertOk();
        $this->assertSame(1, RawPunch::count());
    }

    public function test_the_geofence_follows_the_plan(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->fence([]);
        $plan = \App\Models\Plan::where('code', 'starter')->firstOrFail();
        $this->assertContains('geofence', $plan->features);

        // A plan without the module: settings are kept, but punches aren't restricted and the screen is locked.
        $plan->update(['features' => array_values(array_diff($plan->features, ['geofence']))]);
        app(\App\Services\Billing\SubscriptionService::class)->startTrial($this->branch->company, 'starter');
        app(\App\Services\Billing\BillingFeatureResolver::class)->forget($this->branch->company_id);

        $this->actingAs($this->staff, 'sanctum')->postJson('/api/attendance/check-in', ['source' => 'mobile', 'latitude' => 10.0159, 'longitude' => 76.3419])->assertOk();
        $admin = $this->makeUser('tenant_admin', $this->branch, false)['user'];
        $this->actingAs($admin, 'sanctum')->getJson('/api/geofences')->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_IN_PLAN');
        $this->assertTrue($this->branch->company->fresh()->settings['geofence']['enabled']); // kept for when they upgrade
    }

    public function test_a_flagging_geofence_records_the_punch_for_hr(): void
    {
        $this->fence(['mode' => 'flag']);

        $this->actingAs($this->staff, 'sanctum')->postJson('/api/attendance/check-in', ['source' => 'mobile', 'latitude' => 10.0159, 'longitude' => 76.3419])
            ->assertOk();

        $punch = RawPunch::firstOrFail();
        $this->assertTrue($punch->payload['outside_geofence']);
        $this->assertGreaterThan(5000, $punch->payload['distance_m']);

        $location = $this->actingAs($this->hr, 'sanctum')->getJson('/api/attendance/report/location?from=2026-10-01&to=2026-10-01')->assertOk();
        $this->assertSame(1, $location->json('data.totals.punches'));
        $this->assertSame('Outside', $location->json('data.rows.0.geofence'));
    }
}
