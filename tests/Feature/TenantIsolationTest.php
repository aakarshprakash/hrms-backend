<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The multi-tenancy guarantees: one organisation can never read, reference
 * or modify another's data, regardless of which endpoint or id it tries.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private Branch $branchA;

    private Branch $branchB;

    private User $adminA;

    private User $adminB;

    private Employee $employeeA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = $this->makeCompany('Alpha Motors');
        $this->companyB = $this->makeCompany('Beta Clinic');
        $this->branchA = $this->makeBranch($this->companyA);
        $this->branchB = $this->makeBranch($this->companyB);

        $this->adminA = $this->makeUser('tenant_admin', $this->branchA, false)['user'];
        $this->adminB = $this->makeUser('tenant_admin', $this->branchB, false)['user'];
        $this->employeeA = $this->makeUser('employee', $this->branchA)['employee'];
    }

    public function test_rows_are_stamped_with_the_tenant_of_their_parent(): void
    {
        $this->assertSame($this->companyA->id, $this->employeeA->company_id);
        $this->assertSame($this->companyA->id, $this->adminA->company_id);
    }

    public function test_listing_never_includes_another_tenants_rows(): void
    {
        $this->actingAs($this->adminB, 'sanctum')
            ->getJson('/api/employees')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_route_model_binding_hides_another_tenants_records(): void
    {
        $this->actingAs($this->adminB, 'sanctum')->getJson("/api/employees/{$this->employeeA->id}")->assertNotFound();
        $this->actingAs($this->adminB, 'sanctum')->getJson("/api/branches/{$this->branchA->id}")->assertNotFound();
        $this->actingAs($this->adminB, 'sanctum')->putJson("/api/employees/{$this->employeeA->id}", ['first_name' => 'X'])->assertNotFound();
    }

    public function test_validation_rejects_ids_from_another_tenant(): void
    {
        $this->actingAs($this->adminB, 'sanctum')
            ->postJson('/api/holidays', ['branch_id' => $this->branchA->id, 'name' => 'X', 'date' => '2026-12-25'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id']);

        $this->assertSame(0, Holiday::withoutGlobalScopes()->count());
    }

    public function test_employee_codes_are_unique_per_tenant_not_globally(): void
    {
        $payload = fn (Branch $b, string $email) => [
            'branch_id' => $b->id, 'employee_code' => 'EMP001', 'first_name' => 'Same', 'last_name' => 'Code',
            'email' => $email, 'date_of_joining' => '2026-01-01', 'employment_type' => 'full_time',
        ];

        $this->actingAs($this->adminA, 'sanctum')->postJson('/api/employees', $payload($this->branchA, 'a@x.test'))->assertCreated();
        $this->actingAs($this->adminB, 'sanctum')->postJson('/api/employees', $payload($this->branchB, 'b@x.test'))->assertCreated();
        $this->actingAs($this->adminA, 'sanctum')->postJson('/api/employees', $payload($this->branchA, 'c@x.test'))
            ->assertStatus(422)->assertJsonValidationErrors(['employee_code']);
    }

    public function test_company_endpoint_returns_the_callers_own_organisation(): void
    {
        $this->actingAs($this->adminB, 'sanctum')->getJson('/api/company')->assertJsonPath('data.id', $this->companyB->id);
    }

    public function test_model_layer_refuses_cross_tenant_writes(): void
    {
        $this->expectException(TenantViolationException::class);

        app(TenantContext::class)->runAs($this->companyB->id, function () {
            Holiday::create(['branch_id' => $this->branchA->id, 'name' => 'Sneaky', 'date' => '2026-12-25']);
        });
    }

    public function test_suspended_tenant_is_locked_out(): void
    {
        $this->companyB->forceFill(['status' => Company::STATUS_SUSPENDED])->save();

        $this->actingAs($this->adminB, 'sanctum')->getJson('/api/employees')
            ->assertForbidden()->assertJsonPath('code', 'TENANT_SUSPENDED');
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/employees')->assertOk();
    }

    public function test_platform_admin_sees_nothing_without_support_mode_and_one_tenant_with_it(): void
    {
        $platform = User::create([
            'name' => 'Ops', 'email' => 'ops@platform.test', 'password' => bcrypt('password'), 'is_super_admin' => true,
        ]);
        $platform->assignRole('super_admin');

        $this->actingAs($platform, 'sanctum')->getJson('/api/employees')->assertJsonPath('meta.total', 0);
        $this->actingAs($platform, 'sanctum')->withHeader('X-Company-Id', (string) $this->companyA->id)
            ->getJson('/api/employees')->assertJsonPath('meta.total', 1);
        $this->actingAs($platform, 'sanctum')->getJson('/api/platform/companies')->assertJsonPath('meta.total', 2);
    }

    public function test_tenant_admin_cannot_reach_the_platform_api(): void
    {
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/platform/companies')->assertForbidden();
    }

    public function test_custom_roles_are_private_to_their_tenant(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/roles', ['name' => 'Accountant', 'permissions' => ['payroll.view']])
            ->assertCreated();

        $names = collect($this->actingAs($this->adminB, 'sanctum')->getJson('/api/roles/manage')->json('data'))->pluck('label');
        $this->assertNotContains('Accountant', $names);

        // Tenant B can create its own role with the same label.
        $this->actingAs($this->adminB, 'sanctum')
            ->postJson('/api/roles', ['name' => 'Accountant', 'permissions' => []])
            ->assertCreated();
    }
}
