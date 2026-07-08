<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_note_items', function (Blueprint $table) {
            if (!Schema::hasColumn('goods_receipt_note_items', 'material_id')) {
                $table->unsignedBigInteger('material_id')->nullable()->after('purchase_order_item_id');
                return;
            }

            $table->unsignedBigInteger('material_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_note_items', function (Blueprint $table) {
            if (Schema::hasColumn('goods_receipt_note_items', 'material_id')) {
                $table->unsignedBigInteger('material_id')->nullable(false)->change();
            }
        });
    }
};
