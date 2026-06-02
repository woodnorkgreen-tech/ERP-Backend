<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_inspections', function (Blueprint $table) {
            $table->id();

            // Auto-generated: INS-2026-0001
            $table->string('inspection_code')->unique();

            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();

            // Inspector = driver or logistics officer who fills the form
            $table->foreignId('inspector_id')
                  ->nullable()
                  ->constrained('employees')
                  ->nullOnDelete();

            // Logistics officer who signs off
            $table->foreignId('logistics_officer_id')
                  ->nullable()
                  ->constrained('employees')
                  ->nullOnDelete();

            // Inspection type: pre_trip | post_trip | routine
            $table->enum('inspection_type', ['pre_trip', 'post_trip', 'routine'])->default('pre_trip');

            // Date + time of inspection
            $table->date('inspection_date');
            $table->time('inspection_time')->nullable();

            // Odometer at inspection
            $table->decimal('odometer_reading', 10, 2)->nullable();

            // Fueling info (optional — matches the WNG form)
            $table->decimal('fueling_odometer', 10, 2)->nullable();
            $table->decimal('amount_fueled_litres', 8, 2)->nullable();

            // ── Checklist items — each is: pass | fail | na ───────────────
            // Stored as JSON for flexibility: { item_key: 'pass'|'fail'|'na', remark: '' }
            $table->json('checklist')->nullable();

            // Overall result
            $table->enum('overall_result', [
                'passed',           // all items pass or na
                'passed_with_notes',// passed but has remarks
                'failed',           // one or more critical failures
            ])->nullable();

            // Inspector comments / overall notes
            $table->text('inspector_comments')->nullable();

            // Condition checkboxes (from the WNG form)
            $table->boolean('condition_acceptable')->default(false);
            $table->boolean('defects_repair_immediately')->default(false);
            $table->boolean('defects_repair_few_days')->default(false);

            // Status: draft → submitted → reviewed
            $table->enum('status', ['draft', 'submitted', 'reviewed'])->default('draft');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_inspections');
    }
};
