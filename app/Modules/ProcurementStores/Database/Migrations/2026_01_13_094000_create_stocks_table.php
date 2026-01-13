<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $blueprint) {
            $blueprint->id();
            // Link to the master library
            $blueprint->unsignedBigInteger('material_id');
            
            // Quantity Tracking
            $blueprint->decimal('quantity_on_hand', 15, 2)->default(0);
            $blueprint->decimal('quantity_reserved', 15, 2)->default(0); // For booked projects
            $blueprint->decimal('min_stock_level', 15, 2)->default(0); // For alerts
            
            // Physical Location
            $blueprint->string('warehouse_code')->default('MAIN');
            $blueprint->string('location_bin')->nullable(); // Shelf/Bin No
            
            // Metadata
            $blueprint->timestamps();
            $blueprint->softDeletes();

            // Foreign Key
            $blueprint->foreign('material_id')
                  ->references('id')
                  ->on('library_materials')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
