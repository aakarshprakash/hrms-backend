<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications:
 * - notifications: Laravel's in-app (database) notifications, per user.
 * - notification_settings: per tenant -- which channels are on (email, SMS,
 *   WhatsApp), provider credentials (encrypted), and per-event choices.
 * - notification_logs: the outbox and delivery log for email / SMS /
 *   WhatsApp: queued rows are sent right after the request and retried by
 *   the scheduler, so nothing depends on a queue worker being up.
 * - users.notification_preferences: a person's own opt-outs by channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            });
        }

        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->boolean('email_enabled')->default(false);
            $table->boolean('sms_enabled')->default(false);
            $table->string('sms_provider', 30)->nullable();       // msg91 | twilio | log
            $table->text('sms_credentials')->nullable();          // encrypted JSON
            $table->boolean('whatsapp_enabled')->default(false);
            $table->string('whatsapp_provider', 30)->nullable();  // meta | twilio | log
            $table->text('whatsapp_credentials')->nullable();     // encrypted JSON
            $table->json('events')->nullable();                   // event => channels + template ids
            $table->timestamps();
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 60);
            $table->string('channel', 12);                        // email | sms | whatsapp
            $table->string('recipient', 191);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('template')->nullable();
            $table->json('variables')->nullable();
            $table->string('status', 12)->default('queued');      // queued | sent | failed | skipped
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('provider', 30)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('notifications');
    }
};
