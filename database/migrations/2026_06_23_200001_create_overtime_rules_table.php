<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->decimal('daily_threshold_hours', 5, 2)->default(8);
            $table->decimal('weekly_threshold_hours', 5, 2)->default(48);
            $table->decimal('rate_multiplier', 4, 2)->default(1.5);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_rules');
    }
};
