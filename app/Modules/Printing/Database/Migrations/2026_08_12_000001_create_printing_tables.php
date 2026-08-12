<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_handoff_id')->nullable()->constrained('design_handoffs')->nullOnDelete();
            $table->foreignId('design_item_id')->nullable()->constrained('design_items')->nullOnDelete();
            $table->foreignId('design_job_id')->nullable()->constrained('design_jobs')->nullOnDelete();
            $table->foreignId('project_enquiry_id')->nullable()->constrained('project_enquiries')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('job_number')->nullable();
            $table->string('project_name')->nullable();
            $table->string('client_name')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('final_artwork_url')->nullable();
            $table->unsignedBigInteger('final_artwork_document_id')->nullable();
            $table->unsignedInteger('artwork_version')->nullable();
            $table->enum('order_type', ['original', 'reprint', 'test', 'internal', 'outsourced'])->default('original');
            $table->foreignId('reprint_of_job_id')->nullable()->constrained('print_jobs')->nullOnDelete();
            $table->text('reprint_reason')->nullable();
            $table->enum('status', [
                'queued',
                'preflight',
                'ready_to_print',
                'printing',
                'printed',
                'qc_failed',
                'reprint_required',
                'completed',
                'cancelled',
            ])->default('queued');
            $table->date('due_date')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('machine_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('machine_name_snapshot')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('project_enquiry_id');
            $table->index('project_id');
            $table->index('operator_id');
            $table->index('machine_asset_id');
            $table->index('order_type');
            $table->index('completed_at');
        });

        Schema::create('print_material_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('library_materials')->restrictOnDelete();
            $table->decimal('requested_quantity_m', 12, 3);
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('project_enquiry_id')->nullable()->constrained('project_enquiries')->nullOnDelete();
            $table->foreignId('print_job_id')->nullable()->constrained('print_jobs')->nullOnDelete();
            $table->enum('urgency', ['normal', 'urgent'])->default('normal');
            $table->string('reason')->nullable();
            $table->enum('status', ['draft', 'requested', 'approved', 'issued', 'received', 'rejected', 'cancelled'])->default('requested');
            $table->foreignId('stores_inventory_log_id')->nullable()->constrained('inventory_logs')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['material_id', 'status']);
            $table->index(['project_enquiry_id', 'status']);
        });

        Schema::create('print_rolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('library_materials')->restrictOnDelete();
            $table->foreignId('source_inventory_log_id')->nullable()->constrained('inventory_logs')->nullOnDelete();
            $table->foreignId('print_material_request_id')->nullable()->constrained('print_material_requests')->nullOnDelete();
            $table->string('material_code_snapshot')->nullable();
            $table->string('material_name_snapshot');
            $table->string('roll_code')->unique();
            $table->string('display_label');
            $table->unsignedInteger('received_sequence')->default(1);
            $table->date('received_at');
            $table->decimal('received_length_m', 12, 3);
            $table->decimal('remaining_length_m', 12, 3);
            $table->decimal('roll_width_m', 12, 3)->nullable();
            $table->enum('status', ['active', 'reserved', 'depleted', 'damaged', 'returned', 'reconciled', 'archived'])->default('active');
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['material_id', 'status', 'received_at']);
            $table->index('remaining_length_m');
        });

        Schema::create('print_job_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_job_id')->constrained('print_jobs')->cascadeOnDelete();
            $table->foreignId('print_roll_id')->constrained('print_rolls')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('library_materials')->restrictOnDelete();
            $table->decimal('artwork_width_m', 12, 3)->nullable();
            $table->decimal('artwork_height_m', 12, 3)->nullable();
            $table->unsignedInteger('artwork_count')->default(1);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('bleed_preset')->nullable();
            $table->decimal('bleed_left_m', 12, 3)->default(0);
            $table->decimal('bleed_right_m', 12, 3)->default(0);
            $table->decimal('bleed_top_m', 12, 3)->default(0);
            $table->decimal('bleed_bottom_m', 12, 3)->default(0);
            $table->decimal('spacing_m', 12, 3)->default(0);
            $table->decimal('setup_allowance_m', 12, 3)->default(0);
            $table->decimal('calculated_print_width_m', 12, 3)->nullable();
            $table->decimal('calculated_print_length_m', 12, 3)->nullable();
            $table->decimal('calculated_sqm', 12, 3)->nullable();
            $table->decimal('calculated_running_m', 12, 3)->nullable();
            $table->decimal('actual_running_m', 12, 3)->nullable();
            $table->decimal('variance_m', 12, 3)->nullable();
            $table->decimal('variance_percent', 12, 3)->nullable();
            $table->string('variance_reason')->nullable();
            $table->timestamps();

            $table->index(['print_job_id', 'print_roll_id']);
            $table->index('material_id');
        });

        Schema::create('print_manual_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_roll_id')->constrained('print_rolls')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('library_materials')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('project_enquiry_id')->nullable()->constrained('project_enquiries')->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason');
            $table->decimal('quantity_m', 12, 3);
            $table->text('notes')->nullable();
            $table->dateTime('consumed_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['material_id', 'consumed_at']);
            $table->index('project_enquiry_id');
        });

        Schema::create('print_job_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_job_id')->constrained('print_jobs')->cascadeOnDelete();
            $table->string('event_type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['print_job_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_job_events');
        Schema::dropIfExists('print_manual_consumptions');
        Schema::dropIfExists('print_job_consumptions');
        Schema::dropIfExists('print_rolls');
        Schema::dropIfExists('print_material_requests');
        Schema::dropIfExists('print_jobs');
    }
};
