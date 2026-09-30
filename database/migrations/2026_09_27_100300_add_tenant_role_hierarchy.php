<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role hierarchy for the SaaS:
 *
 *   super_admin   platform operator (no tenant)            data scope: all
 *   tenant_admin  owns one company, every branch            data scope: company
 *   branch_admin  runs their assigned branch(es)            data scope: branch
 *   hr            HR operations in their branch(es)         data scope: branch
 *   manager       their reporting tree                      data scope: team
 *   employee      self-service                              data scope: self
 *
 * Permissions say WHICH modules a role can act in; data_scope says WHOSE
 * records it sees there. Tenants can add their own roles (company_id set);
 * the built-in ones are global (company_id NULL).
 *
 * Before this migration "super_admin" meant "admin of the (only) company".
 * Those accounts become tenant_admin of that company, keeping exactly the
 * access they had; the platform operator account is created separately
 * (php artisan platform:admin).
 */
return new class extends Migration
{
    private array $systemRoles = [
        'super_admin' => ['Super Admin', 'company', 'Platform operator with access to every tenant.'],
        'tenant_admin' => ['Tenant Admin', 'company', 'Full control of the organisation, all branches.'],
        'branch_admin' => ['Branch Admin', 'branch', 'Runs their assigned branch(es).'],
        'hr' => ['HR', 'branch', 'HR operations for their branch(es).'],
        'manager' => ['Manager', 'team', 'Their reporting team only.'],
        'employee' => ['Employee', 'self', 'Self-service for their own records.'],
    ];

    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('id')->index();
            $table->string('display_name', 100)->nullable()->after('name');
            $table->string('description', 255)->nullable()->after('display_name');
            $table->string('data_scope', 10)->default('branch')->after('description');
            $table->boolean('is_system')->default(false)->after('data_scope');

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('user_type');
            $table->string('phone', 30)->nullable()->after('email');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('must_change_password')->default(false);
        });

        // Extra branches a user may act in, on top of users.branch_id (e.g.
        // an area manager covering two showrooms).
        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'branch_id']);
        });

        $now = now();

        // Company-level "super admins" to convert (see below). Resolved before
        // touching roles so tenant_admin is only created when needed -- on an
        // empty database the seeders create every role.
        $superRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');
        $userModel = 'App\\Models\\User';

        $tenantSuperIds = DB::table('users')
            ->whereNotNull('company_id')
            ->where(function ($q) use ($superRoleId, $userModel) {
                $q->where('is_super_admin', true)
                    ->orWhereIn('id', DB::table('model_has_roles')
                        ->where('role_id', $superRoleId ?? 0)
                        ->where('model_type', $userModel)
                        ->select('model_id'));
            })
            ->pluck('id');

        if ($tenantSuperIds->isNotEmpty() && ! DB::table('roles')->where('name', 'tenant_admin')->exists()) {
            DB::table('roles')->insert([
                'name' => 'tenant_admin', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach ($this->systemRoles as $name => [$display, $scope, $description]) {
            DB::table('roles')->where('name', $name)->where('guard_name', 'web')->update([
                'display_name' => $display,
                'description' => $description,
                'data_scope' => $scope,
                'is_system' => true,
                'company_id' => null,
            ]);
        }

        // Pre-existing custom roles were created by the single existing company.
        $companyIds = DB::table('companies')->pluck('id');
        if ($companyIds->count() === 1) {
            DB::table('roles')->where('is_system', false)->whereNull('company_id')
                ->update(['company_id' => $companyIds->first()]);
        }

        // Company-level "super admins" become tenant admins of their company.
        $tenantAdminRoleId = DB::table('roles')->where('name', 'tenant_admin')->value('id');

        foreach ($tenantSuperIds as $userId) {
            DB::table('model_has_roles')
                ->where('model_type', $userModel)->where('model_id', $userId)->where('role_id', $superRoleId)
                ->delete();

            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $tenantAdminRoleId, 'model_type' => $userModel, 'model_id' => $userId,
            ]);
        }

        DB::table('users')->whereIn('id', $tenantSuperIds)->update(['is_super_admin' => false]);

        Cache::forget(config('permission.cache.key', 'spatie.permission.cache'));
    }

    public function down(): void
    {
        $superRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');
        $tenantAdminRoleId = DB::table('roles')->where('name', 'tenant_admin')->value('id');

        if ($superRoleId && $tenantAdminRoleId) {
            $userIds = DB::table('model_has_roles')->where('role_id', $tenantAdminRoleId)->pluck('model_id');
            DB::table('model_has_roles')->where('role_id', $tenantAdminRoleId)->update(['role_id' => $superRoleId]);
            DB::table('users')->whereIn('id', $userIds)->update(['is_super_admin' => true]);
            DB::table('roles')->where('id', $tenantAdminRoleId)->delete();
        }

        Schema::dropIfExists('branch_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'phone', 'last_login_at', 'last_login_ip', 'password_changed_at', 'must_change_password']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn(['company_id', 'display_name', 'description', 'data_scope', 'is_system']);
        });
    }
};
