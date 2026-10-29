<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll extras.
 * - employee_loans: loans and salary advances, recovered in monthly
 *   instalments through payroll;
 * - loan_recoveries: the ledger -- one row per instalment a payroll run
 *   deducted (rewritten whenever the run is reprocessed), or a manual
 *   repayment / foreclosure (no run);
 * - tax_declarations: an employee's income-tax regime and investment
 *   declarations for a financial year, reviewed by HR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type', 10)->default('loan');                 // loan | advance
            $table->decimal('principal', 12, 2);
            $table->decimal('interest_rate', 5, 2)->default(0);          // % per year, flat
            $table->decimal('total_payable', 12, 2);                     // principal + interest
            $table->unsignedSmallInteger('tenure_months');
            $table->decimal('emi', 12, 2);
            $table->unsignedTinyInteger('start_month');                  // first payroll month recovered
            $table->unsignedSmallInteger('start_year');
            $table->date('disbursed_on')->nullable();
            $table->string('status', 12)->default('active');             // active | cancelled
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'status']);
        });

        Schema::create('loan_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_loan_id')->constrained('employee_loans')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('recovered_on');
            $table->string('method', 20)->default('payroll');            // payroll | cash | bank_transfer | waiver
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_loan_id', 'payroll_run_id']);
        });

        Schema::create('tax_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('financial_year', 9);                         // 2026-27
            $table->string('regime', 3)->default('new');                 // new | old
            $table->json('items')->nullable();                            // [{section, amount, note}]
            $table->decimal('declared_total', 12, 2)->default(0);        // after section caps
            $table->decimal('approved_total', 12, 2)->nullable();
            $table->string('status', 12)->default('draft');              // draft | submitted | approved | rejected
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'financial_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_declarations');
        Schema::dropIfExists('loan_recoveries');
        Schema::dropIfExists('employee_loans');
    }
};
