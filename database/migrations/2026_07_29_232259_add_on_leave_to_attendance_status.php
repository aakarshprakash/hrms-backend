<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE attendances MODIFY status ENUM('present','absent','half_day','late','on_leave') NOT NULL DEFAULT 'present'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE attendances MODIFY status ENUM('present','absent','half_day','late') NOT NULL DEFAULT 'present'");
    }
};
