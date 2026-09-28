<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscriptions and billing.
 * - plans: the platform's catalogue (global, not tenant data);
 * - subscriptions: a tenant's plan, status and billing period;
 * - invoices: what was billed for a period, with GST, and its payment.
 * Existing organisations are put on the "founding customer" plan by
 * PlanSeeder -- nothing about how they work today changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->decimal('base_price', 10, 2)->default(0);          // per month, INR, before GST
            $table->decimal('per_employee_price', 10, 2)->default(0);  // per active employee beyond the included
            $table->unsignedInteger('included_employees')->default(0);
            $table->json('features');
            $table->json('limits')->nullable();                        // {branches: n|null, employees: n|null}
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('status', 20)->default('trialing');         // trialing | active | past_due | cancelled | expired
            $table->string('billing_cycle', 10)->default('monthly');   // monthly | yearly
            $table->timestamp('trial_ends_at')->nullable();
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable();
            // Chosen by the tenant (during or after a trial): billing starts at trial end.
            $table->timestamp('plan_confirmed_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->decimal('custom_monthly_price', 10, 2)->nullable(); // negotiated price, overrides the plan
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('number', 40)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->json('lines');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('cgst', 12, 2)->default(0);
            $table->decimal('sgst', 12, 2)->default(0);
            $table->decimal('igst', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('status', 12)->default('issued');           // issued | paid | void
            $table->date('issued_on');
            $table->date('due_on');
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 30)->nullable();          // razorpay | bank_transfer | cheque | other
            $table->string('payment_reference')->nullable();
            $table->string('gateway_link_id')->nullable();
            $table->string('gateway_link_url')->nullable();
            $table->json('buyer')->nullable();                         // name, GSTIN, address, state at issue time
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('gstin', 15)->nullable()->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('gstin');
        });
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
