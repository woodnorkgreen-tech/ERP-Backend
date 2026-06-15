<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill tracking_mode for board materials checked in through the main
 * check-in path before BoardRegistrationService::createBoardRecords() set it.
 *
 * Any stock row whose material already has Board records is, by definition, an
 * individually-tracked board material and must be marked 'individual' so it
 * appears in /boards/stock-registry and every tracking_mode-aware query.
 */
return new class extends Migration
{
    public function up(): void
    {
        $boardMaterialIds = DB::table('boards')
            ->distinct()
            ->pluck('library_material_id')
            ->filter()
            ->all();

        if (empty($boardMaterialIds)) {
            return;
        }

        DB::table('stocks')
            ->whereIn('material_id', $boardMaterialIds)
            ->where('tracking_mode', '!=', 'individual')
            ->update(['tracking_mode' => 'individual', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // No-op: we cannot know which rows were 'count' before the backfill,
        // and reverting a correct tracking_mode would re-hide board stock.
    }
};
