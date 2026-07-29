<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Personal
            $table->string('personal_email', 191)->nullable()->after('email');
            $table->string('marital_status', 30)->nullable()->after('gender');
            $table->string('blood_group', 10)->nullable()->after('marital_status');
            $table->string('nationality', 100)->nullable()->after('blood_group');
            $table->string('national_id', 100)->nullable()->after('nationality');
            $table->string('tax_id', 100)->nullable()->after('national_id');

            // Address
            $table->string('address_line1', 255)->nullable();
            $table->string('address_line2', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('postal_code', 30)->nullable();

            // Emergency contact
            $table->string('emergency_contact_name', 191)->nullable();
            $table->string('emergency_contact_phone', 50)->nullable();
            $table->string('emergency_contact_relation', 100)->nullable();

            // Bank & payment
            $table->string('bank_name', 191)->nullable();
            $table->string('bank_branch', 191)->nullable();
            $table->string('bank_account_number', 100)->nullable();
            $table->string('bank_ifsc_code', 50)->nullable();
            $table->string('payment_method', 30)->nullable();

            // Employment extras
            $table->date('probation_end_date')->nullable();
            $table->date('date_of_leaving')->nullable();
            $table->unsignedInteger('notice_period_days')->nullable();
            $table->string('work_location', 191)->nullable();
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'personal_email', 'marital_status', 'blood_group', 'nationality', 'national_id', 'tax_id',
                'address_line1', 'address_line2', 'city', 'state', 'country', 'postal_code',
                'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
                'bank_name', 'bank_branch', 'bank_account_number', 'bank_ifsc_code', 'payment_method',
                'probation_end_date', 'date_of_leaving', 'notice_period_days', 'work_location', 'notes',
            ]);
        });
    }
};
