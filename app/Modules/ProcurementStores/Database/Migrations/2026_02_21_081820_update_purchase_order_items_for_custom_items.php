<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            // Make material_id nullable to support custom (non-library) items
            // created from task-based requisitions
            $table->unsignedBigInteger('material_id')->nullable()->change();

            // Add description column for custom items that have no library material
            $table->text('custom_description')->nullable()->after('material_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('custom_description');
            $table->unsignedBigInteger('material_id')->nullable(false)->change();
        });
    }
};
