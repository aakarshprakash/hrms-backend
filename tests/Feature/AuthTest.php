<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'employee', 'guard_name' => 'web']);

        // Create company + branch
        $company = Company::create(['name' => 'Test Corp', 'timezone' => 'UTC']);
        Branch::create([
            'company_id' => $company->id,
            'name' => 'HQ',
            'city' => 'Mumbai',
            'country' => 'India',
            'timezone' => 'UTC',
            'currency_code' => 'INR',
        ]);
    }

    private function createSuperAdmin(): array
    {
        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_super_admin' => true,
        ]);
        $user->assignRole('super_admin');

        $employee = Employee::create([
            'branch_id' => 1,
            'employee_code' => 'EMP001',
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'admin@test.com',
            'date_of_joining' => now()->toDateString(),
        ]);

        $user->update(['employee_id' => $employee->id]);
        $employee->update(['user_id' => $user->id]);

        return ['user' => $user, 'employee' => $employee];
    }

    public function test_login_with_valid_credentials_returns_token(): void
    {
        $this->createSuperAdmin();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'user',
                    'token',
                    'branches',
                ],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_with_invalid_credentials_returns_422(): void
    {
        $this->createSuperAdmin();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_logout_revokes_token(): void
    {
        ['user' => $user] = $this->createSuperAdmin();

        // Issue a real DB token
        $tokenModel = $user->createToken('test-logout-token');
        $plainTextToken = $tokenModel->plainTextToken;

        // Confirm 1 token exists
        $this->assertEquals(1, $user->tokens()->count());

        $logoutResponse = $this->withToken($plainTextToken)
            ->postJson('/api/auth/logout');

        $logoutResponse->assertStatus(200);

        // Token should have been deleted from DB
        $this->assertEquals(0, $user->fresh()->tokens()->count());
    }

    public function test_me_returns_authenticated_user(): void
    {
        ['user' => $user] = $this->createSuperAdmin();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'user',
                    'roles',
                ],
            ])
            ->assertJsonPath('data.user.email', 'admin@test.com');
    }
}
