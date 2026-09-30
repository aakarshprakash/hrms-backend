<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema for the full payroll engine.
 *
 * salary_components  how each component behaves: PF / ESI wage, taxable,
 *                    prorated by attendance, variable, base of a percentage.
 * payroll_runs       lifecycle draft -> processed -> finalized -> paid.
 *                    "completed" runs from before become "finalized" (their
 *                    payslips were already visible to employees).
 * payslips           attendance and statutory figures as columns, so
 *                    compliance reports (PF ECR, ESI, PT, TDS) are queries,
 *                    not JSON parsing; publication flag for self-service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_components', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->after('name');
            $table->string('percentage_of', 10)->default('basic')->after('calculation_type'); // basic | gross
            $table->decimal('default_value', 12, 2)->nullable()->after('percentage_of');
            $table->boolean('is_basic')->default(false);
            $table->boolean('pf_applicable')->default(false);
            $table->boolean('esi_applicable')->default(true);
            $table->boolean('taxable')->default(true);
            $table->boolean('prorate')->default(true);
            $table->boolean('is_variable')->default(false);
            $table->unsignedSmallInteger('display_order')->default(100);
            $table->boolean('is_active')->default(true);
        });

        // Infer sensible behaviour for existing components from their names.
        DB::table('salary_components')->whereRaw("LOWER(name) LIKE 'basic%'")->update(['is_basic' => true, 'pf_applicable' => true, 'display_order' => 1]);
        DB::table('salary_components')->whereRaw("LOWER(name) LIKE 'dearness%' OR UPPER(name) = 'DA'")->update(['pf_applicable' => true, 'display_order' => 2]);
        DB::table('salary_components')->where('type', 'deduction')->update(['prorate' => false, 'esi_applicable' => false, 'taxable' => false]);
        DB::table('salary_components')->whereRaw("LOWER(name) LIKE '%incentive%' OR LOWER(name) LIKE '%bonus%'")->update(['prorate' => false, 'is_variable' => true]);

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->string('status_v2', 20)->default('draft')->after('status');
            $table->date('period_start')->nullable()->after('year');
            $table->date('period_end')->nullable()->after('period_start');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->json('totals')->nullable();
            $table->text('notes')->nullable();
        });

        DB::table('payroll_runs')->update([
            // A run left "processing" means a crashed job: back to draft so it can be re-run.
            'status_v2' => DB::raw("CASE status WHEN 'completed' THEN 'finalized' ELSE 'draft' END"),
            'period_start' => DB::raw("STR_TO_DATE(CONCAT(year, '-', month, '-01'), '%Y-%c-%d')"),
            'period_end' => DB::raw("LAST_DAY(STR_TO_DATE(CONCAT(year, '-', month, '-01'), '%Y-%c-%d'))"),
            'processed_at' => DB::raw('run_at'),
            'finalized_at' => DB::raw("CASE status WHEN 'completed' THEN run_at ELSE NULL END"),
        ]);

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('status');
        });
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->renameColumn('status_v2', 'status');
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('days_in_period', 5, 2)->nullable()->after('net_pay');
            $table->decimal('payable_days', 6, 2)->nullable()->after('days_in_period');
            $table->decimal('lop_days', 6, 2)->default(0)->after('payable_days');
            $table->decimal('taxable_gross', 12, 2)->default(0);
            $table->decimal('pf_wage', 12, 2)->default(0);
            $table->decimal('pf_employee', 12, 2)->default(0);
            $table->decimal('pf_employer', 12, 2)->default(0);
            $table->decimal('eps_employer', 12, 2)->default(0);
            $table->decimal('esi_wage', 12, 2)->default(0);
            $table->decimal('esi_employee', 12, 2)->default(0);
            $table->decimal('esi_employer', 12, 2)->default(0);
            $table->decimal('professional_tax', 12, 2)->default(0);
            $table->decimal('tds', 12, 2)->default(0);
            $table->decimal('employer_cost', 12, 2)->default(0);
            $table->timestamp('published_at')->nullable();
        });

        // Payslips of runs that were already complete stay visible to employees.
        DB::statement("
            UPDATE payslips SET published_at = created_at
            WHERE payroll_run_id IN (SELECT id FROM payroll_runs WHERE status = 'finalized')
        ");

        // Fill the statutory columns of existing payslips from their breakdown where possible.
        foreach (DB::table('payslips')->whereNotNull('breakdown_json')->get(['id', 'breakdown_json']) as $slip) {
            $b = json_decode($slip->breakdown_json, true) ?: [];
            $stat = collect($b['statutory_deductions'] ?? [])->keyBy('rule_type');
            DB::table('payslips')->where('id', $slip->id)->update([
                'lop_days' => (float) ($b['lop']['days'] ?? 0),
                'pf_employee' => (float) ($stat['PF']['amount'] ?? 0),
                'esi_employee' => (float) ($stat['ESI']['amount'] ?? 0),
                'tds' => (float) ($stat['TAX']['amount'] ?? 0),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['days_in_period', 'payable_days', 'lop_days', 'taxable_gross', 'pf_wage', 'pf_employee', 'pf_employer',
                'eps_employer', 'esi_wage', 'esi_employee', 'esi_employer', 'professional_tax', 'tds', 'employer_cost', 'published_at']);
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->enum('status_v1', ['draft', 'processing', 'completed'])->default('draft')->after('year');
        });
        DB::table('payroll_runs')->update([
            'status_v1' => DB::raw("CASE WHEN status IN ('finalized','paid','processed') THEN 'completed' ELSE 'draft' END"),
        ]);
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn(['status', 'period_start', 'period_end', 'processed_at', 'finalized_at', 'paid_at', 'payment_reference', 'totals', 'notes']);
        });
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->renameColumn('status_v1', 'status');
        });

        Schema::table('salary_components', function (Blueprint $table) {
            $table->dropColumn(['code', 'percentage_of', 'default_value', 'is_basic', 'pf_applicable', 'esi_applicable',
                'taxable', 'prorate', 'is_variable', 'display_order', 'is_active']);
        });
    }
};
