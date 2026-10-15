<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report 78C — the two lifecycle facts the ERP had no way to record.
 *
 * Purely additive: no BOM line, stock movement, requisition or journal is
 * touched, and nothing is backfilled. Both tables are append-only histories —
 * a hold is released and a retirement is revoked by stamping the row, never by
 * deleting it, so the record of who decided what and why survives the reversal.
 *
 *  - project_holds: one open row (released_at IS NULL) means the job is on
 *    hold. Material issue and new procurement demand stop; nothing stored
 *    changes, so releasing the hold brings the demand straight back.
 *  - material_requirement_retirements: "this outstanding quantity was
 *    fulfilled outside the ERP". It is per BOM line (element_materials ids are
 *    stable: lines are upserted by persistent_id and soft-deleted), so a job
 *    can carry retired historical requirements and live new work at once.
 *    retired_bom_quantity is in the BOM line's own unit; the stock-unit reading
 *    is derived through RequirementUnits at read time, exactly like the
 *    requirement itself. bom_quantity_snapshot is the line quantity when it was
 *    retired, so a later increase shows up as live work instead of vanishing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_holds')) {
            Schema::create('project_holds', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_enquiry_id');
                $table->text('reason');
                $table->unsignedBigInteger('placed_by');
                $table->timestamp('placed_at');
                $table->unsignedBigInteger('released_by')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->text('release_reason')->nullable();
                $table->timestamps();

                $table->foreign('project_enquiry_id', 'project_holds_enquiry_fk')
                    ->references('id')->on('project_enquiries')->cascadeOnDelete();
                $table->foreign('placed_by', 'project_holds_placed_by_fk')->references('id')->on('users');
                $table->foreign('released_by', 'project_holds_released_by_fk')->references('id')->on('users');
                $table->index(['project_enquiry_id', 'released_at'], 'project_holds_open_idx');
            });
        }

        if (! Schema::hasTable('material_requirement_retirements')) {
            Schema::create('material_requirement_retirements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('element_material_id');
                $table->unsignedBigInteger('project_enquiry_id');
                $table->decimal('retired_bom_quantity', 18, 6);
                $table->decimal('bom_quantity_snapshot', 18, 6);
                $table->string('basis', 40)->default('legacy_fulfilled_outside_erp');
                $table->text('reason');
                $table->unsignedBigInteger('retired_by');
                $table->timestamp('retired_at');
                $table->unsignedBigInteger('revoked_by')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->text('revoke_reason')->nullable();
                $table->text('reconciliation_note')->nullable();
                $table->timestamps();

                $table->foreign('element_material_id', 'mat_req_ret_line_fk')
                    ->references('id')->on('element_materials')->cascadeOnDelete();
                $table->foreign('project_enquiry_id', 'mat_req_ret_enquiry_fk')
                    ->references('id')->on('project_enquiries')->cascadeOnDelete();
                $table->foreign('retired_by', 'mat_req_ret_retired_by_fk')->references('id')->on('users');
                $table->foreign('revoked_by', 'mat_req_ret_revoked_by_fk')->references('id')->on('users');
                $table->index(['element_material_id', 'revoked_at'], 'mat_req_ret_line_open_idx');
                $table->index(['project_enquiry_id', 'revoked_at'], 'mat_req_ret_job_open_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('material_requirement_retirements');
        Schema::dropIfExists('project_holds');
    }
};
