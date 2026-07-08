<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            if (!Schema::hasColumn('requisition_items', 'project_enquiry_id')) {
                $table->unsignedBigInteger('project_enquiry_id')->nullable()->after('requisition_id')->index();
            }

            if (!Schema::hasColumn('requisition_items', 'procurement_task_id')) {
                $table->unsignedBigInteger('procurement_task_id')->nullable()->after('project_enquiry_id')->index();
            }

            if (!Schema::hasColumn('requisition_items', 'budget_data_id')) {
                $table->unsignedBigInteger('budget_data_id')->nullable()->after('procurement_task_id')->index();
            }

            if (!Schema::hasColumn('requisition_items', 'budget_element_id')) {
                $table->string('budget_element_id')->nullable()->after('budget_data_id');
            }

            if (!Schema::hasColumn('requisition_items', 'budget_element_persistent_id')) {
                $table->string('budget_element_persistent_id')->nullable()->after('budget_element_id')->index();
            }

            if (!Schema::hasColumn('requisition_items', 'budget_item_id')) {
                $table->string('budget_item_id')->nullable()->after('budget_element_persistent_id');
            }

            if (!Schema::hasColumn('requisition_items', 'budget_item_persistent_id')) {
                $table->string('budget_item_persistent_id')->nullable()->after('budget_item_id')->index();
            }

            if (!Schema::hasColumn('requisition_items', 'internal_budget_unit_price')) {
                $table->decimal('internal_budget_unit_price', 15, 2)->nullable()->after('unit_price');
            }

            if (!Schema::hasColumn('requisition_items', 'procurement_item_snapshot')) {
                $table->json('procurement_item_snapshot')->nullable()->after('reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $columns = [
                'project_enquiry_id',
                'procurement_task_id',
                'budget_data_id',
                'budget_element_id',
                'budget_element_persistent_id',
                'budget_item_id',
                'budget_item_persistent_id',
                'internal_budget_unit_price',
                'procurement_item_snapshot',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('requisition_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
