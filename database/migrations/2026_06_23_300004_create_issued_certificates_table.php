<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issued_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->nullable()->constrained('certificate_requests')->nullOnDelete();
            $table->foreignId('template_version_id')->constrained('certificate_template_versions')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->longText('resolved_html');
            $table->text('pdf_path')->nullable();
            $table->string('certificate_number', 64)->unique();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_certificates');
    }
};
