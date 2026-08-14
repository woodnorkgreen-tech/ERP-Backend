<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->foreignId('original_issue_log_id')->nullable()->after('assigned_job_ref')
                ->constrained('inventory_logs')->nullOnDelete();
            $table->foreignId('board_request_id')->nullable()->after('original_issue_log_id')
                ->constrained('board_requests')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->after('board_request_id')
                ->constrained('projects')->nullOnDelete();
            $table->foreignId('project_material_id')->nullable()->after('project_id')
                ->constrained('element_materials')->nullOnDelete();
            $table->timestamp('return_initiated_at')->nullable()->after('project_material_id');
            $table->foreignId('return_initiated_by')->nullable()->after('return_initiated_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('return_received_at')->nullable()->after('return_initiated_by');
            $table->foreignId('return_received_by')->nullable()->after('return_received_at')
                ->constrained('users')->nullOnDelete();
        });

        // Historical board issues were logged as aggregate quantities. Allocate
        // their physical board identities FIFO within material + job, without
        // inventing a relationship where the evidence is insufficient.
        DB::table('inventory_logs')
            ->whereIn('type', ['check_out', 'issue', 'consumption'])
            ->where('usage_type', 'reusable')
            ->whereNotNull('reference_no')
            ->orderBy('id')
            ->get()
            ->each(function ($issue) {
                $limit = (int) floor(abs((float) $issue->quantity));
                if ($limit < 1) return;

                $boardIds = DB::table('boards')
                    ->where('library_material_id', $issue->material_id)
                    ->where('assigned_job_ref', $issue->reference_no)
                    ->whereNull('original_issue_log_id')
                    ->orderBy('id')->limit($limit)->pluck('id');

                if ($boardIds->isNotEmpty()) {
                    DB::table('boards')->whereIn('id', $boardIds)->update([
                        'original_issue_log_id' => $issue->id,
                        'project_id' => $issue->project_id,
                        'project_material_id' => $issue->project_material_id,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_received_by');
            $table->dropColumn('return_received_at');
            $table->dropConstrainedForeignId('return_initiated_by');
            $table->dropColumn('return_initiated_at');
            $table->dropConstrainedForeignId('project_material_id');
            $table->dropConstrainedForeignId('project_id');
            $table->dropConstrainedForeignId('board_request_id');
            $table->dropConstrainedForeignId('original_issue_log_id');
        });
    }
};
