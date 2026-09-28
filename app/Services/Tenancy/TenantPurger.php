<?php

namespace App\Services\Tenancy;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Permanently deletes a *demo* tenant and everything it owns, so the demo
 * can be rebuilt. Refuses any company not flagged is_demo -- real tenants
 * are suspended or cancelled, never purged by this.
 */
class TenantPurger
{
    /** Children before parents. */
    private const TABLES = [
        'raw_punches', 'approval_actions', 'attendance_regularizations', 'leave_transactions', 'leaves', 'leave_balances',
        'overtime_requests', 'payroll_run_adjustments', 'payslips', 'payroll_runs', 'salary_structures',
        'issued_certificates', 'certificate_requests', 'certificate_template_versions', 'certificate_templates',
        'shift_swap_requests', 'shift_rosters', 'employee_shifts', 'attendance_daily', 'employee_documents',
        'biometric_sync_logs', 'biometric_configs', 'approval_flows', 'overtime_rules', 'statutory_rules',
        'salary_components', 'leave_types', 'holidays', 'branch_user',
    ];

    public function purge(Company $company): void
    {
        if (! $company->is_demo) {
            throw new RuntimeException("Refusing to purge {$company->name}: it is not a demo tenant.");
        }

        DB::transaction(function () use ($company) {
            $id = $company->id;
            $userIds = DB::table('users')->where('company_id', $id)->pluck('id');

            foreach (self::TABLES as $table) {
                DB::table($table)->where('company_id', $id)->delete();
            }

            DB::table('users')->whereIn('id', $userIds)->update(['employee_id' => null]);
            DB::table('employees')->where('company_id', $id)->update(['reporting_manager_id' => null, 'user_id' => null]);
            if (Schema::hasTable('notifications')) {
                DB::table('notifications')->where('notifiable_type', \App\Models\User::class)->whereIn('notifiable_id', $userIds)->delete();
            }
            DB::table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)->whereIn('tokenable_id', $userIds)->delete();
            DB::table('model_has_roles')->where('model_type', \App\Models\User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('media')->where('model_type', \App\Models\Employee::class)
                ->whereIn('model_id', DB::table('employees')->where('company_id', $id)->select('id'))->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
            DB::table('employees')->where('company_id', $id)->delete();
            DB::table('designations')->where('company_id', $id)->delete();
            DB::table('departments')->where('company_id', $id)->update(['parent_department_id' => null]);
            DB::table('departments')->where('company_id', $id)->delete();
            DB::table('branches')->where('company_id', $id)->update(['default_shift_id' => null]);
            DB::table('shifts')->where('company_id', $id)->delete();
            DB::table('roles')->where('company_id', $id)->delete();
            DB::table(config('activitylog.table_name', 'activity_log'))->where('company_id', $id)->delete();

            foreach (['subscriptions', 'invoices', 'notification_logs', 'notification_settings'] as $optional) {
                if (Schema::hasTable($optional) && Schema::hasColumn($optional, 'company_id')) {
                    DB::table($optional)->where('company_id', $id)->delete();
                }
            }

            DB::table('branches')->where('company_id', $id)->delete();
            DB::table('companies')->where('id', $id)->delete();
        });

        Storage::deleteDirectory("tenants/{$company->id}");
    }
}
