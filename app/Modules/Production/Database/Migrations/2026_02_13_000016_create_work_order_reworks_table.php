<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('work_order_reworks')) {
            return;
        }

        Schema::create('work_order_reworks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->enum('source_type', ['mid_qc', 'final_qc', 'manual']);
            $table->string('source_ref', 160)->nullable();
            $table->string('qc_stage', 50)->nullable();
            $table->string('title', 190);
            $table->text('reason')->nullable();
            $table->enum('status', ['open', 'in_progress', 'closed'])->default('open');
            $table->string('assigned_workstation', 120)->nullable();
            $table->string('assigned_to', 190)->nullable();
            $table->date('target_date')->nullable();
            $table->enum('qc_status', ['pending', 'passed', 'failed'])->default('pending');
            $table->string('qc_reason', 255)->nullable();
            $table->boolean('is_change_request')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['work_order_id', 'source_type', 'source_ref', 'title'], 'work_order_reworks_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_reworks');
    }
};
