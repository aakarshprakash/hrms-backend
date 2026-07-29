<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeTest extends TestCase
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

    private function createUserWithRole(string $role, ?int $branchId = null, string $suffix = ''): array
    {
        $email = "{$role}{$suffix}@test.com";
        $user = User::create([
            'name' => ucfirst($role) . $suffix,
            'email' => $email,
            'password' => bcrypt('password'),
            'is_super_admin' => ($role === 'super_admin'),
            'branch_id' => $branchId,
        ]);
        $user->assignRole($role);

        $employee = Employee::withoutGlobalScopes()->create([
            'branch_id' => $branchId ?? $this->branch1->id,
            'employee_code' => strtoupper($role) . rand(100, 999) . $suffix,
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'email' => $email,
            'date_of_joining' => now()->toDateString(),
        ]);

        $user->update(['employee_id' => $employee->id]);
        $employee->update(['user_id' => $user->id]);

        return ['user' => $user, 'employee' => $employee];
    }

    private function createBareEmployee(int $branchId, string $code): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'branch_id' => $branchId,
            'employee_code' => $code,
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'email' => "{$code}@test.com",
            'date_of_joining' => now()->toDateString(),
        ]);
    }

    public function test_super_admin_can_list_all_employees_across_branches(): void
    {
        ['user' => $superAdmin] = $this->createUserWithRole('super_admin');
        $this->createBareEmployee($this->branch1->id, 'EMP-B1-A');
        $this->createBareEmployee($this->branch2->id, 'EMP-B2-A');

        $token = $superAdmin->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/employees');

        $response->assertStatus(200);

        // Super admin sees all employees across both branches (including own)
        $total = $response->json('data.total');
        $this->assertGreaterThanOrEqual(3, $total);
    }

    public function test_hr_user_only_sees_employees_in_their_branch(): void
    {
        ['user' => $hrUser] = $this->createUserWithRole('hr', $this->branch1->id);
        $this->createBareEmployee($this->branch1->id, 'EMP-B1-B');
        $this->createBareEmployee($this->branch2->id, 'EMP-B2-B');

        $token = $hrUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/employees');

        $response->assertStatus(200);

        $employees = $response->json('data.data');
        foreach ($employees as $emp) {
            $this->assertEquals($this->branch1->id, $emp['branch_id']);
        }
    }

    public function test_hr_can_create_employee(): void
    {
        ['user' => $hrUser] = $this->createUserWithRole('hr', $this->branch1->id);
        $token = $hrUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/employees', [
            'branch_id' => $this->branch1->id,
            'employee_code' => 'NEW-EMP-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@test.com',
            'date_of_joining' => now()->toDateString(),
            'employment_type' => 'full_time',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.employee_code', 'NEW-EMP-001');
    }

    public function test_employee_cannot_create_employee(): void
    {
        ['user' => $empUser] = $this->createUserWithRole('employee', $this->branch1->id);
        $token = $empUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/employees', [
            'branch_id' => $this->branch1->id,
            'employee_code' => 'NEW-EMP-002',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe2@test.com',
            'date_of_joining' => now()->toDateString(),
            'employment_type' => 'full_time',
        ]);

        $response->assertStatus(403);
    }

    public function test_branch_scoping_employee_in_branch1_cannot_see_employees_in_branch2(): void
    {
        ['user' => $branch1User] = $this->createUserWithRole('employee', $this->branch1->id, '1');
        $branch2Emp = $this->createBareEmployee($this->branch2->id, 'EMP-B2-C');

        $token = $branch1User->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/employees/{$branch2Emp->id}");

        // BranchScope filters the record out at the model binding level, yielding 404
        // (or 403 if policy fires first — either is acceptable for cross-branch denial)
        $this->assertContains($response->status(), [403, 404]);
    }
}
