<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_jobs', function (Blueprint $table) {
            $table->foreignId('graphic_main_designer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('structural_main_designer_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('design_items', function (Blueprint $table) {
            $table->string('generated_scope_key', 100)->nullable()->unique();
            $table->string('assignment_source', 30)->nullable();
            $table->string('last_synced_element_title')->nullable();
            $table->timestamp('source_element_archived_at')->nullable();
            $table->foreignId('not_required_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('not_required_at')->nullable();
            $table->text('not_required_reason')->nullable();
        });

        Schema::table('design_items', function (Blueprint $table) {
            $table->enum('status', [
                'pending', 'in_design', 'submitted', 'awaiting_client_approval',
                'client_approved', 'client_changes_requested', 'approved', 'done',
                'not_approved', 'cancelled', 'print_ready', 'production_ready',
                'handed_off', 'not_required',
            ])->default('pending')->change();
        });
    }

    public function down(): void
    {
        DB::table('design_items')->where('status', 'not_required')->update(['status' => 'pending']);
        Schema::table('design_items', function (Blueprint $table) {
            $table->enum('status', [
                'pending', 'in_design', 'submitted', 'awaiting_client_approval',
                'client_approved', 'client_changes_requested', 'approved', 'done',
                'not_approved', 'cancelled', 'print_ready', 'production_ready',
                'handed_off',
            ])->default('pending')->change();
            $table->dropUnique(['generated_scope_key']);
            $table->dropConstrainedForeignId('not_required_by');
            $table->dropColumn(['generated_scope_key', 'assignment_source', 'last_synced_element_title', 'source_element_archived_at', 'not_required_at', 'not_required_reason']);
        });
        Schema::table('design_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('graphic_main_designer_id');
            $table->dropConstrainedForeignId('structural_main_designer_id');
        });
    }
};
