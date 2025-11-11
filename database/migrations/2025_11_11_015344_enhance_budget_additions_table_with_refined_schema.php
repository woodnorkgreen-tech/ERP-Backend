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
        Schema::table('budget_additions', function (Blueprint $table) {
            $table->enum('budget_type', ['main', 'supplementary'])->default('supplementary')->after('description');
            $table->enum('source_type', ['manual', 'materials_additional'])->default('manual')->after('budget_type');
            $table->foreignId('source_material_id')->nullable()->constrained('element_materials')->onDelete('set null')->after('source_type');
            $table->foreignId('source_element_id')->nullable()->constrained('project_elements')->onDelete('set null')->after('source_material_id');
            $table->decimal('total_amount', 15, 2)->default(0)->after('logistics');
            $table->text('rejection_reason')->nullable()->after('approved_at');
            $table->foreignId('rejected_by')->nullable()->constrained('users')->onDelete('set null')->after('rejection_reason');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');

            // Add indexes for performance
            $table->index(['budget_type', 'status']);
            $table->index(['source_type', 'source_material_id']);
            $table->index(['source_type', 'source_element_id']);
            $table->index('rejected_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budget_additions', function (Blueprint $table) {
            $table->dropIndex(['budget_type', 'status']);
            $table->dropIndex(['source_type', 'source_material_id']);
            $table->dropIndex(['source_type', 'source_element_id']);
            $table->dropIndex(['rejected_by']);

            $table->dropForeign(['source_material_id']);
            $table->dropForeign(['source_element_id']);
            $table->dropForeign(['rejected_by']);

            $table->dropColumn([
                'budget_type',
                'source_type',
                'source_material_id',
                'source_element_id',
                'total_amount',
                'rejection_reason',
                'rejected_by',
                'rejected_at'
            ]);
        });
    }
};
