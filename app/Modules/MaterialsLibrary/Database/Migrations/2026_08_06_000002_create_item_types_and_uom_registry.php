<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_item_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('default_issue_disposition', 30)->nullable();
            $table->string('default_tracking_mode', 30)->nullable();
            $table->boolean('is_stock_item')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->string('dimension', 30); // count, length, area, volume, mass, package
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('allows_fraction')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('material_item_types')->insert([
            ['code' => 'STOCK', 'name' => 'Stock Material', 'description' => 'Inventory material or component consumed or incorporated into work.', 'default_issue_disposition' => 'consumed', 'default_tracking_mode' => 'bulk_quantity', 'is_stock_item' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'TOOL', 'name' => 'Tooling', 'description' => 'Returnable production tooling with optional individual control.', 'default_issue_disposition' => 'returnable', 'default_tracking_mode' => 'serialized_item', 'is_stock_item' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'EQUIP', 'name' => 'Controlled Equipment', 'description' => 'Reusable operational equipment not automatically capitalized.', 'default_issue_disposition' => 'returnable', 'default_tracking_mode' => 'serialized_item', 'is_stock_item' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'ASSET', 'name' => 'Asset Model', 'description' => 'Purchasable model whose receipt can create controlled asset instances.', 'default_issue_disposition' => 'returnable', 'default_tracking_mode' => 'serialized_item', 'is_stock_item' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'NONSTOCK', 'name' => 'Non-stock Item', 'description' => 'Direct-charge item not normally held in inventory.', 'default_issue_disposition' => 'consumed', 'default_tracking_mode' => 'bulk_quantity', 'is_stock_item' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'SERVICE', 'name' => 'Service', 'description' => 'Procured service with no physical stock balance.', 'default_issue_disposition' => 'consumed', 'default_tracking_mode' => 'bulk_quantity', 'is_stock_item' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('units_of_measure')->insert([
            ['code' => 'pcs', 'name' => 'Piece', 'dimension' => 'count', 'decimal_places' => 0, 'allows_fraction' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'sheet', 'name' => 'Sheet', 'dimension' => 'count', 'decimal_places' => 0, 'allows_fraction' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'roll', 'name' => 'Roll', 'dimension' => 'package', 'decimal_places' => 0, 'allows_fraction' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'box', 'name' => 'Box', 'dimension' => 'package', 'decimal_places' => 0, 'allows_fraction' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'set', 'name' => 'Set', 'dimension' => 'package', 'decimal_places' => 0, 'allows_fraction' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'm', 'name' => 'Metre', 'dimension' => 'length', 'decimal_places' => 3, 'allows_fraction' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'mm', 'name' => 'Millimetre', 'dimension' => 'length', 'decimal_places' => 2, 'allows_fraction' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'm2', 'name' => 'Square metre', 'dimension' => 'area', 'decimal_places' => 4, 'allows_fraction' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'l', 'name' => 'Litre', 'dimension' => 'volume', 'decimal_places' => 3, 'allows_fraction' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'ml', 'name' => 'Millilitre', 'dimension' => 'volume', 'decimal_places' => 2, 'allows_fraction' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'kg', 'name' => 'Kilogram', 'dimension' => 'mass', 'decimal_places' => 3, 'allows_fraction' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'g', 'name' => 'Gram', 'dimension' => 'mass', 'decimal_places' => 2, 'allows_fraction' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('material_categories', function (Blueprint $table) {
            $table->foreignId('item_type_id')->nullable()->after('id')
                ->constrained('material_item_types')->nullOnDelete();
        });

        $stockType = DB::table('material_item_types')->where('code', 'STOCK')->value('id');
        $toolType = DB::table('material_item_types')->where('code', 'TOOL')->value('id');
        DB::table('material_categories')->update(['item_type_id' => $stockType]);
        $toolRootId = DB::table('material_categories')->where('name', 'Cutting Tools')->whereNull('parent_id')->value('id');
        if ($toolRootId) {
            DB::table('material_categories')->where(function ($query) use ($toolRootId) {
                $query->where('id', $toolRootId)->orWhere('parent_id', $toolRootId);
            })->update(['item_type_id' => $toolType]);
        }

        Schema::table('library_materials', function (Blueprint $table) {
            $table->foreignId('item_type_id')->nullable()->after('workstation_id')
                ->constrained('material_item_types')->nullOnDelete();
            $table->foreignId('base_uom_id')->nullable()->after('unit_of_measure')
                ->constrained('units_of_measure')->nullOnDelete();
            $table->foreignId('purchase_uom_id')->nullable()->after('base_uom_id')
                ->constrained('units_of_measure')->nullOnDelete();
            $table->foreignId('issue_uom_id')->nullable()->after('purchase_uom_id')
                ->constrained('units_of_measure')->nullOnDelete();
            $table->string('brand_manufacturer', 150)->nullable()->after('material_name');
            $table->string('manufacturer_part_number', 150)->nullable()->after('brand_manufacturer');
            $table->string('alternative_item_name', 255)->nullable()->after('manufacturer_part_number');
            $table->string('valuation_method', 30)->default('Weighted Average')->after('unit_cost');
            $table->string('revision_version', 20)->default('1.0')->after('valuation_method');
            $table->date('effective_date')->nullable()->after('revision_version');
            $table->foreignId('approved_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approval_date')->nullable()->after('approved_by');
        });

        Schema::create('material_uom_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('library_materials')->cascadeOnDelete();
            $table->foreignId('from_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->foreignId('to_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('factor', 18, 6);
            $table->timestamps();
            $table->unique(['material_id', 'from_uom_id', 'to_uom_id'], 'material_uom_conversion_unique');
        });

        Schema::create('material_workstations', function (Blueprint $table) {
            $table->foreignId('material_id')->constrained('library_materials')->cascadeOnDelete();
            $table->foreignId('workstation_id')->constrained('workstations')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->primary(['material_id', 'workstation_id']);
        });

        $stockTypeId = DB::table('material_item_types')->where('code', 'STOCK')->value('id');
        $toolTypeId = DB::table('material_item_types')->where('code', 'TOOL')->value('id');
        DB::table('library_materials')->update(['item_type_id' => $stockTypeId]);
        DB::table('library_materials')->where('material_type', 'reusable')
            ->where('tracking_mode', '!=', 'dimension_piece')->update(['item_type_id' => $toolTypeId]);

        $uomAliases = [
            'pcs' => ['pcs', 'pc', 'piece', 'pieces'], 'sheet' => ['sheet', 'sheets'],
            'roll' => ['roll', 'rolls'], 'box' => ['box', 'boxes'], 'set' => ['set', 'sets'],
            'm' => ['m', 'mtr', 'mtrs', 'metre', 'meter', 'metres', 'meters'],
            'mm' => ['mm'], 'm2' => ['sqm', 'm2', 'sq m'], 'l' => ['l', 'ltr', 'ltrs', 'litre', 'liter'],
            'ml' => ['ml'], 'kg' => ['kg', 'kgs'], 'g' => ['g', 'gram', 'grams'],
        ];
        foreach ($uomAliases as $code => $aliases) {
            $uomId = DB::table('units_of_measure')->where('code', $code)->value('id');
            DB::table('library_materials')->whereIn(DB::raw('LOWER(TRIM(unit_of_measure))'), $aliases)
                ->update(['base_uom_id' => $uomId, 'issue_uom_id' => $uomId]);
        }

        // Retain the current single workstation as the primary relationship while
        // enabling shared materials without duplicate SKUs.
        DB::statement('INSERT INTO material_workstations (material_id, workstation_id, is_primary, created_at, updated_at)
            SELECT id, workstation_id, 1, NOW(), NOW() FROM library_materials');
    }

    public function down(): void
    {
        Schema::dropIfExists('material_workstations');
        Schema::dropIfExists('material_uom_conversions');
        Schema::table('material_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_type_id');
        });
        Schema::table('library_materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('issue_uom_id');
            $table->dropConstrainedForeignId('purchase_uom_id');
            $table->dropConstrainedForeignId('base_uom_id');
            $table->dropConstrainedForeignId('item_type_id');
            $table->dropColumn([
                'brand_manufacturer', 'manufacturer_part_number', 'alternative_item_name',
                'valuation_method', 'revision_version', 'effective_date', 'approval_date',
            ]);
        });
        Schema::dropIfExists('units_of_measure');
        Schema::dropIfExists('material_item_types');
    }
};
