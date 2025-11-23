<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('archival_setup_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archival_report_id')->constrained('archival_reports')->onDelete('cascade');
            
            // Section 5.2: Setup Item Allocation
            $table->string('deliverable_item')->nullable();
            $table->string('assigned_technician')->nullable();
            $table->string('site_section')->nullable();
            $table->enum('status', ['set', 'pending'])->default('pending');
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('archival_report_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('archival_setup_items');
    }
};
