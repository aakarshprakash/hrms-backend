<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team coverage: the minimum number of people a team needs at work
 * (e.g. Sales in Kochi: at least 5 by 10:30). HR sees today's and the
 * coming days' coverage, leave approvers are warned, and an alert goes out
 * when a team is short.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coverage_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 191);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->unsignedSmallInteger('min_present');
            $table->time('check_at')->default('10:30:00');      // when today's alert is checked (branch time)
            $table->json('notify_user_ids')->nullable();        // on top of HR / attendance managers
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('coverage_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('coverage_rule_id')->constrained('coverage_rules')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('present');
            $table->unsignedSmallInteger('required');
            $table->unsignedSmallInteger('on_leave')->default(0);
            $table->unsignedSmallInteger('notified')->default(0);
            $table->timestamps();
            $table->unique(['coverage_rule_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_alerts');
        Schema::dropIfExists('coverage_rules');
    }
};
