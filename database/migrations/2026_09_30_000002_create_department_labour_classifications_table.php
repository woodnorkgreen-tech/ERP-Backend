<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Stream F (Report 67), MIG-H2: labour classification becomes effective-dated.
 *
 * Each row says a department's pay is direct (cost of client work) or indirect
 * (overhead) from a date, who said so and why. Payroll resolves the row in force
 * at the payroll month's end, so classifying a department today never rewrites a
 * month already posted. Rows are only ever added.
 *
 * Existing explicit department values are carried in as history, effective from
 * the start, so payroll posts exactly as it did before this table existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_labour_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('classification', 16);
            $table->date('effective_from');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->index(['department_id', 'effective_from'], 'dept_labour_class_effective_idx');
        });

        $now = now();
        foreach (DB::table('departments')->whereNotNull('labour_classification')->get(['id', 'labour_classification']) as $department) {
            DB::table('department_labour_classifications')->insert([
                'department_id' => $department->id, 'classification' => $department->labour_classification,
                'effective_from' => '2000-01-01', 'set_by' => null,
                'reason' => 'Classification in force before effective dating (Report 67).',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('department_labour_classifications');
    }
};
