<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records the shift an attendance day was evaluated against, plus the
     * derived lateness/early-departure/worked-time figures — so status
     * ("late", "half_day", ...) is computed once and reports don't need to
     * re-derive it from scratch against a shift assignment that may have
     * since changed.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('employee_id')->constrained('shifts')->nullOnDelete();
            $table->unsignedInteger('late_by_minutes')->nullable()->after('status');
            $table->unsignedInteger('early_by_minutes')->nullable()->after('late_by_minutes');
            $table->unsignedInteger('worked_minutes')->nullable()->after('early_by_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
            $table->dropColumn(['late_by_minutes', 'early_by_minutes', 'worked_minutes']);
        });
    }
};
