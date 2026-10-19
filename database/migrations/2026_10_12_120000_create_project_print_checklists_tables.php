<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_print_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_enquiry_id')->unique()->constrained('project_enquiries')->cascadeOnDelete();
            $table->string('project_officer_name')->nullable();
            $table->string('designer_name')->nullable();
            $table->date('date_prepared')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('project_print_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_print_checklist_id')->constrained('project_print_checklists')->cascadeOnDelete();
            $table->text('item_artwork')->nullable();
            $table->string('print_measurement')->nullable();
            $table->string('material')->nullable();
            $table->string('quantity')->nullable();
            $table->text('structure_surface')->nullable();
            $table->text('bleed_allowance')->nullable();
            $table->string('client_approval_status')->nullable();
            $table->string('final_artwork_check')->nullable();
            $table->string('printing_status')->nullable();
            $table->string('print_technician')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['project_print_checklist_id', 'sort_order'], 'print_checklist_items_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_print_checklist_items');
        Schema::dropIfExists('project_print_checklists');
    }
};