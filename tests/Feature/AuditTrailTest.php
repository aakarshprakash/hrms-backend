<?php

namespace Tests\Feature;

use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_changes_are_audited_without_leaking_sensitive_values(): void
    {
        $company = $this->makeCompany();
        $branch = $this->makeBranch($company);
        ['user' => $hr] = $this->makeUser('hr', $branch);
        ['employee' => $emp] = $this->makeUser('employee', $branch, true, ['bank_account_number' => '111122223333']);

        $this->actingAs($hr, 'sanctum')
            ->putJson("/api/employees/{$emp->id}", ['bank_account_number' => '999988887777', 'phone' => '9876543210'])
            ->assertOk();

        $entry = Activity::where('subject_type', \App\Models\Employee::class)
            ->where('subject_id', $emp->id)->where('event', 'updated')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($hr->id, $entry->causer_id);
        $this->assertSame($company->id, $entry->company_id);
        $this->assertSame('9876543210', $entry->properties['attributes']['phone']);
        $this->assertSame(['bank_account_number'], $entry->properties['sensitive_changed']);
        $this->assertStringNotContainsString('999988887777', json_encode($entry->properties));
        $this->assertStringNotContainsString('111122223333', json_encode($entry->properties));
    }

    public function test_a_change_to_only_a_sensitive_field_is_still_recorded(): void
    {
        $company = $this->makeCompany();
        $branch = $this->makeBranch($company);
        ['user' => $hr] = $this->makeUser('hr', $branch);
        ['employee' => $emp] = $this->makeUser('employee', $branch);

        $this->actingAs($hr, 'sanctum')->putJson("/api/employees/{$emp->id}", ['tax_id' => 'ABCDE1234F'])->assertOk();

        $this->assertTrue(Activity::where('subject_id', $emp->id)->where('event', 'updated')->exists());
    }

    public function test_audit_log_is_private_to_the_tenant_and_needs_permission(): void
    {
        $branchA = $this->makeBranch($this->makeCompany('A Co'));
        $branchB = $this->makeBranch($this->makeCompany('B Co'));
        ['user' => $adminA] = $this->makeUser('tenant_admin', $branchA, false);
        ['user' => $adminB] = $this->makeUser('tenant_admin', $branchB, false);
        ['user' => $hrA] = $this->makeUser('hr', $branchA);
        ['employee' => $emp] = $this->makeUser('employee', $branchA);

        $this->actingAs($adminA, 'sanctum')->putJson("/api/employees/{$emp->id}", ['phone' => '123'])->assertOk();

        $this->assertGreaterThan(0, $this->actingAs($adminA, 'sanctum')->getJson('/api/audit-logs')->json('meta.total'));
        $this->assertSame(0, $this->actingAs($adminB, 'sanctum')->getJson('/api/audit-logs?subject=employee')->json('meta.total'));
        $this->actingAs($hrA, 'sanctum')->getJson('/api/audit-logs')->assertForbidden();
    }
}
