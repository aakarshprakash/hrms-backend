<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave management on a ledger.
 *
 * - leave_transactions: every credit and debit to a balance (opening,
 *   accrual, carry forward, adjustment, availed, reversal, lapse). A
 *   balance row is the running total of its transactions, so "why is my
 *   balance 7.5?" always has an answer, and accrual can never double-credit
 *   (one accrual per balance per period).
 * - leave_balances: the running total split into its parts.
 * - leaves: half-day session, the leave year it draws from, attachment,
 *   who applied it, and cancellation details.
 * - approval_actions: which approver a step was waiting for, captured at
 *   submission so later changes to the flow don't re-route a request.
 *
 * Existing balances are carried over as they are: their allocation becomes
 * the opening entry and they are treated as fully accrued for their year,
 * so nobody's balance changes on deploy. Accrual by policy starts with the
 * next leave year (and for anyone who has no balance yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('prorate_on_joining')->default(true)->after('accrual');
            $table->unsignedSmallInteger('min_notice_days')->default(0)->after('min_service_days');
        });

        Schema::table('leave_balances', function (Blueprint $table) {
            $table->decimal('opening', 6, 2)->default(0)->after('year');
            $table->decimal('accrued', 6, 2)->default(0)->after('opening');
            $table->decimal('adjusted', 6, 2)->default(0)->after('accrued');
            $table->decimal('carried_forward', 6, 2)->default(0)->after('used');
            $table->decimal('lapsed', 6, 2)->default(0)->after('carried_forward');
            $table->date('accrued_through')->nullable()->after('balance');
            $table->timestamp('closed_at')->nullable()->after('accrued_through');
        });

        Schema::table('leaves', function (Blueprint $table) {
            $table->string('half_day_session', 12)->nullable()->after('days'); // first_half | second_half
            $table->unsignedSmallInteger('leave_year')->nullable()->after('half_day_session');
            $table->string('attachment_path')->nullable()->after('reason');
            $table->foreignId('applied_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('applied_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by');
            $table->index(['employee_id', 'status', 'start_date']);
        });

        // Deleting a leave type must never take employees' leave history with it.
        Schema::table('leaves', function (Blueprint $table) {
            $table->dropForeign(['leave_type_id']);
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->restrictOnDelete();
        });

        Schema::table('approval_actions', function (Blueprint $table) {
            $table->string('approver_type', 60)->nullable()->after('step_number'); // manager | <role name>
        });

        Schema::create('leave_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->foreignId('leave_balance_id')->constrained('leave_balances')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            // opening | accrual | carry_forward | adjustment | availed | reversal | carry_forward_out | lapse
            $table->string('type', 20);
            $table->decimal('days', 6, 2);
            $table->date('period')->nullable();
            $table->foreignId('leave_id')->nullable()->constrained('leaves')->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'year']);
            // One accrual / carry forward / lapse per balance per period.
            $table->unique(['leave_balance_id', 'type', 'period'], 'leave_txn_period_unique');
        });

        $this->backfill();

        // Flows were never configurable, so every existing one is the old
        // default step ("hr"), which was never enforced: any approver who
        // could see the employee could act. Now that steps are enforced, a
        // reporting-manager step keeps exactly that working -- managers
        // approve their team, HR and admins act for anyone in their branches.
        DB::table('approval_flows')->update(['steps_json' => json_encode([['step' => 1, 'approver_type' => 'manager']])]);
    }

    private function backfill(): void
    {
        DB::table('leaves')->whereNull('leave_year')->update(['leave_year' => DB::raw('YEAR(start_date)')]);

        $currentYear = (int) now()->year;
        $now = now();

        DB::table('leave_balances')->orderBy('id')->chunkById(500, function ($balances) use ($currentYear, $now) {
            foreach ($balances as $b) {
                $allocated = round((float) $b->allocated, 2);
                $used = round((float) $b->used, 2);
                $balance = round((float) $b->balance, 2);

                DB::table('leave_balances')->where('id', $b->id)->update([
                    'opening' => $allocated,
                    'accrued_through' => "{$b->year}-12-31",
                    // Past years are settled as they stand: no retroactive carry forward.
                    'closed_at' => (int) $b->year < $currentYear ? $now : null,
                ]);

                $rows = [];
                $base = [
                    'company_id' => $b->company_id, 'employee_id' => $b->employee_id, 'leave_type_id' => $b->leave_type_id,
                    'leave_balance_id' => $b->id, 'year' => $b->year, 'created_at' => $now, 'updated_at' => $now,
                ];

                if ($allocated != 0.0) {
                    $rows[] = $base + ['type' => 'opening', 'days' => $allocated, 'period' => "{$b->year}-01-01",
                        'leave_id' => null, 'note' => 'Balance carried over from before the leave ledger'];
                }

                $availed = 0.0;
                $leaves = DB::table('leaves')->where('employee_id', $b->employee_id)->where('leave_type_id', $b->leave_type_id)
                    ->where('leave_year', $b->year)->where('status', 'approved')->orderBy('start_date')->get(['id', 'days']);
                foreach ($leaves as $leave) {
                    $availed += (float) $leave->days;
                    $rows[] = $base + ['type' => 'availed', 'days' => -1 * (float) $leave->days, 'period' => null,
                        'leave_id' => $leave->id, 'note' => null];
                }

                // Whatever the old counters say beyond the leave history, keep it:
                // the ledger must add up to the balance people already see.
                $gap = round($balance - ($allocated - $availed), 2);
                if ($gap != 0.0) {
                    $rows[] = $base + ['type' => 'adjustment', 'days' => $gap, 'period' => null, 'leave_id' => null,
                        'note' => 'Reconciled with the balance recorded before the leave ledger'];
                }

                if ($rows) {
                    DB::table('leave_transactions')->insert($rows);
                }

                // Keep the parts consistent with the total.
                DB::table('leave_balances')->where('id', $b->id)->update([
                    'adjusted' => $gap, 'used' => round($availed, 2), 'allocated' => round($allocated + $gap, 2),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('approval_flows')->update(['steps_json' => json_encode([['step' => 1, 'approver_role' => 'hr']])]);

        Schema::dropIfExists('leave_transactions');

        Schema::table('approval_actions', function (Blueprint $table) {
            $table->dropColumn('approver_type');
        });

        Schema::table('leaves', function (Blueprint $table) {
            $table->dropForeign(['leave_type_id']);
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->cascadeOnDelete();
        });

        Schema::table('leaves', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'status', 'start_date']);
            $table->dropConstrainedForeignId('applied_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['half_day_session', 'leave_year', 'attachment_path', 'cancelled_at', 'cancellation_reason']);
        });

        Schema::table('leave_balances', function (Blueprint $table) {
            $table->dropColumn(['opening', 'accrued', 'adjusted', 'carried_forward', 'lapsed', 'accrued_through', 'closed_at']);
        });

        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['prorate_on_joining', 'min_notice_days']);
        });
    }
};
