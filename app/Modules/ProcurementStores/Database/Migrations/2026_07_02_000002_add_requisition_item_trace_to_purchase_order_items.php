<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_order_items', 'requisition_item_id')) {
                $table->unsignedBigInteger('requisition_item_id')->nullable()->after('purchase_order_id');
                $table->index('requisition_item_id', 'poi_requisition_item_id_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_order_items', 'requisition_item_id')) {
                $table->dropIndex('poi_requisition_item_id_index');
                $table->dropColumn('requisition_item_id');
            }
        });
    }
};
