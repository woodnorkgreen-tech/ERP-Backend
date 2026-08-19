<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('library_materials')->restrictOnDelete();
            $table->string('lot_number', 100);
            $table->date('expiry_date')->nullable()->index();
            $table->string('status', 20)->default('Released')->index();
            $table->string('warehouse_code', 20)->default('MAIN');
            $table->string('location_bin', 100)->default('UNASSIGNED');
            $table->decimal('quantity_on_hand', 15, 4)->default(0);
            $table->decimal('quantity_reserved', 15, 4)->default(0);
            $table->timestamps();
            $table->unique(['material_id', 'lot_number', 'warehouse_code', 'location_bin'], 'inventory_lot_location_unique');
        });

        Schema::create('inventory_serial_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('library_materials')->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->string('tracking_code', 100)->unique();
            $table->string('manufacturer_serial', 150)->nullable()->index();
            $table->string('status', 30)->default('Available')->index();
            $table->string('condition_grade', 50)->default('Good');
            $table->string('warehouse_code', 20)->default('MAIN');
            $table->string('location_bin', 100)->default('UNASSIGNED');
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('holder_name', 150)->nullable();
            $table->timestamps();
            $table->unique(['material_id', 'manufacturer_serial'], 'inventory_serial_material_unique');
        });

        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->foreignId('inventory_lot_id')->nullable()->after('expiry_date')->constrained('inventory_lots')->nullOnDelete();
            $table->foreignId('inventory_serial_item_id')->nullable()->after('inventory_lot_id')->constrained('inventory_serial_items')->nullOnDelete();
        });

        Schema::create('inventory_movement_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_log_id')->constrained('inventory_logs')->cascadeOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->foreignId('inventory_serial_item_id')->nullable()->constrained('inventory_serial_items')->nullOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movement_allocations');
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_serial_item_id');
            $table->dropConstrainedForeignId('inventory_lot_id');
        });
        Schema::dropIfExists('inventory_serial_items');
        Schema::dropIfExists('inventory_lots');
    }
};
