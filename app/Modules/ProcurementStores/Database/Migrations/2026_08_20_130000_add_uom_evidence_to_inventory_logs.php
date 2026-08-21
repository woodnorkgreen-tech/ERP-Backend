<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->decimal('entered_quantity', 18, 6)->nullable()->after('quantity');
            $table->unsignedBigInteger('entered_uom_id')->nullable()->after('entered_quantity');
            $table->decimal('uom_conversion_factor', 18, 6)->nullable()->after('entered_uom_id');
            $table->foreign('entered_uom_id', 'inventory_logs_entered_uom_fk')->references('id')->on('units_of_measure')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropForeign('inventory_logs_entered_uom_fk');
            $table->dropColumn(['entered_quantity', 'entered_uom_id', 'uom_conversion_factor']);
        });
    }
};
