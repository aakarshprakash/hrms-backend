<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->foreignId('source_attendance_id')->nullable()->after('leave_type_id')
                ->constrained('attendances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_attendance_id');
        });
    }
};
