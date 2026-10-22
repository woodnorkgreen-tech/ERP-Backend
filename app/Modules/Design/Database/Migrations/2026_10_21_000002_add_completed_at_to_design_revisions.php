<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_revisions', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('status');
        });
        DB::table('design_revisions')->where('status', 'approved')
            ->update(['completed_at' => DB::raw('COALESCE(approved_at, created_at)')]);

        // Keep existing print-ready work usable after the version-specific handoff rule.
        DB::table('design_items')->where('stream', 'graphic')
            ->whereIn('status', ['print_ready', 'handed_off'])->orderBy('id')
            ->chunkById(100, function ($items) {
                foreach ($items as $item) {
                    if (DB::table('design_revisions')->where('design_item_id', $item->id)->exists()) {
                        continue;
                    }
                    $artwork = DB::table('design_documents')->where('design_item_id', $item->id)
                        ->where('document_type', 'artwork')->where('source', 'link')->where('status', 'active')
                        ->orderByDesc('version')->orderByDesc('id')->first();
                    if (!$artwork) {
                        continue;
                    }
                    $timestamp = $item->print_ready_at ?? $item->updated_at ?? now();
                    $revisionId = DB::table('design_revisions')->insertGetId([
                        'design_item_id' => $item->id,
                        'version_number' => 1,
                        'change_summary' => 'Existing print-ready artwork',
                        'status' => 'approved',
                        'completed_at' => $timestamp,
                        'approved_at' => $timestamp,
                        'approval_evidence' => 'Existing print-ready status before revision tracking',
                        'created_by' => $item->updated_by,
                        'approved_by' => $item->updated_by,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                    DB::table('design_documents')->where('id', $artwork->id)
                        ->update(['design_revision_id' => $revisionId, 'version' => 1]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('design_revisions', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
