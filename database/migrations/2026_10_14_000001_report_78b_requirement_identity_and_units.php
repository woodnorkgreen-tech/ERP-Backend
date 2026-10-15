<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report 78B — a BOM requirement's material identity and unit basis.
 *
 * Purely additive. The BOM's own description, unit text and quantity are never
 * rewritten; these columns record how Stores reads the requirement:
 *
 *  - requirement_disposition: an explicit Stores classification for a line
 *    with no library identity — 'non_stock' (a direct purchase that must never
 *    become a fictitious inventory item) or 'awaiting_registration' (a new
 *    material has been requested and is waiting for the library custodian).
 *    NULL means "derive it": resolved when library_material_id is set,
 *    awaiting resolution otherwise.
 *  - requested_material_id: the Under Review library item a new-material
 *    request created. It is NOT the line's identity until it is Active and the
 *    line is resolved to it.
 *  - requirement_uom_id / requirement_base_uom_id / requirement_base_factor /
 *    requirement_unit_basis: the approved reading of the BOM unit in the
 *    material's stock unit, captured at resolution. Requirement in stock units
 *    = quantity × factor. Cleared whenever the BOM unit or identity changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('element_materials', function (Blueprint $table) {
            if (! Schema::hasColumn('element_materials', 'requirement_disposition')) {
                $table->string('requirement_disposition', 30)->nullable()->after('library_material_id');
            }
            if (! Schema::hasColumn('element_materials', 'requested_material_id')) {
                $table->foreignId('requested_material_id')->nullable()->after('requirement_disposition')
                    ->constrained('library_materials')->nullOnDelete();
            }
            if (! Schema::hasColumn('element_materials', 'requirement_uom_id')) {
                $table->foreignId('requirement_uom_id')->nullable()->after('unit_of_measurement')
                    ->constrained('units_of_measure')->nullOnDelete();
            }
            if (! Schema::hasColumn('element_materials', 'requirement_base_uom_id')) {
                $table->foreignId('requirement_base_uom_id')->nullable()->after('requirement_uom_id')
                    ->constrained('units_of_measure')->nullOnDelete();
            }
            if (! Schema::hasColumn('element_materials', 'requirement_base_factor')) {
                $table->decimal('requirement_base_factor', 18, 6)->nullable()->after('requirement_base_uom_id');
            }
            if (! Schema::hasColumn('element_materials', 'requirement_unit_basis')) {
                $table->json('requirement_unit_basis')->nullable()->after('requirement_base_factor');
            }
        });
    }

    public function down(): void
    {
        Schema::table('element_materials', function (Blueprint $table) {
            foreach (['requested_material_id', 'requirement_uom_id', 'requirement_base_uom_id'] as $column) {
                if (Schema::hasColumn('element_materials', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
            foreach (['requirement_disposition', 'requirement_base_factor', 'requirement_unit_basis'] as $column) {
                if (Schema::hasColumn('element_materials', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
