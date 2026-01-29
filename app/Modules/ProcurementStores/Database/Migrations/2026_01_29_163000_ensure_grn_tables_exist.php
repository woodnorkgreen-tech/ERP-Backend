<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rescue logic: Ensure tables exist if previous migrations were marked as run but tables are missing
        if (!Schema::hasTable('goods_receipt_notes')) {
            Schema::create('goods_receipt_notes', function (Blueprint $table) {
                $table->id();
                $table->string('grn_number')->unique();
                $table->date('date');
                $table->foreignId('purchase_order_id')->constrained()->onDelete('cascade');
                $table->string('batch_number');
                $table->string('store_location');
                $table->enum('quality_check', ['pass', 'fail']);
                $table->foreignId('received_by')->constrained('users')->onDelete('cascade');
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('goods_receipt_note_items')) {
            Schema::create('goods_receipt_note_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('goods_receipt_note_id')->constrained()->onDelete('cascade');
                $table->foreignId('purchase_order_item_id')->constrained()->onDelete('cascade');
                $table->foreignId('material_id')->constrained()->onDelete('cascade');
                $table->integer('ordered_quantity');
                $table->integer('received_quantity');
                $table->enum('condition', ['good', 'fair', 'damaged', 'for_repair']);
                $table->boolean('accepted')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // No-op for safety, avoid dropping if we are just ensuring existence
    }
};
