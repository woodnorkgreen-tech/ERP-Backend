<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W6-3: Shared cost allocation.
     *
     * A single verified cost line (e.g. a venue hire that serves two projects)
     * can be split across multiple projects by recording allocations. The service
     * enforces: SUM(allocated_amount) = parent cost_line.net_amount — no doubling,
     * no rounding gap. Once allocations exist the parent is excluded from its own
     * project's margin; only the slices appear in each project's margin.
     */
    public function up(): void
    {
        Schema::create('cost_line_allocations', function (Blueprint $table) {
            $table->id();
            // The cost line being shared — source of truth for the total amount.
            $table->foreignId('cost_line_id')->constrained('cost_lines')->cascadeOnDelete();
            // The project receiving this slice.
            $table->foreignId('project_enquiry_id')->constrained('project_enquiries')->cascadeOnDelete();
            // Amount allocated to this project. Must sum to parent net_amount.
            $table->decimal('allocated_amount', 15, 2);
            $table->unsignedBigInteger('allocated_by');
            $table->text('allocation_reason')->nullable();
            $table->timestamps();

            // One allocation per (line, project) pair — prevents double-allocation to
            // the same project from a single cost line.
            $table->unique(['cost_line_id', 'project_enquiry_id'], 'cla_line_project_unique');
            $table->index(['project_enquiry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_line_allocations');
    }
};
