<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-serve onboarding and online payments.
 * - signup_verifications: the email OTP a new admin confirms before a
 *   workspace is created, and the short-lived token that proves it;
 * - payments: every gateway payment attempt (Razorpay Checkout orders),
 *   so the platform can see what was paid, failed or is still open;
 * - subscriptions.billing_cycle now also takes "half_yearly".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signup_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191)->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('gateway', 20)->default('razorpay');
            $table->string('gateway_order_id', 64)->nullable()->unique();
            $table->string('gateway_payment_id', 64)->nullable()->index();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('status', 12)->default('created');        // created | captured | failed
            $table->string('method', 30)->nullable();                  // card | upi | netbanking | wallet ...
            $table->string('error', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('billing_cycle', 20)->default('monthly')->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('signup_verifications');
    }
};
