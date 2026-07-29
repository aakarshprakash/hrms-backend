<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            // Worked minutes below this on a given day count as half_day rather
            // than present. Defaults to half the shift's net duration at the
            // application layer when left unset.
            $table->unsignedSmallInteger('half_day_threshold_minutes')->nullable()->after('grace_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('half_day_threshold_minutes');
        });
    }
};
