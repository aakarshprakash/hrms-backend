<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The employee code as known to the biometric device/provider — this is
     * frequently a short numeric code and rarely matches our own
     * employee_code, so punches are matched against this field instead.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('biometric_emp_code', 50)->nullable()->after('employee_code');
            $table->unique(['branch_id', 'biometric_emp_code'], 'employees_branch_biometric_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_branch_biometric_code_unique');
            $table->dropColumn('biometric_emp_code');
        });
    }
};
