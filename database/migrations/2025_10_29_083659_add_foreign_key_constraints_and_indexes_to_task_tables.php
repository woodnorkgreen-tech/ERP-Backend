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
        // Note: Foreign key constraints already exist from table creation migrations
        // We skip re-adding them to avoid duplicate constraint errors
        // Instead, we focus on adding check constraints, indexes, and new columns

        // Note: Check constraints are not supported in all MySQL versions and Laravel's Blueprint doesn't have a check method
        // Instead, we'll rely on application-level validation and enum constraints for status fields
        // The status fields already use enum() which provides similar validation at the database level

        // Add performance indexes (skip if they already exist)
        try {
            Schema::table('task_materials_data', function (Blueprint $table) {
                $table->index('enquiry_task_id', 'idx_task_materials_enquiry_task');
                $table->index('created_at', 'idx_task_materials_created_at');
            });
        } catch (\Exception $e) {
            // Index might already exist, continue
        }

        try {
            Schema::table('project_elements', function (Blueprint $table) {
                $table->index(['task_materials_data_id', 'is_included'], 'idx_project_elements_task_included');
                $table->index('category', 'idx_project_elements_category');
                $table->index('sort_order', 'idx_project_elements_sort_order');
            });
        } catch (\Exception $e) {
            // Index might already exist, continue
        }

        try {
            Schema::table('element_materials', function (Blueprint $table) {
                $table->index(['project_element_id', 'is_included'], 'idx_element_materials_element_included');
                $table->index('sort_order', 'idx_element_materials_sort_order');
            });
        } catch (\Exception $e) {
            // Index might already exist, continue
        }

        try {
            Schema::table('task_budget_data', function (Blueprint $table) {
                $table->index('enquiry_task_id', 'idx_task_budget_enquiry_task');
                $table->index('status', 'idx_task_budget_status');
                $table->index('created_at', 'idx_task_budget_created_at');
                $table->index('updated_at', 'idx_task_budget_updated_at');
            });
        } catch (\Exception $e) {
            // Index might already exist, continue
        }

        try {
            Schema::table('budget_approvals', function (Blueprint $table) {
                $table->index('task_budget_data_id', 'idx_budget_approvals_budget');
                $table->index('approved_by', 'idx_budget_approvals_user');
                $table->index('status', 'idx_budget_approvals_status');
                $table->index('approved_at', 'idx_budget_approvals_date');
            });
        } catch (\Exception $e) {
            // Index might already exist, continue
        }

        // Add tracking columns to task_budget_data table for materials integration
        // Check if columns already exist to avoid duplicate column errors
        if (!Schema::hasColumn('task_budget_data', 'materials_imported_at')) {
            Schema::table('task_budget_data', function (Blueprint $table) {
                $table->timestamp('materials_imported_at')->nullable()->after('status');
            });
        }
        if (!Schema::hasColumn('task_budget_data', 'materials_imported_from_task')) {
            Schema::table('task_budget_data', function (Blueprint $table) {
                $table->foreignId('materials_imported_from_task')->nullable()->constrained('enquiry_tasks')->onDelete('set null')->after('materials_imported_at');
            });
        }
        if (!Schema::hasColumn('task_budget_data', 'materials_manually_modified')) {
            Schema::table('task_budget_data', function (Blueprint $table) {
                $table->boolean('materials_manually_modified')->default(false)->after('materials_imported_from_task');
            });
        }
        if (!Schema::hasColumn('task_budget_data', 'materials_import_metadata')) {
            Schema::table('task_budget_data', function (Blueprint $table) {
                $table->json('materials_import_metadata')->nullable()->after('materials_manually_modified');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // For backward compatibility, we don't remove the added columns and indexes
        // This ensures that existing data remains intact and the system continues to work
        // The migration is designed to be non-destructive on rollback

        // Note: In a production environment, you might want to manually remove these
        // columns after confirming no data depends on them, but for safety we keep them
    }
};
