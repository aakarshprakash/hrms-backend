<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            // Make roster_id nullable so swaps can exist without a formal roster entry
            $table->foreignId('roster_id')->nullable()->change();

            // Add date columns so the frontend can submit dates instead of a roster_id
            $table->date('my_date')->nullable()->after('roster_id');
            $table->date('their_date')->nullable()->after('my_date');
        });
    }

    public function down(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->dropColumn(['my_date', 'their_date']);
            $table->foreignId('roster_id')->nullable(false)->change();
        });
    }
};
