<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One biometric device-provider config per branch — each branch may be a
     * different physical location/institution registered separately with the
     * provider (its own ins_code and Bearer token).
     */
    public function up(): void
    {
        Schema::create('biometric_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('api_url')->default('https://bio.kochi.digital/api/fetch-punches');
            $table->string('ins_code');
            $table->text('api_token'); // encrypted at rest via model cast
            $table->boolean('enabled')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status', 20)->nullable(); // success | failed
            $table->text('last_sync_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_configs');
    }
};
