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
        Schema::create('inventory_logs', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->unsignedBigInteger('material_id');
            $blueprint->unsignedBigInteger('user_id'); // Who did the action
            
            // Movement Details
            $blueprint->enum('type', ['check_in', 'check_out', 'return', 'adjustment', 'defective', 'allocated']);
            $blueprint->decimal('quantity', 15, 2);
            $blueprint->decimal('balance_after', 15, 2);
            
            // Optional links to other domains
            $blueprint->unsignedBigInteger('project_id')->nullable();
            $blueprint->unsignedBigInteger('supplier_id')->nullable(); // For GRNs
            $blueprint->string('reference_no')->nullable(); // LPO No, GRN No, Issue Note No
            
            $blueprint->text('notes')->nullable();
            $blueprint->timestamps();

            // Foreign Keys
            $blueprint->foreign('material_id')->references('id')->on('library_materials')->onDelete('cascade');
            $blueprint->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_logs');
    }
};
