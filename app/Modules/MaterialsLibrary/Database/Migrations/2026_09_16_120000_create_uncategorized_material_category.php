<?php

use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\MaterialItemType;
use Illuminate\Database\Migrations\Migration;

/**
 * The placeholder category MaterialRegistrationService assigns when nobody
 * names one at creation. It carries real defaults (item type, disposition,
 * tracking mode, a stock unit) so a material can still reach Active without
 * anyone picking from the taxonomy — and it stays an ordinary, re-filable
 * category afterwards (bulk repair / merge already move materials off it).
 */
return new class extends Migration
{
    public function up(): void
    {
        $stockType = MaterialItemType::where('code', 'STOCK')->first();

        $group = MaterialCategory::firstOrCreate(
            ['code' => 'UNCATG'],
            [
                'name' => 'Uncategorized',
                'is_active' => true,
                'is_selectable' => false,
            ],
        );

        MaterialCategory::firstOrCreate(
            ['code' => 'UNCAT'],
            [
                'name' => 'Uncategorized',
                'parent_id' => $group->id,
                'item_type_id' => $stockType?->id,
                'default_issue_disposition' => 'consumed',
                'default_tracking_mode' => 'bulk_quantity',
                'allowed_uoms' => ['pcs'],
                'is_active' => true,
                'is_selectable' => true,
            ],
        );
    }

    public function down(): void
    {
        // Left in place deliberately: by the time anyone rolls this back, real
        // materials may already reference it, and material_category_id's
        // foreign key would refuse the delete anyway. Re-categorizing away
        // from it is what the existing bulk-repair/merge tools are for.
    }
};
