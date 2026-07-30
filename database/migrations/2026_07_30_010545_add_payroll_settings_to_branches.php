<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedTinyInteger('payroll_days_in_month')->default(30)->after('currency_code');
            // MySQL disallows a DB-level default on JSON columns, so this is
            // nullable instead; Branch::isWorkingDay() already treats a null
            // value as [0, 6] (the prior hardcoded Sat+Sun behavior).
            $table->json('week_off_days')->nullable()->after('payroll_days_in_month');
        });

        DB::table('branches')->whereNull('week_off_days')->update([
            'week_off_days' => json_encode([0, 6]),
        ]);
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['payroll_days_in_month', 'week_off_days']);
        });
    }
};
