<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A Company is a tenant. These columns turn the single "company settings"
 * row into a proper SaaS account: identity, industry vertical (drives the
 * default templates, never hardcoded behaviour), lifecycle status, and the
 * employer statutory registrations payroll/compliance reports print.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('slug', 80)->nullable()->unique()->after('name');
            $table->string('legal_name', 191)->nullable()->after('slug');
            $table->string('industry', 40)->default('general')->after('legal_name');
            $table->string('email', 191)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website', 191)->nullable();
            $table->string('address_line1', 255)->nullable();
            $table->string('address_line2', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 2)->default('IN');
            $table->string('currency_code', 3)->default('INR');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(4);
            // PF establishment code, ESI employer code, TAN, PAN, PT
            // registration, LWF -- printed on payslips and statutory returns.
            $table->json('statutory')->nullable();
            $table->json('settings')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 255)->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamp('onboarded_at')->nullable();
        });

        // Existing companies are live and already set up.
        foreach (DB::table('companies')->orderBy('id')->get(['id', 'name']) as $company) {
            $base = Str::slug($company->name) ?: 'company';
            $slug = Str::limit($base, 70, '');
            if (DB::table('companies')->where('slug', $slug)->exists()) {
                $slug .= '-' . $company->id;
            }

            DB::table('companies')->where('id', $company->id)->update([
                'slug' => $slug,
                'onboarded_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'slug', 'legal_name', 'industry', 'email', 'phone', 'website',
                'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
                'currency_code', 'fiscal_year_start_month', 'statutory', 'settings',
                'status', 'suspended_at', 'suspension_reason', 'is_demo', 'onboarded_at',
            ]);
        });
    }
};
