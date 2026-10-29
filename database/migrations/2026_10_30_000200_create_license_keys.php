<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Licence keys: the platform collects payment offline (bank transfer,
 * cheque, UPI to the account) and issues a key; the customer redeems it to
 * activate or extend their plan. Plus the autopay switch (wired to a
 * payment mandate later) and a small store for platform-wide settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('billing_cycle', 20)->default('yearly');
            $table->unsignedSmallInteger('months');
            // Locked to one organisation, or redeemable by any.
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('status', 20)->default('available'); // available | redeemed | revoked
            $table->decimal('amount', 12, 2)->default(0);        // collected, GST included
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_reference', 191)->nullable();
            $table->date('paid_on')->nullable();
            $table->date('valid_until')->nullable();              // redeem by
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('redeemed_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'company_id']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('billing_mode', 20)->default('online')->after('billing_cycle'); // online | license
            $table->foreignId('license_key_id')->nullable()->after('billing_mode')->constrained('license_keys')->nullOnDelete();
            $table->boolean('autopay')->default(false)->after('cancel_at_period_end');
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('license_key_id');
            $table->dropColumn(['billing_mode', 'autopay']);
        });
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('license_keys');
    }
};
