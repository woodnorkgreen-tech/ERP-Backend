<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->string('lot_number', 100)->nullable()->after('batch_number')->index();
            $table->date('expiry_date')->nullable()->after('lot_number')->index();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropIndex(['expiry_date']);
            $table->dropIndex(['lot_number']);
            $table->dropColumn(['lot_number', 'expiry_date']);
        });
    }
};
