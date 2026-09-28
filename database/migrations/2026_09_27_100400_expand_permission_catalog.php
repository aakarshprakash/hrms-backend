<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permissions introduced with the SaaS release, granted once to the
 * built-in roles that should have them. Doing this here (rather than in the
 * deploy-time seeder) means a tenant's later edits to a role are never
 * silently reverted by the next deploy.
 *
 * Also tags audit-log entries with their tenant.
 */
return new class extends Migration
{
    private array $grants = [
        // HR could always run payroll in the single-tenant app (checked by
        // role name); make that an explicit permission so it survives.
        'payroll.manage' => ['hr'],
        'employees.sensitive' => ['branch_admin', 'hr'],
        'leaves.manage' => ['branch_admin', 'hr'],
        'payroll.finalize' => ['branch_admin'],
        'reports.view' => ['branch_admin', 'hr'],
        'roles.manage' => [],
        'audit.view' => [],
        'notifications.manage' => ['branch_admin'],
        'billing.manage' => [],
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->grants as $permission => $roles) {
            if (! DB::table('permissions')->where('name', $permission)->where('guard_name', 'web')->exists()) {
                DB::table('permissions')->insert([
                    'name' => $permission, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $permissionId = DB::table('permissions')->where('name', $permission)->where('guard_name', 'web')->value('id');

            foreach (DB::table('roles')->whereIn('name', $roles)->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId, 'role_id' => $roleId,
                ]);
            }
        }

        Cache::forget(config('permission.cache.key', 'spatie.permission.cache'));

        $logTable = config('activitylog.table_name', 'activity_log');
        if (Schema::hasTable($logTable) && ! Schema::hasColumn($logTable, 'company_id')) {
            Schema::table($logTable, function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        $logTable = config('activitylog.table_name', 'activity_log');
        if (Schema::hasColumn($logTable, 'company_id')) {
            Schema::table($logTable, function (Blueprint $table) {
                $table->dropIndex(['company_id']);
                $table->dropColumn('company_id');
            });
        }

        // payroll.manage pre-dates this migration: only revoke the HR grant.
        $hrRoleId = DB::table('roles')->where('name', 'hr')->value('id');
        $payrollManageId = DB::table('permissions')->where('name', 'payroll.manage')->value('id');
        DB::table('role_has_permissions')->where('role_id', $hrRoleId)->where('permission_id', $payrollManageId)->delete();

        $ids = DB::table('permissions')->whereIn('name', array_diff(array_keys($this->grants), ['payroll.manage']))->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Cache::forget(config('permission.cache.key', 'spatie.permission.cache'));
    }
};
