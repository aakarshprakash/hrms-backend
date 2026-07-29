<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Plain string so manual entries and the future device-API sync
        // ('manual', 'api') don't need another schema change.
        DB::statement("ALTER TABLE attendances MODIFY source VARCHAR(20) NOT NULL DEFAULT 'web'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE attendances MODIFY source ENUM('web','mobile','kiosk') NOT NULL DEFAULT 'web'");
    }
};
