<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditMaterialInventorySync extends Command
{
    protected $signature = 'inventory:audit-material-sync';

    protected $description = 'Report drift between governed material master data and physical inventory projections';

    public function handle(): int
    {
        $checks = [
            'Category projection mismatches' => DB::table('library_materials as lm')
                ->join('material_categories as c', 'c.id', '=', 'lm.material_category_id')
                ->leftJoin('material_categories as p', 'p.id', '=', 'c.parent_id')
                ->whereNull('lm.deleted_at')
                ->whereRaw('(lm.category <> COALESCE(p.name, c.name) OR NOT (lm.subcategory <=> CASE WHEN p.id IS NULL THEN NULL ELSE c.name END))')
                ->count(),
            'UOM projection mismatches' => DB::table('library_materials as lm')
                ->join('units_of_measure as u', 'u.id', '=', 'lm.base_uom_id')
                ->whereNull('lm.deleted_at')
                ->whereColumn('lm.unit_of_measure', '<>', 'u.code')
                ->count(),
            'Stock tracking mismatches' => DB::table('library_materials as lm')
                ->join('stocks as s', 's.material_id', '=', 'lm.id')
                ->whereNull('lm.deleted_at')->whereNull('s.deleted_at')
                ->whereRaw("s.tracking_mode <> CASE WHEN lm.tracking_mode = 'dimension_piece' AND lm.issue_disposition = 'recoverable_remainder' THEN 'individual' ELSE 'count' END")
                ->count(),
            'Duplicate active stock rows' => DB::query()->fromSub(
                DB::table('stocks')->select('material_id')->whereNull('deleted_at')
                    ->groupBy('material_id')->havingRaw('COUNT(*) > 1'),
                'duplicates'
            )->count(),
            'Board physical-balance mismatches' => DB::query()->fromSub(
                DB::table('boards as b')
                    ->leftJoin('stocks as s', function ($join) {
                        $join->on('s.material_id', '=', 'b.library_material_id')->whereNull('s.deleted_at');
                    })
                    ->select('b.library_material_id')
                    ->groupBy('b.library_material_id', 's.quantity_on_hand')
                    ->havingRaw("COALESCE(s.quantity_on_hand, 0) <> SUM(CASE WHEN b.status IN ('Available', 'Quarantine') THEN 1 ELSE 0 END)"),
                'board_drift'
            )->count(),
        ];

        $this->table(['Consistency check', 'Exceptions'], collect($checks)
            ->map(fn ($count, $name) => [$name, $count])->values()->all());

        $exceptions = array_sum($checks);
        $this->newLine();
        $exceptions === 0
            ? $this->info('Material Library and Store Inventory projections are aligned.')
            : $this->warn("{$exceptions} synchronization exception(s) require attention.");

        return $exceptions === 0 ? self::SUCCESS : self::FAILURE;
    }
}
