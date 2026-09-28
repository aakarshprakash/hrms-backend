<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave policy per leave type, configured by each tenant rather than
 * hardcoded: accrual (annual / monthly / none), carry-forward cap,
 * encashment, gender applicability, negative balance, half days, minimum
 * service, maximum consecutive days, supporting documents, sandwich rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->string('code', 10)->nullable()->after('name');
            $table->string('accrual', 10)->default('annual')->after('days_per_year'); // annual | monthly | none
            $table->decimal('max_carry_forward', 6, 2)->nullable()->after('carry_forward');
            $table->boolean('encashable')->default(false)->after('max_carry_forward');
            $table->string('applicable_gender', 10)->nullable();
            $table->boolean('allow_negative')->default(false);
            $table->boolean('allow_half_day')->default(true);
            $table->unsignedSmallInteger('min_service_days')->default(0);
            $table->unsignedSmallInteger('max_consecutive_days')->nullable();
            $table->unsignedSmallInteger('requires_document_after_days')->nullable();
            $table->boolean('sandwich_rule')->default(false);
            $table->string('color', 9)->nullable();
            $table->boolean('is_active')->default(true);
        });

        // Codes and sensible policies for leave types that already exist.
        $map = [
            'casual' => ['CL', 'annual', null], 'sick' => ['SL', 'annual', null], 'earned' => ['EL', 'monthly', null],
            'privilege' => ['PL', 'monthly', null], 'compensatory' => ['CO', 'none', null], 'comp' => ['CO', 'none', null],
            'maternity' => ['ML', 'annual', 'female'], 'paternity' => ['PTL', 'annual', 'male'],
            'loss of pay' => ['LOP', 'none', null], 'unpaid' => ['LOP', 'none', null], 'lop' => ['LOP', 'none', null],
        ];
        foreach (DB::table('leave_types')->get(['id', 'name', 'paid']) as $type) {
            foreach ($map as $needle => [$code, $accrual, $gender]) {
                if (str_contains(strtolower($type->name), $needle)) {
                    DB::table('leave_types')->where('id', $type->id)->update([
                        'code' => $code, 'accrual' => $accrual, 'applicable_gender' => $gender,
                        'allow_negative' => $code === 'LOP' || ! $type->paid,
                    ]);
                    break;
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['code', 'accrual', 'max_carry_forward', 'encashable', 'applicable_gender', 'allow_negative',
                'allow_half_day', 'min_service_days', 'max_consecutive_days', 'requires_document_after_days', 'sandwich_rule', 'color', 'is_active']);
        });
    }
};
