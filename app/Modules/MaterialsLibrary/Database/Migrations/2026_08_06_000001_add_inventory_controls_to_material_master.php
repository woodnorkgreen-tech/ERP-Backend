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
            $table->string('item_status', 20)->default('Under Review')->after('material_type')->index();
            $table->string('issue_disposition', 30)->nullable()->after('item_status')->index();
            $table->string('tracking_mode', 30)->nullable()->after('issue_disposition')->index();
            $table->boolean('is_hazardous')->default(false)->after('tracking_mode');
            $table->boolean('is_serialized')->default(false)->after('is_hazardous');
            $table->boolean('is_batch_controlled')->default(false)->after('is_serialized');
            $table->boolean('is_expiry_controlled')->default(false)->after('is_batch_controlled');
            $table->boolean('is_project_chargeable')->default(true)->after('is_expiry_controlled');
            $table->decimal('minimum_reusable_length_mm', 12, 2)->nullable()->after('is_project_chargeable');
            $table->decimal('minimum_reusable_width_mm', 12, 2)->nullable()->after('minimum_reusable_length_mm');
            $table->decimal('minimum_reusable_area_m2', 12, 4)->nullable()->after('minimum_reusable_width_mm');
        });

        // Preserve current behaviour during rollout. Existing board instances are
        // authoritative; category names are used only as a one-time compatibility backfill.
        DB::table('library_materials')->whereNull('issue_disposition')->update([
            'item_status' => DB::raw("CASE WHEN is_active = 1 THEN 'Active' ELSE 'Inactive' END"),
            'issue_disposition' => DB::raw("CASE WHEN material_type = 'reusable' THEN 'returnable' ELSE 'consumed' END"),
            'tracking_mode' => 'bulk_quantity',
        ]);

        if (Schema::hasTable('boards')) {
            DB::statement("UPDATE library_materials lm
                INNER JOIN (SELECT DISTINCT library_material_id FROM boards) b
                    ON b.library_material_id = lm.id
                SET lm.issue_disposition = 'recoverable_remainder',
                    lm.tracking_mode = 'dimension_piece',
                    lm.material_type = 'reusable'");
        }

        Schema::table('material_categories', function (Blueprint $table) {
            $table->boolean('is_selectable')->default(true)->after('is_active');
            $table->string('default_issue_disposition', 30)->nullable()->after('is_selectable');
            $table->string('default_tracking_mode', 30)->nullable()->after('default_issue_disposition');
            $table->json('allowed_uoms')->nullable()->after('default_tracking_mode');
            $table->json('required_attributes')->nullable()->after('allowed_uoms');
        });

        DB::table('material_categories')->whereNull('parent_id')->update(['is_selectable' => false]);
    }

    public function down(): void
    {
        Schema::table('material_categories', function (Blueprint $table) {
            $table->dropColumn([
                'is_selectable', 'default_issue_disposition', 'default_tracking_mode',
                'allowed_uoms', 'required_attributes',
            ]);
        });

        Schema::table('library_materials', function (Blueprint $table) {
            $table->dropColumn([
                'item_status', 'issue_disposition', 'tracking_mode', 'is_hazardous',
                'is_serialized', 'is_batch_controlled', 'is_expiry_controlled',
                'is_project_chargeable', 'minimum_reusable_length_mm',
                'minimum_reusable_width_mm', 'minimum_reusable_area_m2',
            ]);
        });
    }
};
