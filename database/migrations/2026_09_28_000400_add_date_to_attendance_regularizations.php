<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A regularization is for a specific day. The day used to be dropped (only
 * the times were kept, and bare "HH:MM" times were read as *today*), so
 * requests for past days pointed at the wrong date. Store it explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_regularizations', function (Blueprint $table) {
            $table->date('date')->nullable()->after('employee_id');
            $table->index(['employee_id', 'date']);
        });

        // Best available evidence for existing rows: the linked attendance day.
        DB::statement('
            UPDATE attendance_regularizations
            SET date = (SELECT a.date FROM attendance_daily a WHERE a.id = attendance_regularizations.attendance_id)
            WHERE date IS NULL AND attendance_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::table('attendance_regularizations', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'date']);
            $table->dropColumn('date');
        });
    }
};
