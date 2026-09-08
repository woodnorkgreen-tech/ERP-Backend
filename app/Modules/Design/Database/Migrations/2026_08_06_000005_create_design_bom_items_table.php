<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_item_id')->constrained('design_items')->cascadeOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('library_materials')->nullOnDelete();
            $table->string('description');
            $table->text('specification')->nullable();
            $table->decimal('quantity', 15, 3)->default(1);
            $table->string('unit')->default('pcs');
            $table->decimal('wastage_percent', 5, 2)->nullable();
            $table->enum('source', ['designer', 'production', 'agreed'])->default('designer');
            $table->enum('status', ['draft', 'production_review', 'approved'])->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['design_item_id', 'status']);
            $table->index('material_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_bom_items');
    }
};
