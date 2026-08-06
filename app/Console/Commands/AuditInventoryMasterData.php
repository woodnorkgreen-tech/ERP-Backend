<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AuditInventoryMasterData extends Command
{
    protected $signature = 'inventory:audit-master-data
        {--output= : Output directory; defaults to storage/app/inventory-audits/<timestamp>}
        {--include-transactions : Export transaction-level inventory logs in addition to summaries}';

    protected $description = 'Read-only export and quality audit of the current material, stock, board and asset master data';

    public function handle(): int
    {
        foreach (['library_materials', 'material_categories'] as $requiredTable) {
            if (!Schema::hasTable($requiredTable)) {
                $this->error("Required table [{$requiredTable}] does not exist on the configured database.");
                return self::FAILURE;
            }
        }

        $output = $this->option('output')
            ?: storage_path('app/inventory-audits/'.now()->format('Ymd_His'));
        $output = rtrim((string) $output, DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($output);

        $categories = DB::table('material_categories as child')
            ->leftJoin('material_categories as parent', 'parent.id', '=', 'child.parent_id')
            ->select([
                'child.id', 'child.code', 'child.name', 'child.parent_id',
                'parent.code as parent_code', 'parent.name as parent_name',
                'child.is_active', 'child.deleted_at',
            ])
            ->orderBy('parent.name')
            ->orderBy('child.name')
            ->get();

        $workstations = Schema::hasTable('workstations')
            ? DB::table('workstations')->select('id', 'code', 'name')->get()->keyBy('id')
            : collect();
        $categoryById = $categories->keyBy('id');

        $stockSummary = Schema::hasTable('stocks')
            ? DB::table('stocks')
                ->whereNull('deleted_at')
                ->selectRaw('material_id, SUM(quantity_on_hand) quantity_on_hand, SUM(quantity_reserved) quantity_reserved, MAX(min_stock_level) min_stock_level, GROUP_CONCAT(DISTINCT warehouse_code ORDER BY warehouse_code) warehouses, GROUP_CONCAT(DISTINCT location_bin ORDER BY location_bin) bins, COUNT(*) stock_rows')
                ->groupBy('material_id')->get()->keyBy('material_id')
            : collect();

        $movementSummary = Schema::hasTable('inventory_logs')
            ? DB::table('inventory_logs')
                ->selectRaw("material_id, COUNT(*) movement_count, SUM(CASE WHEN type = 'check_in' THEN quantity ELSE 0 END) received_qty, SUM(CASE WHEN type IN ('check_out', 'allocated') THEN quantity ELSE 0 END) issued_qty, SUM(CASE WHEN type = 'return' THEN quantity ELSE 0 END) returned_qty, SUM(CASE WHEN usage_type = 'reusable' THEN 1 ELSE 0 END) reusable_movements")
                ->groupBy('material_id')->get()->keyBy('material_id')
            : collect();

        $boardSummary = Schema::hasTable('boards')
            ? DB::table('boards')
                ->selectRaw('library_material_id, COUNT(*) board_count, SUM(CASE WHEN is_offcut = 1 THEN 1 ELSE 0 END) offcut_count, SUM(area_m2) tracked_area_m2, SUM(current_value) tracked_value')
                ->groupBy('library_material_id')->get()->keyBy('library_material_id')
            : collect();

        $materials = DB::table('library_materials')->orderBy('id')->get();
        $rows = [];
        $issues = [];
        $counts = [
            'materials' => $materials->count(), 'active_materials' => 0, 'missing_name' => 0,
            'missing_category_fk' => 0, 'category_mismatch' => 0, 'invalid_uom' => 0,
            'missing_type' => 0, 'suspected_board_without_dimensions' => 0,
            'reusable_without_tracking_evidence' => 0,
        ];

        foreach ($materials as $material) {
            $category = $categoryById->get($material->material_category_id);
            $stock = $stockSummary->get($material->id);
            $movements = $movementSummary->get($material->id);
            $boards = $boardSummary->get($material->id);
            $attributes = $this->attributes($material->attributes ?? null);
            $rootName = $category?->parent_name ?: $category?->name ?: $material->category;
            $leafName = $category?->parent_name ? $category?->name : null;
            $isBoardFamily = in_array($rootName, ['Boards', 'Sheet Materials', 'Veneer'], true);
            $hasDimensions = $this->hasAny($attributes, [
                'thickness_mm', 'thickness', 'thickness_size',
            ]) && ($this->hasAny($attributes, ['standard_length_mm', 'length', 'sheet_size', 'sheet_size_dimensions'])
                || (int) ($boards?->board_count ?? 0) > 0);

            $rowIssues = [];
            if (trim((string) $material->material_name) === '') $rowIssues[] = 'MISSING_NAME';
            if (!$material->material_category_id) $rowIssues[] = 'MISSING_CATEGORY_FK';
            if (trim((string) $material->unit_of_measure) === '' || trim((string) $material->unit_of_measure) === '-') $rowIssues[] = 'INVALID_UOM';
            if (!$material->material_type) $rowIssues[] = 'MISSING_MATERIAL_TYPE';
            if ($category && ($this->normalized($material->category) !== $this->normalized($rootName)
                || $this->normalized($material->subcategory) !== $this->normalized($leafName))) {
                $rowIssues[] = 'CATEGORY_STRING_FK_MISMATCH';
            }
            if ($isBoardFamily && !$hasDimensions) $rowIssues[] = 'BOARD_FAMILY_MISSING_DIMENSIONS';
            if ($material->material_type === 'reusable'
                && (int) ($boards?->board_count ?? 0) === 0
                && (int) ($movements?->reusable_movements ?? 0) === 0) {
                $rowIssues[] = 'REUSABLE_WITHOUT_TRACKING_EVIDENCE';
            }

            foreach ($rowIssues as $issue) {
                $issues[] = [
                    'material_id' => $material->id,
                    'material_code' => $material->material_code,
                    'material_name' => $material->material_name,
                    'issue_code' => $issue,
                ];
            }

            if (!$material->deleted_at && (bool) $material->is_active) $counts['active_materials']++;
            if (in_array('MISSING_NAME', $rowIssues, true)) $counts['missing_name']++;
            if (in_array('MISSING_CATEGORY_FK', $rowIssues, true)) $counts['missing_category_fk']++;
            if (in_array('CATEGORY_STRING_FK_MISMATCH', $rowIssues, true)) $counts['category_mismatch']++;
            if (in_array('INVALID_UOM', $rowIssues, true)) $counts['invalid_uom']++;
            if (in_array('MISSING_MATERIAL_TYPE', $rowIssues, true)) $counts['missing_type']++;
            if (in_array('BOARD_FAMILY_MISSING_DIMENSIONS', $rowIssues, true)) $counts['suspected_board_without_dimensions']++;
            if (in_array('REUSABLE_WITHOUT_TRACKING_EVIDENCE', $rowIssues, true)) $counts['reusable_without_tracking_evidence']++;

            [$proposedDisposition, $proposedTracking, $confidence] = $this->proposeControls(
                $rootName,
                $leafName,
                $material->material_type,
                (int) ($boards?->board_count ?? 0),
                (int) ($movements?->reusable_movements ?? 0)
            );

            $rows[] = [
                'material_id' => $material->id,
                'sku_code' => $material->material_code,
                'item_name' => $material->material_name,
                'workstation_code' => $workstations->get($material->workstation_id)?->code,
                'category_code' => $category?->parent_code ?: $category?->code,
                'category_name' => $rootName,
                'subcategory_code' => $category?->parent_name ? $category?->code : null,
                'subcategory_name' => $leafName,
                'legacy_category' => $material->category,
                'legacy_subcategory' => $material->subcategory,
                'current_material_type' => $material->material_type,
                'proposed_issue_disposition' => $proposedDisposition,
                'proposed_tracking_mode' => $proposedTracking,
                'mapping_confidence' => $confidence,
                'base_uom' => $material->unit_of_measure,
                'unit_cost' => $material->unit_cost,
                'quantity_on_hand' => $stock?->quantity_on_hand ?? 0,
                'quantity_reserved' => $stock?->quantity_reserved ?? 0,
                'minimum_stock_level' => $stock?->min_stock_level ?? 0,
                'warehouses' => $stock?->warehouses,
                'bins' => $stock?->bins,
                'movement_count' => $movements?->movement_count ?? 0,
                'received_qty' => $movements?->received_qty ?? 0,
                'issued_qty' => $movements?->issued_qty ?? 0,
                'returned_qty' => $movements?->returned_qty ?? 0,
                'board_count' => $boards?->board_count ?? 0,
                'offcut_count' => $boards?->offcut_count ?? 0,
                'attributes_json' => json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_active' => $material->is_active,
                'deleted_at' => $material->deleted_at,
                'quality_issues' => implode('|', $rowIssues),
            ];
        }

        $duplicates = collect($rows)
            ->groupBy(fn (array $row) => $this->normalized($row['item_name']).'|'.($row['subcategory_code'] ?: $row['category_code']).'|'.$this->normalized($row['base_uom']))
            ->filter(fn ($group) => $group->count() > 1)
            ->flatMap(fn ($group, $key) => $group->map(fn ($row) => [
                'duplicate_key' => $key,
                'material_id' => $row['material_id'],
                'sku_code' => $row['sku_code'],
                'item_name' => $row['item_name'],
            ]))->values()->all();
        $counts['suspected_duplicate_rows'] = count($duplicates);

        $this->writeCsv($output.'/materials_alignment.csv', $rows);
        $this->writeCsv($output.'/material_categories.csv', $categories->map(fn ($row) => (array) $row)->all());
        $this->writeCsv($output.'/quality_issues.csv', $issues);
        $this->writeCsv($output.'/suspected_duplicates.csv', $duplicates);

        if (Schema::hasTable('assets')) {
            $this->writeCsv($output.'/assets.csv', DB::table('assets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        }
        if ($this->option('include-transactions') && Schema::hasTable('inventory_logs')) {
            $this->writeCsv($output.'/inventory_transactions.csv', DB::table('inventory_logs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        }

        File::put($output.'/summary.json', json_encode([
            'generated_at' => now()->toIso8601String(),
            'database_connection' => DB::connection()->getName(),
            'read_only' => true,
            'counts' => $counts,
            'files' => collect(File::files($output))->map->getFilename()->sort()->values()->all(),
            'mapping_warning' => 'Proposed classifications are review suggestions, not approved master-data changes.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("Inventory master-data audit written to: {$output}");
        $this->table(['Measure', 'Count'], collect($counts)->map(fn ($value, $key) => [$key, $value])->values()->all());
        return self::SUCCESS;
    }

    private function attributes(?string $json): array
    {
        if (!$json) return [];
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) return [];
        return isset($decoded['attributes']) && is_array($decoded['attributes'])
            ? $decoded['attributes']
            : $decoded;
    }

    private function hasAny(array $attributes, array $keys): bool
    {
        foreach ($keys as $key) {
            if (isset($attributes[$key]) && $attributes[$key] !== '') return true;
        }
        return false;
    }

    private function normalized(mixed $value): string
    {
        return Str::lower(preg_replace('/\s+/', ' ', trim((string) $value)));
    }

    private function proposeControls(?string $root, ?string $leaf, ?string $currentType, int $boards, int $reusableMovements): array
    {
        if ($boards > 0) return ['recoverable_remainder', 'dimension_piece', 'high'];
        if (in_array($root, ['Boards', 'Sheet Materials'], true)) return ['recoverable_remainder', 'dimension_piece', 'medium'];
        if ($root === 'Veneer') return ['consumed', 'bulk_quantity', 'low'];
        if (in_array($root, ['Inks & Coatings', 'Adhesives & Laminates'], true)) return ['consumed', 'lot_batch', 'medium'];
        if (in_array($root, ['Printing Media'], true)) return ['consumed', 'lot_batch', 'medium'];
        if (in_array($root, ['Cutting Tools'], true) || $currentType === 'reusable' || $reusableMovements > 0) {
            return ['returnable', 'serialized_item', 'medium'];
        }
        if (in_array($root, ['Metals & Profiles', 'Timber & Wood'], true)) return ['consumed', 'bulk_quantity', 'medium'];
        return ['consumed', 'bulk_quantity', 'low'];
    }

    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) throw new \RuntimeException("Unable to create {$path}");
        if ($rows !== []) {
            fputcsv($handle, array_keys($rows[0]));
            foreach ($rows as $row) fputcsv($handle, array_values($row));
        }
        fclose($handle);
    }
}
