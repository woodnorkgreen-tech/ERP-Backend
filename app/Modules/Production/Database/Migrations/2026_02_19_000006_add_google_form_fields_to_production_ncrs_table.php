<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_ncrs', function (Blueprint $table) {
            if (!Schema::hasColumn('production_ncrs', 'shift')) {
                $table->string('shift', 50)->nullable()->after('source_ref');
            }
            if (!Schema::hasColumn('production_ncrs', 'raised_by_name')) {
                $table->string('raised_by_name', 160)->nullable()->after('shift');
            }
            if (!Schema::hasColumn('production_ncrs', 'job_order_no')) {
                $table->string('job_order_no', 120)->nullable()->after('raised_by_name');
            }
            if (!Schema::hasColumn('production_ncrs', 'quantity_affected')) {
                $table->decimal('quantity_affected', 12, 2)->nullable()->after('description');
            }
            if (!Schema::hasColumn('production_ncrs', 'failure_description')) {
                $table->text('failure_description')->nullable()->after('quantity_affected');
            }
            if (!Schema::hasColumn('production_ncrs', 'primary_sop_breached')) {
                $table->string('primary_sop_breached', 200)->nullable()->after('failure_description');
            }
            if (!Schema::hasColumn('production_ncrs', 'conformance_type')) {
                $table->string('conformance_type', 80)->nullable()->after('primary_sop_breached');
            }
            if (!Schema::hasColumn('production_ncrs', 'items_rejected')) {
                $table->decimal('items_rejected', 12, 2)->nullable()->after('conformance_type');
            }
            if (!Schema::hasColumn('production_ncrs', 'rejects_location')) {
                $table->string('rejects_location', 160)->nullable()->after('items_rejected');
            }
            if (!Schema::hasColumn('production_ncrs', 'production_impact')) {
                $table->text('production_impact')->nullable()->after('rejects_location');
            }
            if (!Schema::hasColumn('production_ncrs', 'client_impacted')) {
                $table->boolean('client_impacted')->default(false)->after('production_impact');
            }
            if (!Schema::hasColumn('production_ncrs', 'immediate_action_taken')) {
                $table->text('immediate_action_taken')->nullable()->after('client_impacted');
            }
            if (!Schema::hasColumn('production_ncrs', 'root_cause_category')) {
                $table->string('root_cause_category', 120)->nullable()->after('immediate_action_taken');
            }
            if (!Schema::hasColumn('production_ncrs', 'root_cause_description')) {
                $table->text('root_cause_description')->nullable()->after('root_cause_category');
            }
            if (!Schema::hasColumn('production_ncrs', 'preventive_action')) {
                $table->text('preventive_action')->nullable()->after('root_cause_description');
            }
            if (!Schema::hasColumn('production_ncrs', 'reinspection_performed')) {
                $table->boolean('reinspection_performed')->default(false)->after('preventive_action');
            }
            if (!Schema::hasColumn('production_ncrs', 'reinspection_results')) {
                $table->text('reinspection_results')->nullable()->after('reinspection_performed');
            }
            if (!Schema::hasColumn('production_ncrs', 'image_path')) {
                $table->string('image_path')->nullable()->after('reinspection_results');
            }
            if (!Schema::hasColumn('production_ncrs', 'image_original_name')) {
                $table->string('image_original_name')->nullable()->after('image_path');
            }
            if (!Schema::hasColumn('production_ncrs', 'resolution')) {
                $table->text('resolution')->nullable()->after('image_original_name');
            }
            if (!Schema::hasColumn('production_ncrs', 'supervisor_approval')) {
                $table->boolean('supervisor_approval')->default(false)->after('resolution');
            }
            if (!Schema::hasColumn('production_ncrs', 'supervisor_approved_by')) {
                $table->unsignedBigInteger('supervisor_approved_by')->nullable()->after('supervisor_approval');
                $table->foreign('supervisor_approved_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('production_ncrs', 'supervisor_approved_at')) {
                $table->timestamp('supervisor_approved_at')->nullable()->after('supervisor_approved_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('production_ncrs', function (Blueprint $table) {
            if (Schema::hasColumn('production_ncrs', 'supervisor_approved_at')) {
                $table->dropColumn('supervisor_approved_at');
            }
            if (Schema::hasColumn('production_ncrs', 'supervisor_approved_by')) {
                $table->dropForeign(['supervisor_approved_by']);
                $table->dropColumn('supervisor_approved_by');
            }
            $columns = [
                'supervisor_approval',
                'resolution',
                'image_original_name',
                'image_path',
                'reinspection_results',
                'reinspection_performed',
                'preventive_action',
                'root_cause_description',
                'root_cause_category',
                'immediate_action_taken',
                'client_impacted',
                'production_impact',
                'rejects_location',
                'items_rejected',
                'conformance_type',
                'primary_sop_breached',
                'failure_description',
                'quantity_affected',
                'job_order_no',
                'raised_by_name',
                'shift',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('production_ncrs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
