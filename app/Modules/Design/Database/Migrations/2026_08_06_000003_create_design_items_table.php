<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_job_id')->constrained('design_jobs')->cascadeOnDelete();
            $table->foreignId('design_type_id')->nullable()->constrained('design_types')->nullOnDelete();
            $table->foreignId('project_deliverable_id')->nullable()->constrained('project_deliverables')->nullOnDelete();
            $table->enum('stream', ['graphic', 'structural']);
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', [
                'pending',
                'in_design',
                'submitted',
                'approved',
                'not_approved',
                'cancelled',
                'print_ready',
                'production_ready',
                'handed_off',
            ])->default('pending');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('quantity', 15, 2)->default(1);
            $table->enum('dimension_unit', ['m', 'cm', 'mm'])->default('m');
            $table->decimal('length_value', 15, 3)->nullable();
            $table->decimal('width_value', 15, 3)->nullable();
            $table->decimal('height_value', 15, 3)->nullable();
            $table->decimal('length_m', 15, 3)->nullable();
            $table->decimal('width_m', 15, 3)->nullable();
            $table->decimal('height_m', 15, 3)->nullable();
            $table->foreignId('print_material_id')->nullable()->constrained('library_materials')->nullOnDelete();
            $table->text('print_notes')->nullable();
            $table->text('concept_notes')->nullable();
            $table->text('technical_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('print_ready_at')->nullable();
            $table->timestamp('production_ready_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['design_job_id', 'stream']);
            $table->index(['stream', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index('print_material_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_items');
    }
};
