<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->enum('module', ['leave', 'overtime', 'regularization']);
            $table->json('steps_json');
            $table->timestamps();
            $table->unique(['branch_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_flows');
    }
};
