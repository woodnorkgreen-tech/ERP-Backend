<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE requisition_items MODIFY quantity DECIMAL(18,6) NOT NULL');

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->unsignedBigInteger('uom_id')->nullable()->after('quantity');
            $table->foreign('uom_id', 'requisition_items_uom_fk')->references('id')->on('units_of_measure')->nullOnDelete();
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('uom_id')->nullable()->after('quantity');
            $table->foreign('uom_id', 'purchase_order_items_uom_fk')->references('id')->on('units_of_measure')->nullOnDelete();
        });

        DB::statement('UPDATE requisition_items ri JOIN library_materials lm ON lm.id = ri.material_id SET ri.uom_id = COALESCE(lm.purchase_uom_id, lm.base_uom_id) WHERE ri.uom_id IS NULL');
        DB::statement('UPDATE purchase_order_items poi LEFT JOIN requisition_items ri ON ri.id = poi.requisition_item_id JOIN library_materials lm ON lm.id = poi.material_id SET poi.uom_id = COALESCE(ri.uom_id, lm.purchase_uom_id, lm.base_uom_id) WHERE poi.uom_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropForeign('purchase_order_items_uom_fk');
            $table->dropColumn('uom_id');
        });
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropForeign('requisition_items_uom_fk');
            $table->dropColumn('uom_id');
        });
    }
};
