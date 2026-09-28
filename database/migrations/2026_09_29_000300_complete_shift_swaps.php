<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift swaps become a real workflow: the colleague accepts or declines,
 * an approver decides, and approval swaps the two rosters. Status becomes a
 * plain string so a request can also be cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
            $table->string('target_response', 12)->nullable()->after('status'); // accepted | declined
            $table->timestamp('responded_at')->nullable()->after('target_response');
            $table->foreignId('decided_by')->nullable()->after('responded_at')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');
            $table->string('decision_note', 500)->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['target_response', 'responded_at', 'decided_at', 'decision_note']);
        });
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->change();
        });
    }
};
