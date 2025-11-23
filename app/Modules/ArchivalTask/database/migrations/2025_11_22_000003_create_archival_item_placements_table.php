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
        Schema::create('archival_item_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archival_report_id')->constrained('archival_reports')->onDelete('cascade');
            
            // Section 5.4: Item Placement Details
            $table->string('section_area')->nullable();
            $table->text('items_installed')->nullable();
            $table->enum('placement_accuracy', ['correct', 'needs_adjusted'])->default('correct');
            $table->text('observation')->nullable();
            
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
        Schema::dropIfExists('archival_item_placements');
    }
};
