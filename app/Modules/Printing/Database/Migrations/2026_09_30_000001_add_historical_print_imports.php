<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_history_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('file_sha256', 64);
            $table->string('stored_path');
            $table->date('cutoff_date');
            $table->string('status', 20)->default('previewed');
            $table->json('preview_summary')->nullable();
            $table->unsignedInteger('imported_count')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('print_history_source_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_history_import_batch_id')->constrained('print_history_import_batches')->restrictOnDelete();
            $table->foreignId('print_job_id')->unique()->constrained('print_jobs')->cascadeOnDelete();
            $table->string('source_key', 64)->unique();
            $table->string('sheet_name', 100);
            $table->unsignedInteger('sheet_row');
            $table->string('date_source', 20);
            $table->boolean('project_inferred')->default(false);
            $table->string('project_reference')->nullable();
            $table->string('material_label')->nullable();
            $table->string('material_family', 100);
            $table->decimal('recorded_usage_value', 14, 3)->nullable();
            $table->string('recorded_usage_unit', 20)->nullable();
            $table->json('source_values');
            $table->timestamps();
            $table->index(['sheet_name', 'sheet_row']);
        });

        Schema::table('print_job_consumptions', function (Blueprint $table) {
            $table->unsignedBigInteger('print_roll_id')->nullable()->change();
            $table->unsignedBigInteger('material_id')->nullable()->change();
            $table->string('material_name_snapshot')->nullable()->after('material_id');
            $table->string('material_family', 100)->nullable()->after('material_name_snapshot');
        });
    }

    public function down(): void
    {
        if (DB::table('print_history_source_rows')->exists()) {
            throw new \RuntimeException('Remove imported historical print jobs before rolling back this migration.');
        }
        Schema::dropIfExists('print_history_source_rows');
        Schema::dropIfExists('print_history_import_batches');
        Schema::table('print_job_consumptions', function (Blueprint $table) {
            $table->dropColumn(['material_name_snapshot', 'material_family']);
            $table->unsignedBigInteger('print_roll_id')->nullable(false)->change();
            $table->unsignedBigInteger('material_id')->nullable(false)->change();
        });
    }
};
