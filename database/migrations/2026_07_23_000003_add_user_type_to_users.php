<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separates the login base into two populations that must never mix:
     *  - 'system'   → solution operators (super admin, branch admins, …) managed
     *                 in User Management; not part of the employee directory.
     *  - 'employee' → self-service logins that belong to an Employee record,
     *                 created from the employee profile.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('user_type', 20)->default('system')->after('is_super_admin');
        });

        // Backfill: operator roles are system accounts; anything tied to an
        // employee record is an employee login.
        DB::statement("
            UPDATE users u
            LEFT JOIN model_has_roles mhr ON mhr.model_id = u.id AND mhr.model_type = 'App\\\\Models\\\\User'
            LEFT JOIN roles r ON r.id = mhr.role_id
            SET u.user_type = CASE
                WHEN u.is_super_admin = 1 OR r.name IN ('super_admin', 'branch_admin') THEN 'system'
                WHEN u.employee_id IS NOT NULL THEN 'employee'
                ELSE 'system'
            END
        ");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('user_type');
        });
    }
};
