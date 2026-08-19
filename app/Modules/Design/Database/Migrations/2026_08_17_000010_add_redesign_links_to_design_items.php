<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_items', function (Blueprint $table) {
            if (!Schema::hasColumn('design_items', 'redesign_of_item_id')) {
                $table->foreignId('redesign_of_item_id')
                    ->nullable()
                    ->after('project_deliverable_id')
                    ->constrained('design_items')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('design_items', 'redesign_of_print_job_id')) {
                $table->foreignId('redesign_of_print_job_id')
                    ->nullable()
                    ->after('redesign_of_item_id')
                    ->constrained('print_jobs')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('design_items', 'redesign_source')) {
                $table->enum('redesign_source', ['design', 'printing'])->nullable()->after('redesign_of_print_job_id');
            }

            if (!Schema::hasColumn('design_items', 'redesign_reason')) {
                $table->text('redesign_reason')->nullable()->after('redesign_source');
            }

            if (!Schema::hasColumn('design_items', 'redesign_requested_at')) {
                $table->timestamp('redesign_requested_at')->nullable()->after('redesign_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('design_items', function (Blueprint $table) {
            if (Schema::hasColumn('design_items', 'redesign_of_print_job_id')) {
                $table->dropConstrainedForeignId('redesign_of_print_job_id');
            }

            if (Schema::hasColumn('design_items', 'redesign_of_item_id')) {
                $table->dropConstrainedForeignId('redesign_of_item_id');
            }

            foreach (['redesign_source', 'redesign_reason', 'redesign_requested_at'] as $column) {
                if (Schema::hasColumn('design_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
