<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A catalogue row is a name for a thing, and neither of these columns is part
 * of what a thing is.
 *
 * `workstation_id` says which machine consumes the material — routing, not
 * identity. The same vinyl runs on two printers and a material used at no
 * workstation is still a material. A many-to-many already exists in
 * material_workstations for the cases that need it.
 *
 * `unit_of_measure` is a legacy string projection of base_uom_id, kept in sync
 * by syncUomCompatibility(). A draft that has not yet chosen a unit has nothing
 * truthful to put here, and '' is a lie dressed as a value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_materials', function (Blueprint $table) {
            $table->unsignedBigInteger('workstation_id')->nullable()->change();
            $table->string('unit_of_measure')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Restoring NOT NULL needs every row to hold a value, so fill the gaps
        // that draft-first saving is allowed to leave behind.
        $fallbackWorkstation = DB::table('workstations')->orderBy('id')->value('id');
        if ($fallbackWorkstation) {
            DB::table('library_materials')->whereNull('workstation_id')
                ->update(['workstation_id' => $fallbackWorkstation]);
        }
        DB::table('library_materials')->whereNull('unit_of_measure')->update(['unit_of_measure' => '-']);

        Schema::table('library_materials', function (Blueprint $table) {
            $table->unsignedBigInteger('workstation_id')->nullable(false)->change();
            $table->string('unit_of_measure')->nullable(false)->change();
        });
    }
};
