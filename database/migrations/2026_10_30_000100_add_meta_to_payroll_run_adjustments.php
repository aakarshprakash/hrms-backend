<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra figures an adjustment carries -- arrears keep the PF and EPS wages
 * they add, already limited by the ceiling of the months they relate to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_adjustments', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_adjustments', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
