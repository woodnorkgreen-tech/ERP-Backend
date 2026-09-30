<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W7 remediation (Report 41).
 *
 * 1. Return → Correct → Resubmit: resubmission attribution on the actual, plus an
 *    append-only return history (one row per return cycle; the actual's own
 *    returned_* columns only ever show the latest cycle).
 * 2. W7-13: a labour correction or reclassification is a W6-4 cost transfer. The
 *    actual links to the transfer that carried its economic effect.
 * 3. cost_line_transfers.transfer_type distinguishes a cross-project
 *    reclassification from a same-project correction, and source_cost_line_id
 *    becomes unique so one cost line can be transferred or corrected at most once
 *    even under concurrent requests (the service guard alone was check-then-act).
 *
 * Additive and reversible; no existing data is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_labour_actuals', function (Blueprint $table) {
            $table->foreignId('resubmitted_by')->nullable()->after('return_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('resubmitted_at')->nullable()->after('resubmitted_by');
            $table->unsignedInteger('resubmission_count')->default(0)->after('resubmitted_at');
            $table->foreignId('correction_transfer_id')->nullable()->after('correction_reason')
                ->constrained('cost_line_transfers', 'id', 'pla_correction_transfer_fk')->nullOnDelete();
            $table->foreignId('reclassification_transfer_id')->nullable()->after('correction_transfer_id')
                ->constrained('cost_line_transfers', 'id', 'pla_reclass_transfer_fk')->nullOnDelete();
        });

        Schema::create('project_labour_actual_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_labour_actual_id')
                ->constrained('project_labour_actuals', 'id', 'pla_returns_actual_fk')->cascadeOnDelete();
            $table->unsignedInteger('cycle');
            $table->string('returned_from_status');
            $table->foreignId('returned_by')->constrained('users', 'id', 'pla_returns_returned_by_fk');
            $table->timestamp('returned_at');
            $table->text('return_reason');
            $table->json('snapshot_before');
            $table->foreignId('resubmitted_by')->nullable()
                ->constrained('users', 'id', 'pla_returns_resubmitted_by_fk')->nullOnDelete();
            $table->timestamp('resubmitted_at')->nullable();
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->unique(['project_labour_actual_id', 'cycle'], 'pla_returns_actual_cycle_unique');
        });

        Schema::table('cost_line_transfers', function (Blueprint $table) {
            $table->string('transfer_type')->default('reclassification')->after('in_cost_line_id');
            $table->unique('source_cost_line_id', 'cost_line_transfers_source_unique');
        });

        Schema::table('cost_line_transfers', function (Blueprint $table) {
            $table->dropIndex('cost_line_transfers_source_cost_line_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('cost_line_transfers', function (Blueprint $table) {
            $table->index('source_cost_line_id', 'cost_line_transfers_source_cost_line_id_index');
        });

        Schema::table('cost_line_transfers', function (Blueprint $table) {
            $table->dropUnique('cost_line_transfers_source_unique');
            $table->dropColumn('transfer_type');
        });

        Schema::dropIfExists('project_labour_actual_returns');

        Schema::table('project_labour_actuals', function (Blueprint $table) {
            $table->dropForeign('pla_reclass_transfer_fk');
            $table->dropForeign('pla_correction_transfer_fk');
            $table->dropColumn('reclassification_transfer_id');
            $table->dropColumn('correction_transfer_id');
            $table->dropConstrainedForeignId('resubmitted_by');
            $table->dropColumn(['resubmitted_at', 'resubmission_count']);
        });
    }
};
