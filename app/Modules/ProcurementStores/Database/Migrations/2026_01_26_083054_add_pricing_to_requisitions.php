<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add fields to requisition_items
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->decimal('unit_price', 15, 2)->default(0)->after('quantity');
            $table->decimal('total', 15, 2)->default(0)->after('unit_price');
        });

        // Add field to requisitions
        Schema::table('requisitions', function (Blueprint $table) {
            $table->decimal('total_amount', 15, 2)->default(0)->after('urgency');
        });
    }

    public function down(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'total']);
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn('total_amount');
        });
    }
};