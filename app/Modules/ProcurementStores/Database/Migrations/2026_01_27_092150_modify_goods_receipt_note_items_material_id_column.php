<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_note_items', function (Blueprint $table) {
            // Drop the foreign key constraint
            $table->dropForeign(['material_id']);
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_note_items', function (Blueprint $table) {
            // Restore the foreign key
            $table->foreign('material_id')->references('id')->on('materials')->onDelete('cascade');
        });
    }
};