<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 65 materials carry a unit the registry never held — PACK, CAN, CARTRIDGE,
 * BOTTLE, CYL — so their base_uom_id could never be resolved and they could
 * never become complete. These are ordinary packaging units, not bad data:
 * the registry was simply short.
 */
return new class extends Migration
{
    private const UNITS = [
        ['code' => 'pack', 'name' => 'Pack', 'dimension' => 'package'],
        ['code' => 'can', 'name' => 'Can', 'dimension' => 'package'],
        ['code' => 'bottle', 'name' => 'Bottle', 'dimension' => 'package'],
        ['code' => 'cartridge', 'name' => 'Cartridge', 'dimension' => 'package'],
        ['code' => 'cyl', 'name' => 'Cylinder', 'dimension' => 'package'],
    ];

    public function up(): void
    {
        foreach (self::UNITS as $unit) {
            DB::table('units_of_measure')->updateOrInsert(
                ['code' => $unit['code']],
                $unit + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        // Only remove units nothing has adopted, so a rollback cannot orphan a
        // material's base unit.
        DB::table('units_of_measure')
            ->whereIn('code', array_column(self::UNITS, 'code'))
            ->whereNotIn('id', function ($query) {
                $query->select('base_uom_id')->from('library_materials')->whereNotNull('base_uom_id');
            })
            ->delete();
    }
};
