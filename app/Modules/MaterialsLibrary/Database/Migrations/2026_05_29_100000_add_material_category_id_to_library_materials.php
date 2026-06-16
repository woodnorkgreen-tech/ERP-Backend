<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use App\Modules\MaterialsLibrary\Database\Seeders\MaterialCategorySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add column without FK first so we can populate it safely
        if (!Schema::hasColumn('library_materials', 'material_category_id')) {
            Schema::table('library_materials', function (Blueprint $table) {
                $table->unsignedBigInteger('material_category_id')
                      ->nullable()
                      ->after('subcategory')
                      ->comment('FK to material_categories (child/subcategory level)');
            });
        }

        // ── Data migration ──────────────────────────────────────────────────────
        // Match existing category/subcategory strings to material_categories rows.
        //
        // Strategy:
        //   1. Try to match on subcategory name first (child category)
        //   2. Fall back to parent category name if no subcategory match
        //
        // This preserves all existing materials without losing their category.

        if (DB::table('material_categories')->count() === 0) {
            app(MaterialCategorySeeder::class)->run();
        }

        $categories = DB::table('material_categories')
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'parent_id'])
            ->keyBy('name');

        DB::table('library_materials')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->each(function ($material) use ($categories) {
                $categoryId = null;

                // 1. Prefer subcategory match (more specific)
                if (!empty($material->subcategory) && isset($categories[$material->subcategory])) {
                    $categoryId = $categories[$material->subcategory]->id;
                }

                // 2. Fall back to parent category name
                if (!$categoryId && !empty($material->category) && isset($categories[$material->category])) {
                    $categoryId = $categories[$material->category]->id;
                }

                if ($categoryId) {
                    DB::table('library_materials')
                        ->where('id', $material->id)
                        ->update(['material_category_id' => $categoryId]);
                }
            });

        // ── Add FK constraint after data is populated ───────────────────────────
        Schema::table('library_materials', function (Blueprint $table) {
            $table->foreign('material_category_id')
                  ->references('id')
                  ->on('material_categories')
                  ->nullOnDelete();

            $table->index('material_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('library_materials', function (Blueprint $table) {
            $table->dropForeign(['material_category_id']);
            $table->dropIndex(['material_category_id']);
            $table->dropColumn('material_category_id');
        });
    }
};
