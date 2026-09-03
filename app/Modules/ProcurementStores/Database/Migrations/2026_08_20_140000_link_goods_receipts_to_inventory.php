<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE purchase_order_items MODIFY quantity DECIMAL(18,6) NOT NULL');
        DB::statement('ALTER TABLE goods_receipt_note_items MODIFY ordered_quantity DECIMAL(18,6) NOT NULL');
        DB::statement('ALTER TABLE goods_receipt_note_items MODIFY received_quantity DECIMAL(18,6) NOT NULL');

        Schema::table('goods_receipt_note_items', function (Blueprint $table) {
            $table->unsignedBigInteger('entered_uom_id')->nullable()->after('received_quantity');
            $table->decimal('stock_quantity', 18, 6)->nullable()->after('entered_uom_id');
            $table->decimal('receipt_unit_cost', 18, 4)->nullable()->after('stock_quantity');
            $table->string('stock_status', 40)->default('not_received')->after('receipt_unit_cost');
            $table->unsignedBigInteger('inventory_log_id')->nullable()->after('stock_status');
            $table->foreign('entered_uom_id', 'grn_items_entered_uom_fk')->references('id')->on('units_of_measure')->nullOnDelete();
            $table->foreign('inventory_log_id', 'grn_items_inventory_log_fk')->references('id')->on('inventory_logs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_note_items', function (Blueprint $table) {
            $table->dropForeign('grn_items_entered_uom_fk');
            $table->dropForeign('grn_items_inventory_log_fk');
            $table->dropColumn(['entered_uom_id', 'stock_quantity', 'receipt_unit_cost', 'stock_status', 'inventory_log_id']);
        });
    }
};
