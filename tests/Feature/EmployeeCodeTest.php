<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Automatic employee numbering, per-company code uniqueness and the optional last name. */
class EmployeeCodeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = $this->makeBranch($this->makeCompany('Code Motors'));
        $this->admin = $this->makeUser('tenant_admin', $this->branch)['user'];
    }

    private function hire(array $overrides = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/employees', array_merge([
            'branch_id' => $this->branch->id, 'first_name' => 'Arun', 'email' => 'arun' . uniqid() . '@example.com',
            'date_of_joining' => '2026-10-01', 'employment_type' => 'full_time',
        ], $overrides));
    }

    public function test_codes_are_required_until_automatic_numbering_is_on(): void
    {
        $this->hire()->assertStatus(422)->assertJsonValidationErrors('employee_code');

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/employees/code-settings', [
            'auto' => true, 'prefix' => 'cm-', 'digits' => 3, 'start' => 1,
        ])->assertOk()->assertJsonPath('data.next', 'CM-001');

        // A code typed by hand earlier pushes the sequence past it.
        $this->hire(['employee_code' => 'CM-007'])->assertCreated();
        $this->assertSame('CM-008', $this->actingAs($this->admin, 'sanctum')->getJson('/api/employees/code-settings')->json('data.next'));

        $this->hire()->assertCreated()->assertJsonPath('data.employee_code', 'CM-008');
        $this->hire()->assertCreated()->assertJsonPath('data.employee_code', 'CM-009');
    }

    public function test_last_name_is_optional(): void
    {
        $id = $this->hire(['employee_code' => 'X1', 'first_name' => 'Ravi'])->assertCreated()->json('data.id');
        $this->assertSame('Ravi', Employee::find($id)->full_name);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/employees/{$id}", ['last_name' => null])->assertOk();
    }

    public function test_the_same_code_can_be_used_by_two_companies(): void
    {
        $this->hire(['employee_code' => 'EMP001'])->assertCreated();
        $this->hire(['employee_code' => 'EMP001'])->assertStatus(422);

        $other = $this->makeBranch($this->makeCompany('Other Motors'));
        $otherAdmin = $this->makeUser('tenant_admin', $other)['user'];
        $this->actingAs($otherAdmin, 'sanctum')->postJson('/api/employees', [
            'branch_id' => $other->id, 'first_name' => 'Asha', 'email' => 'asha@example.com', 'employee_code' => 'EMP001',
            'date_of_joining' => '2026-10-01', 'employment_type' => 'full_time',
        ])->assertCreated();
    }
}
