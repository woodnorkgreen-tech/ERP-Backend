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
        Schema::create('library_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_id')->constrained('workstations')->onDelete('cascade');
            $table->string('material_code', 100)->unique()->comment('SKU Code - unique identifier');
            $table->string('material_name')->comment('Material/Item Name');
            $table->string('category', 100)->nullable()->comment('Main category');
            $table->string('subcategory', 100)->nullable()->comment('Sub-category');
            $table->string('unit_of_measure', 50)->comment('sqm, roll, meter, piece, liter, etc.');
            $table->decimal('unit_cost', 15, 2)->default(0.00)->comment('Cost per unit of measure');
            $table->json('attributes')->nullable()->comment('Dynamic fields stored as JSON');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable()->comment('General notes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            
            // Indexes
            $table->index('workstation_id');
            $table->index('material_code');
            $table->index('category');
            $table->index('is_active');
            $table->index('created_at');
            
            // Full-text search index (for MySQL)
            // $table->fullText(['material_name', 'category', 'subcategory']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('library_materials');
    }
};
