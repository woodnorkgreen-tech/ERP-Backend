<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W6-4: Cost transfer / reclassification.
     *
     * Moves a verified cost from Project A to Project B via a reversing pair:
     *   CL-TRF-OUT-xxxxx — negative entry on the source project, auto-verified.
     *   CL-TRF-IN-xxxxx  — positive entry on the destination project, auto-verified.
     *
     * The original cost line is NEVER modified. Its project_enquiry_id stays put.
     * This record groups the three lines so Finance can trace the transfer.
     */
    public function up(): void
    {
        Schema::create('cost_line_transfers', function (Blueprint $table) {
            $table->id();
            // The original verified cost line being transferred.
            $table->foreignId('source_cost_line_id')->constrained('cost_lines');
            // The negating OUT entry on the source project.
            $table->foreignId('out_cost_line_id')->constrained('cost_lines');
            // The matching IN entry on the destination project.
            $table->foreignId('in_cost_line_id')->constrained('cost_lines');
            $table->text('reason');
            $table->unsignedBigInteger('transferred_by');
            $table->timestamps();

            $table->index(['source_cost_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_line_transfers');
    }
};
