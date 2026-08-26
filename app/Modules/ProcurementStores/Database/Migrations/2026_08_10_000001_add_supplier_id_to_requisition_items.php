<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            if (!Schema::hasColumn('requisition_items', 'supplier_id')) {
                $table->unsignedBigInteger('supplier_id')->nullable()->after('material_id')->index();
                $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('set null');
            }
        });
    }

    public function down(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            if (Schema::hasColumn('requisition_items', 'supplier_id')) {
                $table->dropForeign(['supplier_id']);
                $table->dropColumn('supplier_id');
            }
        });
    }
};