<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_materials', function (Blueprint $table) {
            if (!Schema::hasColumn('library_materials', 'material_type')) {
                $table->string('material_type', 20)
                      ->nullable()
                      ->default('consumable')
                      ->after('subcategory')
                      ->comment('consumable | reusable — drives board tracking and inventory log behaviour');
            }
        });

        // Add index only if it doesn't already exist
        $indexes = collect(DB::select("SHOW INDEX FROM library_materials"))
            ->pluck('Key_name');

        if (!$indexes->contains('library_materials_material_type_index')) {
            Schema::table('library_materials', function (Blueprint $table) {
                $table->index('material_type');
            });
        }

        // Backfill: any material that already has board records is reusable
        DB::statement("
            UPDATE library_materials lm
            INNER JOIN (
                SELECT DISTINCT library_material_id
                FROM boards
            ) b ON b.library_material_id = lm.id
            SET lm.material_type = 'reusable'
            WHERE lm.deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('library_materials', function (Blueprint $table) {
            if (Schema::hasColumn('library_materials', 'material_type')) {
                $table->dropIndex(['material_type']);
                $table->dropColumn('material_type');
            }
        });
    }
};
