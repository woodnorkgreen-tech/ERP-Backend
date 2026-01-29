<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_note_items', function (Blueprint $table) {
            // Drop the foreign key constraint if it exists
            try {
                $table->dropForeign(['material_id']);
            } catch (\Illuminate\Database\QueryException $e) {
                // Constraint might not exist, continue
                // Check for MySQL error 1091 (Can't DROP X; check that it exists)
                if ((isset($e->errorInfo[1]) && $e->errorInfo[1] == 1091) || str_contains($e->getMessage(), '1091 Can\'t DROP FOREIGN KEY')) {
                   return;
                }
                throw $e;
            }
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