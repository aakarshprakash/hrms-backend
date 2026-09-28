<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Some logins were linked to their employee record only from the employee
 * side (employees.user_id). Self-service scoping reads users.employee_id,
 * so fill it in wherever the reverse link is unambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            UPDATE users
            SET employee_id = (SELECT MIN(e.id) FROM employees e WHERE e.user_id = users.id)
            WHERE employee_id IS NULL
              AND (SELECT COUNT(*) FROM employees e WHERE e.user_id = users.id) = 1
        ');
    }

    public function down(): void
    {
        // Data backfill; nothing to undo.
    }
};
