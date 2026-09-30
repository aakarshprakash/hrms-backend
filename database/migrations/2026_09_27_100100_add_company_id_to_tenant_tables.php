<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Row-level multi-tenancy: stamp every tenant-owned row with company_id.
 *
 * Shared-database (not schema-per-tenant) because the target is shared
 * hosting, where creating databases on signup isn't available, and because
 * the expected scale (hundreds of tenants, low thousands of employees each)
 * is comfortably served by indexed company_id filtering.
 *
 * Backfill follows each row's own parent chain (branch -> company,
 * employee -> company, ...). Before this migration the system was
 * single-tenant, so when exactly one company exists every row that still
 * can't be resolved belongs to it -- that is the "migrate the current
 * company as tenant #1" step. A column is only made NOT NULL once it is
 * fully backfilled; anything left NULL is simply invisible to every tenant
 * (the scope filters on equality) rather than blocking the deploy.
 *
 * The list of tables is frozen here on purpose (not read from config) so
 * this migration keeps meaning the same thing as the schema evolves.
 */
return new class extends Migration
{
    /** table => [parent column, parent table], resolved in this order */
    private array $parents = [
        'departments' => ['branch_id', 'branches'],
        'designations' => ['branch_id', 'branches'],
        'employees' => ['branch_id', 'branches'],
        'holidays' => ['branch_id', 'branches'],
        'shifts' => ['branch_id', 'branches'],
        'shift_rosters' => ['branch_id', 'branches'],
        'leave_types' => ['branch_id', 'branches'],
        'approval_flows' => ['branch_id', 'branches'],
        'overtime_rules' => ['branch_id', 'branches'],
        'salary_components' => ['branch_id', 'branches'],
        'statutory_rules' => ['branch_id', 'branches'],
        'payroll_runs' => ['branch_id', 'branches'],
        'certificate_templates' => ['branch_id', 'branches'],
        'biometric_configs' => ['branch_id', 'branches'],
        'biometric_sync_logs' => ['branch_id', 'branches'],
        'employee_documents' => ['employee_id', 'employees'],
        'employee_shifts' => ['employee_id', 'employees'],
        'attendances' => ['employee_id', 'employees'],
        'attendance_regularizations' => ['employee_id', 'employees'],
        'leave_balances' => ['employee_id', 'employees'],
        'leaves' => ['employee_id', 'employees'],
        'overtime_requests' => ['employee_id', 'employees'],
        'salary_structures' => ['employee_id', 'employees'],
        'payslips' => ['employee_id', 'employees'],
        'payroll_run_adjustments' => ['employee_id', 'employees'],
        'certificate_requests' => ['employee_id', 'employees'],
        'issued_certificates' => ['employee_id', 'employees'],
        'shift_swap_requests' => ['requester_id', 'employees'],
        'approval_actions' => ['flow_id', 'approval_flows'],
        'certificate_template_versions' => ['template_id', 'certificate_templates'],
    ];

    /** extra composite indexes for the hottest tenant-filtered lookups */
    private array $compositeIndexes = [
        'employees' => ['company_id', 'status'],
        'attendances' => ['company_id', 'date'],
        'leaves' => ['company_id', 'status'],
    ];

    public function up(): void
    {
        $tables = array_merge(array_keys($this->parents), ['users']);

        foreach ($tables as $table) {
            if (Schema::hasColumn($table, 'company_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->nullable()->after('id')->index();
            });
        }

        // Parent-chain backfill (portable correlated subqueries).
        foreach ($this->parents as $table => [$column, $parentTable]) {
            DB::statement("
                UPDATE {$table}
                SET company_id = (SELECT p.company_id FROM {$parentTable} p WHERE p.id = {$table}.{$column})
                WHERE company_id IS NULL AND {$column} IS NOT NULL
            ");
        }

        // Users: via their branch, else via their employee record.
        DB::statement('
            UPDATE users
            SET company_id = (SELECT b.company_id FROM branches b WHERE b.id = users.branch_id)
            WHERE company_id IS NULL AND branch_id IS NOT NULL
        ');
        DB::statement('
            UPDATE users
            SET company_id = (SELECT e.company_id FROM employees e WHERE e.id = users.employee_id)
            WHERE company_id IS NULL AND employee_id IS NOT NULL
        ');

        // Single-tenant legacy data: everything left belongs to the one company.
        $companyIds = DB::table('companies')->pluck('id');
        if ($companyIds->count() === 1) {
            foreach ($tables as $table) {
                DB::table($table)->whereNull('company_id')->update(['company_id' => $companyIds->first()]);
            }
        }

        foreach (array_keys($this->parents) as $table) {
            $orphans = DB::table($table)->whereNull('company_id')->count();

            Schema::table($table, function (Blueprint $t) use ($orphans) {
                if ($orphans === 0) {
                    $t->unsignedBigInteger('company_id')->nullable(false)->change();
                }
                $t->foreign('company_id')->references('id')->on('companies');
            });

            if ($orphans > 0) {
                Log::warning("Tenancy migration: {$orphans} row(s) in {$table} could not be assigned a company and are hidden from all tenants.");
            }
        }

        // Users stay nullable: platform admins belong to no tenant.
        Schema::table('users', function (Blueprint $t) {
            $t->foreign('company_id')->references('id')->on('companies');
        });

        foreach ($this->compositeIndexes as $table => $columns) {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns));
        }
    }

    public function down(): void
    {
        foreach ($this->compositeIndexes as $table => $columns) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($columns));
        }

        foreach (array_merge(array_keys($this->parents), ['users']) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['company_id']);
                $t->dropIndex(['company_id']);
                $t->dropColumn('company_id');
            });
        }
    }
};
