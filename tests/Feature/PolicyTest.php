<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PolicyTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Branch $branch1;
    protected Branch $branch2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'branch_admin', 'hr', 'manager', 'employee'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        $this->company = Company::create(['name' => 'Test Corp', 'timezone' => 'UTC']);

        $this->branch1 = Branch::create([
            'company_id' => $this->company->id,
            'name' => 'Branch 1',
            'city' => 'Mumbai',
            'country' => 'India',
            'timezone' => 'UTC',
            'currency_code' => 'INR',
        ]);

        $this->branch2 = Branch::create([
            'company_id' => $this->company->id,
            'name' => 'Branch 2',
            'city' => 'Delhi',
            'country' => 'India',
            'timezone' => 'UTC',
            'currency_code' => 'INR',
        ]);
    }

    private function createUserWithRole(string $role, int $branchId, string $suffix = ''): array
    {
        $email = "{$role}{$suffix}@policy-test.com";
        $user = User::create([
            'name' => ucfirst($role) . $suffix,
            'email' => $email,
            'password' => bcrypt('password'),
            'is_super_admin' => ($role === 'super_admin'),
            'branch_id' => $branchId,
        ]);
        $user->assignRole($role);

        $employee = Employee::withoutGlobalScopes()->create([
            'branch_id' => $branchId,
            'employee_code' => strtoupper($role) . rand(100, 999) . rand(10, 99) . $suffix,
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'email' => $email,
            'date_of_joining' => now()->toDateString(),
        ]);

        $user->update(['employee_id' => $employee->id]);
        $employee->update(['user_id' => $user->id]);

        return ['user' => $user, 'employee' => $employee];
    }

    private function createBareEmployee(int $branchId, string $code, ?int $managerId = null): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'branch_id' => $branchId,
            'employee_code' => $code,
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'email' => "{$code}@policy-test.com",
            'date_of_joining' => now()->toDateString(),
            'reporting_manager_id' => $managerId,
        ]);
    }

    public function test_manager_can_view_direct_reports(): void
    {
        ['user' => $managerUser, 'employee' => $managerEmployee] = $this->createUserWithRole('manager', $this->branch1->id);
        $directReport = $this->createBareEmployee($this->branch1->id, 'DR-001', $managerEmployee->id);

        $token = $managerUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/employees/{$directReport->id}");

        $response->assertStatus(200);
    }

    public function test_manager_cannot_view_employees_from_other_branches(): void
    {
        ['user' => $managerUser] = $this->createUserWithRole('manager', $this->branch1->id);
        $otherBranchEmp = $this->createBareEmployee($this->branch2->id, 'OB-001');

        $token = $managerUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/employees/{$otherBranchEmp->id}");

        // BranchScope filters record at model binding (404) before policy can fire (403)
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_branch_admin_can_update_employees_in_their_branch(): void
    {
        ['user' => $branchAdmin] = $this->createUserWithRole('branch_admin', $this->branch1->id);
        $emp = $this->createBareEmployee($this->branch1->id, 'BA-UPD-001');

        $token = $branchAdmin->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/employees/{$emp->id}", [
            'first_name' => 'Updated',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.first_name', 'Updated');
    }

    public function test_branch_admin_cannot_update_employees_in_other_branches(): void
    {
        ['user' => $branchAdmin] = $this->createUserWithRole('branch_admin', $this->branch1->id);
        $emp = $this->createBareEmployee($this->branch2->id, 'BA-OTHER-001');

        $token = $branchAdmin->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/employees/{$emp->id}", [
            'first_name' => 'Hacked',
        ]);

        // BranchScope filters record at model binding (404) before policy can fire (403)
        $this->assertContains($response->status(), [403, 404]);
    }
}
