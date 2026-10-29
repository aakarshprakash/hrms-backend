<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Work locations become a master list (Head Office, Kochi showroom,
 * Remote...) instead of free text on each employee. Existing text values
 * are turned into masters and linked, so nothing is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name', 191);
            $table->string('code', 50)->nullable();
            $table->string('type', 20)->default('office'); // office | site | client | remote
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('work_location_id')->nullable()->after('work_location')->constrained('work_locations')->nullOnDelete();
        });

        $rows = DB::table('employees')->whereNotNull('work_location')->where('work_location', '!=', '')
            ->select('company_id', 'work_location')->distinct()->get();
        foreach ($rows as $row) {
            $name = trim($row->work_location);
            $id = DB::table('work_locations')->where('company_id', $row->company_id)->where('name', $name)->value('id')
                ?? DB::table('work_locations')->insertGetId([
                    'company_id' => $row->company_id, 'name' => $name,
                    'type' => stripos($name, 'remote') !== false || stripos($name, 'home') !== false ? 'remote' : 'office',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            DB::table('employees')->where('company_id', $row->company_id)->where('work_location', $row->work_location)->update(['work_location_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_location_id');
        });
        Schema::dropIfExists('work_locations');
    }
};
