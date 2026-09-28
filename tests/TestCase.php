<?php

namespace Tests;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->reset();

        // Every DB-backed test gets the real built-in roles with their real
        // default permissions -- the same catalog production runs with.
        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            $this->seed(PermissionCatalogSeeder::class);
        }
    }

    protected function makeCompany(string $name = 'Test Corp'): Company
    {
        return Company::create(['name' => $name, 'slug' => Str::slug($name) . '-' . Str::random(4), 'timezone' => 'UTC']);
    }

    protected function makeBranch(Company $company, string $name = 'HQ'): Branch
    {
        return Branch::create([
            'company_id' => $company->id,
            'name' => $name,
            'city' => 'Mumbai',
            'country' => 'India',
            'timezone' => 'UTC',
            'currency_code' => 'INR',
            'week_off_days' => [0, 6],
        ]);
    }

    /**
     * A login with $role in $branch's organisation, optionally with a linked
     * employee record.
     *
     * @return array{user: User, employee: ?Employee}
     */
    protected function makeUser(string $role, Branch $branch, bool $withEmployee = true, array $employeeAttributes = []): array
    {
        $email = $role . '.' . Str::lower(Str::random(6)) . '@test.com';

        $user = User::create([
            'name' => ucfirst($role) . ' User',
            'email' => $email,
            'password' => bcrypt('password'),
            'branch_id' => $branch->id,
            'user_type' => $withEmployee ? 'employee' : 'system',
        ]);
        $user->assignRole($role);

        $employee = null;
        if ($withEmployee) {
            $employee = Employee::withoutGlobalScopes()->create(array_merge([
                'branch_id' => $branch->id,
                'employee_code' => strtoupper($role) . Str::upper(Str::random(5)),
                'first_name' => ucfirst($role),
                'last_name' => 'User',
                'email' => $email,
                'date_of_joining' => now()->subYear()->toDateString(),
                'status' => 'active',
                'user_id' => $user->id,
            ], $employeeAttributes));
            $user->update(['employee_id' => $employee->id]);
        }

        return ['user' => $user->fresh(), 'employee' => $employee];
    }
}
