<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Professional tax (state slabs) and labour welfare fund join PF, ESI and
 * income tax as configurable statutory rules. One rule of each type per
 * branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE statutory_rules MODIFY rule_type VARCHAR(10) NOT NULL");
    }

    public function down(): void
    {
        DB::table('statutory_rules')->whereNotIn('rule_type', ['PF', 'ESI', 'TAX'])->delete();
        DB::statement("ALTER TABLE statutory_rules MODIFY rule_type ENUM('PF','ESI','TAX') NOT NULL");
    }
};
