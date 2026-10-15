<?php

/**
 * Report 78B Phase 8 — Resolve Material exception register. READ-ONLY.
 *
 * Classifies existing BOM requirement data with the same RequirementUnits
 * policy the application now enforces, so the register and the runtime cannot
 * disagree. Everything runs inside a transaction that is always rolled back;
 * nothing is repaired, reversed, deleted or revalued.
 *
 *   ddev exec php docs/finance-redesign/phase-2/78b-evidence/exception_register.php
 *
 * Writes CSVs and summary.json next to this file.
 */

use App\Models\ElementMaterial;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Services\MaterialDuplicateFinder;
use App\Modules\ProcurementStores\Services\RequirementUnits;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out = __DIR__;
$database = config('database.connections.'.config('database.default').'.database');
$csv = function (string $name, array $rows) use ($out) {
    $handle = fopen("{$out}/{$name}", 'w');
    if ($rows !== []) {
        fputcsv($handle, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($v) => is_bool($v) ? ($v ? 'yes' : 'no') : $v, $row));
        }
    }
    fclose($handle);
};

DB::beginTransaction();
try {
    $units = app(RequirementUnits::class);
    $approved = fn ($line) => (bool) data_get($line->element?->taskMaterialsData?->project_info, 'approval_status.all_approved', false);
    $job = fn ($line) => DB::table('project_enquiries')->where('id', $line->element?->taskMaterialsData?->task?->project_enquiry_id)->value('job_number');

    /* 1. Every linked line: unit reading under the 78B policy. */
    $linked = ElementMaterial::with(['libraryMaterial.uomConversions', 'libraryMaterial.baseUom', 'element.taskMaterialsData.task'])
        ->whereNotNull('library_material_id')->orderBy('id')->get();
    $issuedByLine = DB::table('inventory_logs')->whereIn('type', ['check_out', 'issue', 'consumption'])
        ->whereNotNull('project_material_id')->groupBy('project_material_id')
        ->select('project_material_id', DB::raw('SUM(ABS(quantity)) q'), DB::raw('COUNT(*) n'))->get()->keyBy('project_material_id');

    $unitRows = [];
    foreach ($linked as $line) {
        $assessment = $units->assess($line);
        $textDiffers = mb_strtolower(trim((string) $line->unit_of_measurement)) !== mb_strtolower(trim((string) ($line->libraryMaterial?->unit_of_measure ?? '')));
        if (! $textDiffers && $assessment['status'] === 'compatible') {
            continue;
        }
        $class = match (true) {
            $assessment['status'] === 'incompatible' => 'REAL_INCOMPATIBILITY',
            $assessment['basis'] === 'alias' || $assessment['basis'] === 'same_unit' => 'HARMLESS_ALIAS',
            $assessment['basis'] === 'standard' => 'STANDARD_CONVERSION',
            default => 'APPROVED_MATERIAL_CONVERSION',
        };
        $issued = $issuedByLine[$line->id] ?? null;
        $unitRows[] = [
            'line_id' => $line->id, 'job_number' => $job($line), 'approved_list' => $approved($line),
            'bom_description' => $line->description, 'bom_quantity' => (string) $line->getRawOriginal('quantity'), 'bom_unit' => $line->unit_of_measurement,
            'library_material_id' => $line->library_material_id, 'library_material' => $line->libraryMaterial?->material_name,
            'library_unit' => $line->libraryMaterial?->unit_of_measure, 'normalised_bom_unit' => $assessment['requirement_unit'],
            'normalised_stock_unit' => $assessment['base_unit'], 'classification' => $class, 'factor' => $assessment['factor'],
            'issue_movements' => (int) ($issued->n ?? 0), 'issued_stock_qty' => (float) ($issued->q ?? 0),
            'reason' => $assessment['reason'],
            'proposed_correction' => match ($class) {
                'REAL_INCOMPATIBILITY' => ($issued ? 'Review issued quantity with Stores + Finance; if wrong, return/reverse via existing flows. ' : '')
                    .'Either add an approved material conversion in the Material Catalogue, correct the BOM unit (re-approval), or relink to a material stocked in the BOM unit.',
                'HARMLESS_ALIAS' => 'None — same unit, spelling differs. Read 1:1.',
                default => 'None — converted by a recognised factor.',
            },
        ];
    }
    $csv('01_linked_lines_unit_text_differs.csv', $unitRows);

    /* 2. Movements 78 and 79 and their financial footprint. */
    $movementRows = [];
    foreach ([78, 79] as $id) {
        $log = DB::table('inventory_logs')->where('id', $id)->first();
        if (! $log) {
            continue;
        }
        $line = ElementMaterial::withTrashed()->with(['libraryMaterial.uomConversions', 'libraryMaterial.baseUom'])->find($log->project_material_id);
        $posting = DB::table('stores_finance_postings')->where('inventory_log_id', $id)->orderByDesc('id')->first();
        $cost = $posting?->cost_line_id ? DB::table('cost_lines')->where('id', $posting->cost_line_id)->first() : null;
        $assessment = $line ? $units->assess($line) : null;
        $movementRows[] = [
            'movement_id' => $id, 'type' => $log->type, 'logged_at' => $log->logged_at,
            'material_id' => $log->material_id, 'material' => DB::table('library_materials')->where('id', $log->material_id)->value('material_name'),
            'stock_qty_issued' => abs((float) $log->quantity), 'stock_unit' => $line?->libraryMaterial?->unit_of_measure,
            'line_id' => $log->project_material_id, 'bom_description' => $line?->description, 'bom_quantity' => $line ? (string) $line->getRawOriginal('quantity') : null,
            'bom_unit' => $line?->unit_of_measurement, 'resolved_via_desk' => (bool) data_get($line?->source_metadata, 'catalogue_resolution'),
            'unit_status_78b' => $assessment['status'] ?? null, 'unit_reason' => $assessment['reason'] ?? null,
            'finance_posting_status' => $posting?->status, 'cost_line_id' => $posting?->cost_line_id,
            'cost_amount' => $cost?->amount, 'cost_status' => $cost?->status, 'journal_entry_id' => $cost?->journal_entry_id,
        ];
    }
    $csv('02_movements_78_79.csv', $movementRows);

    /* 3. Approved, unresolved lines. */
    $unresolved = ElementMaterial::with(['element.taskMaterialsData.task'])
        ->whereNull('library_material_id')->where('is_included', true)->orderBy('id')->get()
        ->filter($approved)->values();
    $closedEnquiries = DB::table('project_enquiries')->whereIn('status', ['completed', 'cancelled', 'closed'])->pluck('id')->flip();
    $csv('03_approved_unresolved_lines.csv', $unresolved->map(fn ($line) => [
        'line_id' => $line->id, 'job_number' => $job($line),
        'job_closed' => isset($closedEnquiries[$line->element?->taskMaterialsData?->task?->project_enquiry_id]),
        'element' => $line->element?->name, 'bom_description' => $line->description,
        'bom_quantity' => (string) $line->getRawOriginal('quantity'), 'bom_unit' => $line->unit_of_measurement,
        'bom_unit_recognised' => (bool) $units->normalise($line->unit_of_measurement),
    ])->all());

    /* 4. Desk resolutions that already have issue history. */
    $resolvedWithIssues = ElementMaterial::withTrashed()->whereNotNull('source_metadata')->get()
        ->filter(fn ($line) => data_get($line->source_metadata, 'catalogue_resolution') && isset($issuedByLine[$line->id]))
        ->map(fn ($line) => [
            'line_id' => $line->id, 'bom_description' => $line->description, 'bom_unit' => $line->unit_of_measurement,
            'resolved_to' => data_get($line->source_metadata, 'catalogue_resolution.library_material_id'),
            'current_library_material_id' => $line->library_material_id,
            'issue_movements' => (int) $issuedByLine[$line->id]->n,
            'issued_material_ids' => DB::table('inventory_logs')->where('project_material_id', $line->id)->whereIn('type', ['check_out', 'issue', 'consumption'])->distinct()->pluck('material_id')->implode(';'),
            'unit_status_78b' => $units->assess($line)['status'],
        ])->values()->all();
    $csv('04_resolutions_with_issue_history.csv', $resolvedWithIssues);

    /* 5. Issues whose material differs from their line's identity. */
    $mismatch = DB::table('inventory_logs as il')->join('element_materials as em', 'em.id', '=', 'il.project_material_id')
        ->whereIn('il.type', ['check_out', 'issue', 'consumption'])
        ->where(fn ($q) => $q->whereNull('em.library_material_id')->orWhereColumn('em.library_material_id', '!=', 'il.material_id'))
        ->select('il.id as movement_id', 'il.material_id', 'em.id as line_id', 'em.library_material_id', 'em.description')->get()
        ->map(fn ($r) => (array) $r)->all();
    $csv('05_issue_identity_mismatch.csv', $mismatch);

    /* 6. Duplicate library candidates (exact, after case/punctuation/order). */
    $finder = app(MaterialDuplicateFinder::class);
    $groups = LibraryMaterial::whereIn('item_status', ['Active', 'Under Review'])->get(['id', 'material_name', 'material_code', 'item_status', 'unit_of_measure'])
        ->groupBy(function ($material) use ($finder) {
            $tokens = $finder->tokens($material->material_name);
            sort($tokens);

            return implode(' ', $tokens);
        })->filter(fn ($group) => $group->count() > 1);
    $dupRows = [];
    foreach ($groups as $key => $group) {
        foreach ($group as $material) {
            $dupRows[] = ['group_key' => $key, 'material_id' => $material->id, 'material_name' => $material->material_name,
                'code' => $material->material_code, 'status' => $material->item_status, 'unit' => $material->unit_of_measure,
                'stock_on_hand' => (float) DB::table('stocks')->where('material_id', $material->id)->value('quantity_on_hand'),
                'linked_bom_lines' => DB::table('element_materials')->where('library_material_id', $material->id)->whereNull('archived_at')->count()];
        }
    }
    $csv('06_duplicate_library_candidates.csv', $dupRows);

    $summary = [
        'generated_at' => now()->toIso8601String(), 'database' => $database,
        'linked_lines_total' => $linked->count(),
        'linked_lines_unit_text_differs' => collect($unitRows)->count(),
        'by_classification' => collect($unitRows)->countBy('classification')->all(),
        'real_incompatibilities_with_issues' => collect($unitRows)->where('classification', 'REAL_INCOMPATIBILITY')->where('issue_movements', '>', 0)->count(),
        'real_incompatibilities_on_approved_lists' => collect($unitRows)->where('classification', 'REAL_INCOMPATIBILITY')->where('approved_list', true)->count(),
        'incompatible_pairs' => collect($unitRows)->where('classification', 'REAL_INCOMPATIBILITY')
            ->countBy(fn ($r) => mb_strtolower((string) $r['bom_unit']).' -> '.mb_strtolower((string) $r['library_unit']))->sortDesc()->all(),
        'alias_pairs' => collect($unitRows)->where('classification', 'HARMLESS_ALIAS')
            ->countBy(fn ($r) => mb_strtolower((string) $r['bom_unit']).' -> '.mb_strtolower((string) $r['library_unit']))->sortDesc()->all(),
        'movements' => $movementRows,
        'approved_unresolved_lines' => $unresolved->count(),
        'approved_unresolved_on_closed_jobs' => collect(array_filter($unresolved->all(), fn ($line) => isset($closedEnquiries[$line->element?->taskMaterialsData?->task?->project_enquiry_id])))->count(),
        'approved_unresolved_unit_unrecognised' => $unresolved->filter(fn ($line) => ! $units->normalise($line->unit_of_measurement))->count(),
        'approved_unresolved_jobs' => $unresolved->map($job)->unique()->count(),
        'resolutions_with_issue_history' => count($resolvedWithIssues),
        'issue_identity_mismatches' => count($mismatch),
        'duplicate_groups' => $groups->count(),
        'duplicate_items' => count($dupRows),
    ];
    file_put_contents("{$out}/summary.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo json_encode(array_diff_key($summary, ['movements' => 1]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
} finally {
    DB::rollBack(); // read-only by construction
}
