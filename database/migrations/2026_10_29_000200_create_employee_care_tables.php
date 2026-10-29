<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee care.
 * - care_tickets / care_ticket_messages: the HR helpdesk -- requests and
 *   grievances (optionally anonymous to the people handling them), with
 *   priority-based response targets, assignment and internal notes;
 * - announcements / announcement_reads: company or branch notices, pinned
 *   or not, with read receipts and acknowledgement where required.
 * Two permissions, granted once to the built-in HR and branch-admin roles.
 */
return new class extends Migration
{
    private array $grants = [
        'helpdesk.manage' => ['branch_admin', 'hr'],
        'announcements.manage' => ['branch_admin', 'hr'],
    ];

    public function up(): void
    {
        Schema::create('care_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('number', 20);
            $table->string('type', 12)->default('request');              // request | grievance
            $table->string('category', 30);
            $table->string('subject', 191);
            $table->text('description');
            $table->string('priority', 10)->default('normal');           // low | normal | high | urgent
            $table->string('status', 12)->default('open');               // open | in_progress | waiting | resolved | closed
            $table->boolean('anonymous')->default(false);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();           // 1..5, by the employee once resolved
            $table->string('rating_comment', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('care_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('care_ticket_id')->constrained('care_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('internal')->default(false);                 // HR-only note
            $table->string('event', 30)->nullable();                     // status_changed, assigned... (system lines)
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete(); // null = everyone
            $table->string('title', 191);
            $table->text('body');
            $table->string('category', 20)->default('general');          // general | policy | event | holiday | urgent
            $table->boolean('pinned')->default(false);
            $table->boolean('requires_ack')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'published_at']);
        });

        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamp('acknowledged_at')->nullable();

            $table->unique(['announcement_id', 'user_id']);
        });

        $now = now();
        foreach ($this->grants as $permission => $roles) {
            if (! DB::table('permissions')->where('name', $permission)->where('guard_name', 'web')->exists()) {
                DB::table('permissions')->insert(['name' => $permission, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
            }
            $permissionId = DB::table('permissions')->where('name', $permission)->where('guard_name', 'web')->value('id');
            foreach (DB::table('roles')->whereIn('name', $roles)->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
        Cache::forget(config('permission.cache.key', 'spatie.permission.cache'));
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('care_ticket_messages');
        Schema::dropIfExists('care_tickets');

        $ids = DB::table('permissions')->whereIn('name', array_keys($this->grants))->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
