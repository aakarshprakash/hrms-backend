<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An organisation's own SMTP server for its emails (optional; the platform's is used otherwise). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->boolean('smtp_enabled')->default(false)->after('email_enabled');
            $table->text('smtp_credentials')->nullable()->after('smtp_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn(['smtp_enabled', 'smtp_credentials']);
        });
    }
};
