<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A price the catalogue can be told, kept apart from the price it works out.
 *
 * `unit_cost` is derived: InventoryService recomputes it as a weighted average
 * of what deliveries actually cost. Letting a person type into that column
 * would put a guess and a receipt in the same field, and afterwards nothing
 * could tell them apart — the next receipt would average against the guess as
 * though it were money that had been spent.
 *
 * `default_unit_cost` is what this material is expected to cost when nothing
 * better is known: a list price, a standing supplier rate, a figure someone
 * has to put in so stock can be received at all. It is only ever read as a
 * fallback, and never feeds the weighted average.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_materials', function (Blueprint $table) {
            // Nullable, not zero-defaulted: "nobody has said" and "it is free"
            // are different answers, and only the first should send a receipt
            // looking for a price.
            $table->decimal('default_unit_cost', 15, 2)->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('library_materials', function (Blueprint $table) {
            $table->dropColumn('default_unit_cost');
        });
    }
};
