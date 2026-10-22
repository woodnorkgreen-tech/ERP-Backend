<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of each stock-unit change: which unit a material was counted in,
 * which it is counted in now, the factor every quantity was restated by, and
 * who did it. Movement history keeps its original entry; this row explains why
 * its stock-unit quantity reads differently from what was typed at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_stock_unit_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('library_materials')->cascadeOnDelete();
            $table->foreignId('from_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->foreignId('to_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('factor', 18, 6)->comment('new units in one old unit');
            $table->decimal('on_hand_before', 18, 6)->default(0);
            $table->decimal('on_hand_after', 18, 6)->default(0);
            $table->decimal('stock_value_before', 18, 2)->default(0);
            $table->decimal('stock_value_after', 18, 2)->default(0)->comment('differs only by rounding the average cost to cents');
            $table->json('restated')->nullable()->comment('rows restated per table');
            $table->string('reason', 500);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_stock_unit_changes');
    }
};
