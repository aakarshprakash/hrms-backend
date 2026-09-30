<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee codes are a tenant's own numbering scheme: two companies can both
 * have an EMP001. Uniqueness therefore moves from global to per-company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_employee_code_unique');
            $table->unique(['company_id', 'employee_code'], 'employees_company_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_company_code_unique');
            $table->unique('employee_code');
        });
    }
};
