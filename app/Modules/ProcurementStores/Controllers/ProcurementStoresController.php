<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\InventoryLot;
use App\Modules\ProcurementStores\Models\InventorySerialItem;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Models\StoresFinancePosting;
use App\Modules\ProcurementStores\Jobs\ProcessStoresFinancePosting;
use App\Modules\ProcurementStores\Services\StockMovementPoster;
use App\Modules\ProcurementStores\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcurementStoresController extends Controller
{
    public function financeSyncExceptions(): JsonResponse
    {
        if (! auth()->user()?->hasAnyRole(['Stores', 'Finance', 'Finance Manager', 'Accounts', 'Accountant', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'You are not permitted to view Stores accounting exceptions.'], 403);
        }

        $planRate = fn ($log) => $log?->project_material_id
            ? app(\App\Modules\Finance\CostCollector\Services\StoresCostProducer::class)
                ->plannedUnitRate((int) $log->project_material_id)
            : null;

        $postings = StoresFinancePosting::with([
                'inventoryLog.material:id,material_name,material_code',
                'inventoryLog.project:id,project_id', 'costLine:id,ref',
                'inventoryLog.projectMaterial:id,unit_cost,description',
            ])
            ->whereIn('status', ['pending', 'processing', 'failed'])
            ->latest()
            ->get()
            ->map(function (StoresFinancePosting $posting) use ($planRate) {
                $log = $posting->inventoryLog;
                $isStale = ($posting->status === 'processing'
                        && $posting->processing_started_at?->lt(now()->subMinutes(10)))
                    || ($posting->status === 'pending'
                        && $posting->updated_at?->lt(now()->subMinutes(15)));
                return [
                    'id' => $posting->id, 'posting_type' => $posting->posting_type,
                    'status' => $posting->status, 'attempts' => $posting->attempts,
                    'last_error' => $posting->last_error,
                    'next_retry_at' => $posting->next_retry_at?->toIso8601String(),
                    'processing_started_at' => $posting->processing_started_at?->toIso8601String(),
                    'is_stale' => $isStale,
                    'action_required' => $posting->status === 'failed' || $isStale,
                    'status_message' => $posting->status === 'failed'
                        ? 'Finance posting failed and needs review.'
                        : ($isStale
                            ? 'Finance posting has stopped progressing and can be resumed safely.'
                            : ($posting->status === 'processing' ? 'Finance is currently posting this cost.' : 'Finance posting is queued.')),
                    'needs_valuation' => $posting->posting_type === 'issue_cost'
                        && str_contains(strtolower((string) $posting->last_error), 'unit cost'),
                    // What the approved plan expected this to cost. Offered as a
                    // starting figure only: it is an estimate, and a Stores issue
                    // posts as an actual, so a person still has to accept or
                    // replace it and say what the number is based on.
                    //
                    // Read through the same resolver the posting path uses.
                    // This previously read `element_materials.unit_cost`
                    // directly — a column populated on 5% of rows — so the panel
                    // offered a blank hint for almost every exception it raised,
                    // which is the one moment the figure is actually needed.
                    'planned_unit_cost' => ($rate = $planRate($log)) !== null ? (float) $rate : null,
                    'created_at' => $posting->created_at?->toIso8601String(),
                    'cost_line' => $posting->costLine,
                    'inventory_log_id' => $log?->id, 'type' => $log?->type,
                    'quantity' => $log?->quantity, 'reference_no' => $log?->reference_no,
                    'logged_at' => $log?->logged_at?->toIso8601String(),
                    'material' => $log?->material, 'project' => $log?->project,
                ];
            });

        return response()->json(['data' => $postings]);
    }

    public function retryFinanceSync(StoresFinancePosting $inventoryLog): JsonResponse
    {
        if (! auth()->user()?->hasAnyRole(['Stores', 'Finance', 'Finance Manager', 'Accounts', 'Accountant', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'You are not permitted to retry Stores accounting.'], 403);
        }
        $isStale = ($inventoryLog->status === 'processing'
                && $inventoryLog->processing_started_at?->lt(now()->subMinutes(10)))
            || ($inventoryLog->status === 'pending'
                && $inventoryLog->updated_at?->lt(now()->subMinutes(15)));
        if ($inventoryLog->status !== 'failed' && ! $isStale) {
            return response()->json(['message' => 'This Finance posting is still progressing normally and does not need to be resumed.'], 422);
        }

        $inventoryLog->update([
            'status' => 'pending', 'last_error' => null, 'next_retry_at' => null,
            'last_retried_by' => auth()->id(),
        ]);
        ProcessStoresFinancePosting::dispatch($inventoryLog->id)->onQueue('stores-finance');

        return response()->json(['message' => 'Finance posting queued. Stock will not move again.']);
    }

    public function resolveFinanceValuation(Request $request, StoresFinancePosting $inventoryLog): JsonResponse
    {
        if (! auth()->user()?->hasAnyRole(['Stores', 'Finance', 'Finance Manager', 'Accounts', 'Accountant', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'You are not permitted to resolve Stores valuation.'], 403);
        }
        $validated = $request->validate([
            'unit_cost' => 'required|numeric|min:0.01',
            'reason' => 'required|string|min:5|max:500',
        ]);
        if ($inventoryLog->status !== 'failed' || $inventoryLog->posting_type !== 'issue_cost') {
            return response()->json(['message' => 'Only a failed Stores issue valuation can be resolved here.'], 422);
        }

        DB::transaction(function () use ($inventoryLog, $validated) {
            $posting = StoresFinancePosting::whereKey($inventoryLog->id)->lockForUpdate()->firstOrFail();
            $movement = InventoryLog::whereKey($posting->inventory_log_id)->lockForUpdate()->firstOrFail();
            $movement->update(['receipt_unit_cost' => $validated['unit_cost']]);
            Board::where('original_issue_log_id', $movement->id)->where('current_value', '<=', 0)
                ->update(['current_value' => $validated['unit_cost']]);
            $posting->update([
                'status' => 'pending', 'last_error' => null, 'next_retry_at' => null,
                'resolved_unit_cost' => $validated['unit_cost'], 'resolution_notes' => $validated['reason'],
                'resolved_by' => auth()->id(), 'resolved_at' => now(), 'last_retried_by' => auth()->id(),
            ]);
            ProcessStoresFinancePosting::dispatch($posting->id)->onQueue('stores-finance')->afterCommit();
        });

        return response()->json(['message' => 'Valuation recorded and Finance posting queued.']);
    }

    public function controlOptions(LibraryMaterial $material): JsonResponse
    {
        $lots = InventoryLot::where('material_id', $material->id)
            ->where('status', 'Released')->whereRaw('(quantity_on_hand - quantity_reserved) > 0')
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')->get()
            ->map(fn ($lot) => [
                'id' => $lot->id, 'lot_number' => $lot->lot_number,
                'expiry_date' => $lot->expiry_date?->toDateString(), 'available' => $lot->available,
                'warehouse_code' => $lot->warehouse_code, 'location_bin' => $lot->location_bin,
                'is_expired' => $lot->expiry_date?->isPast() ?? false,
            ]);

        $serials = InventorySerialItem::where('material_id', $material->id)
            ->whereIn('status', ['Available', 'Issued'])->orderBy('tracking_code')->get([
                'id', 'tracking_code', 'manufacturer_serial', 'status', 'condition_grade',
                'inventory_lot_id', 'project_id', 'holder_name', 'location_bin',
            ]);

        return response()->json(['data' => ['lots' => $lots, 'serial_items' => $serials]]);
    }

    /**
     * Fetch the Master Inventory (Library Materials + Stock Quantities)
     */
    public function inventory(Request $request): JsonResponse
    {
        $request->validate([
            'material_ids' => 'sometimes|array|max:500',
            'material_ids.*' => 'integer|distinct|exists:library_materials,id',
        ]);

        $includeUnstocked = $request->boolean('include_unstocked');
        $query = LibraryMaterial::with(['workstation', 'stock', 'materialCategory.parent', 'itemType', 'baseUom', 'purchaseUom', 'issueUom', 'uomConversions']);

        // Project fulfilment knows the exact catalogue identities referenced by
        // its BOM. Fetching those identities directly avoids treating a valid
        // link as "unlinked" merely because it fell beyond an inventory page.
        if ($request->filled('material_ids')) {
            $query->whereIn('library_materials.id', $request->input('material_ids', []));
        } elseif (! $request->boolean('include_hidden')) {
            // Only the catalogue items Stores actually carries. Applied to
            // browsing only: an explicit material_ids lookup above is an
            // identity resolution, and filtering it would report a correctly
            // linked BOM row as unlinked. include_hidden is the deliberate
            // opt-out for a screen that wants the whole register back.
            $query->inventoryVisible();
        }

        if ($request->input('selection_context') === 'project') {
            // Rank by demonstrated execution use, not by catalogue creation date.
            // Physical issues are the strongest signal; repeated appearance in
            // project specifications keeps frequently prepared items near the top
            // even before their next Stores issue occurs.
            $query->withCount([
                'inventoryLogs as recent_project_issue_count' => fn ($issues) => $issues
                    ->whereNotNull('project_id')
                    ->whereIn('type', ['check_out', 'issue', 'consumption'])
                    ->where('logged_at', '>=', now()->subDays(90)),
                'projectSpecifications as project_specification_count' => fn ($specifications) => $specifications
                    ->where('is_included', true),
            ]);
        }

        if ($includeUnstocked) {
            // Receive Stock is the controlled bridge from catalogue identity to
            // physical inventory, so it must be able to select every active item.
            $query->where(function ($active) {
                $active->where('item_status', 'Active')
                    ->orWhere(function ($legacy) {
                        $legacy->whereNull('item_status')->where('is_active', true);
                    });
            });
        } else {
            // Store Inventory and outbound operations show only items that have
            // entered stock control. Zero balances remain visible for reorder use.
            $query->whereHas('stock');
        }

        // Pickers behind an action that DECREMENTS stock (issue, damage) ask for
        // issuable only, so an item at zero cannot be selected in the first
        // place. Deliberately not applied to receive or return: both increase
        // stock, and a fully-issued item sits at zero exactly when it is being
        // returned. Board-tracked items are ranked here on the stock row alone,
        // which counts ungraded Quarantine boards; the board-aware figures are
        // derived further down, and adjustStock() remains the binding check.
        if ($request->input('availability') === 'issuable') {
            $query->issuable();
        }

        // Filter by Search Query
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Filter by Workstation
        if ($request->filled('workstation_id')) {
            $query->where('workstation_id', $request->workstation_id);
        }

        // Filter by Category
        if ($request->filled('category')) {
            $query->where('category', 'like', "%{$request->category}%");
        }

        if ($request->input('selection_context') === 'project') {
            $query->orderByDesc('recent_project_issue_count')
                ->orderByDesc('project_specification_count')
                ->orderBy('library_materials.material_name');
        } else {
            $query->latest('library_materials.created_at');
        }
        // Project selectors do not consume the inventory-wide summary. Avoid
        // hydrating every matching material and all its relationships merely to
        // discard that work at response time.
        $needsSummary = $request->input('selection_context') !== 'project';
        // The summary spans every match, so this second pass reads the whole
        // catalogue. It is projected down to the columns the counters actually
        // use: the page query's eager loads (workstation, item type, three UOMs
        // and the conversion table) are dropped, along with the wide columns
        // and the ordering, none of which survive into a count or a sum.
        // materialCategory.parent stays because board classification falls back
        // to the root category name for rows with no tracking_mode.
        $summaryMaterials = $needsSummary
            ? (clone $query)
                ->reorder()
                ->setEagerLoads([])
                ->with([
                    'stock:id,material_id,quantity_on_hand,quantity_reserved,min_stock_level',
                    'materialCategory:id,name,parent_id',
                    'materialCategory.parent:id,name',
                ])
                ->get([
                    'library_materials.id',
                    'library_materials.material_category_id',
                    'library_materials.category',
                    'library_materials.unit_cost',
                    'library_materials.material_type',
                    'library_materials.tracking_mode',
                    'library_materials.issue_disposition',
                ])
            : collect();
        $pageLimit = $includeUnstocked ? 500 : 200;
        $paginator = $query->paginate(min((int) $request->get('per_page', 50), $pageLimit));

        // For board-tracked (individual) materials, quantity_on_hand on the stock row
        // also counts ungraded Quarantine boards, which overstates what's actually
        // issuable. Derive the figures from board records instead so the master
        // inventory matches the board registry:
        //   on_hand   = boards physically in stores (Available + Quarantine)
        //   available = ready-to-issue (Available) minus soft reservations
        $allMaterials = $summaryMaterials->concat($paginator->getCollection())->unique('id');
        $boardMaterialIds = $allMaterials
            ->filter(fn($m) => $m->isBoardTrackable())
            ->pluck('id');

        $boardCounts = $boardMaterialIds->isNotEmpty()
            ? Board::whereIn('library_material_id', $boardMaterialIds)
                ->selectRaw("library_material_id,
                    SUM(CASE WHEN status = 'Available' THEN 1 ELSE 0 END) AS available_cnt,
                    SUM(CASE WHEN status IN ('Available', 'Quarantine') THEN 1 ELSE 0 END) AS in_stores_cnt,
                    SUM(CASE WHEN status IN ('Available', 'Quarantine') THEN current_value ELSE 0 END) AS in_stores_value")
                ->groupBy('library_material_id')
                ->get()
                ->keyBy('library_material_id')
            : collect();

        $formatMaterial = function ($material) use ($boardCounts) {
            // The governed master controls behaviour. stocks.tracking_mode is a
            // compatibility projection and must never override master data.
            $isBoard = $material->isBoardTrackable();
            $bc      = $isBoard ? $boardCounts->get($material->id) : null;

            $reserved   = (float) ($material->stock?->quantity_reserved ?? 0);
            $onHand     = $bc
                ? (float) $bc->in_stores_cnt
                : (float) ($material->stock?->quantity_on_hand ?? 0);
            $available  = $bc
                ? max(0.0, (float) $bc->available_cnt - $reserved)
                : (float) ($material->stock ? ($material->stock->quantity_on_hand - $reserved) : 0);

            return [
                'id'                => $material->id,
                'workstation_id'    => $material->workstation_id,
                'material_name'     => $material->material_name,
                'material_code'     => $material->material_code,
                'material_category_id' => $material->material_category_id,
                'category'          => $material->materialCategory?->parent?->name
                    ?? $material->materialCategory?->name ?? $material->category,
                'subcategory'       => $material->materialCategory?->parent
                    ? $material->materialCategory->name : $material->subcategory,
                'unit_of_measure'   => $material->baseUom?->code ?? $material->unit_of_measure,
                'unit_cost'         => $material->unit_cost,
                'workstation'       => $material->workstation,
                'workstation_name'  => $material->workstation?->name ?? 'N/A',
                'attributes'        => $material->attributes ?? [],
                'is_active'         => $material->is_active,
                'notes'             => $material->notes,
                'material_type'     => $material->material_type ?? 'consumable',
                'item_status'       => $material->item_status ?? ($material->is_active ? 'Active' : 'Inactive'),
                'issue_disposition' => $material->issue_disposition ?? ($material->material_type === 'reusable' ? 'returnable' : 'consumed'),
                'tracking_mode'     => $material->tracking_mode ?? ($material->isBoardTrackable() ? 'dimension_piece' : 'bulk_quantity'),
                'is_hazardous'      => (bool) $material->is_hazardous,
                'is_serialized'     => (bool) $material->is_serialized,
                'is_batch_controlled' => (bool) $material->is_batch_controlled,
                'is_expiry_controlled' => (bool) $material->is_expiry_controlled,
                'is_project_chargeable' => (bool) $material->is_project_chargeable,
                'base_uom'          => $material->baseUom?->code ?? $material->unit_of_measure,
                'base_uom_id'       => $material->base_uom_id,
                'purchase_uom'      => $material->purchaseUom ? ['id' => $material->purchaseUom->id, 'code' => $material->purchaseUom->code, 'name' => $material->purchaseUom->name] : null,
                'issue_uom'         => $material->issueUom ? ['id' => $material->issueUom->id, 'code' => $material->issueUom->code, 'name' => $material->issueUom->name] : null,
                'uom_conversions'   => $material->uomConversions->map(fn ($row) => ['from_uom_id' => $row->from_uom_id, 'to_uom_id' => $row->to_uom_id, 'factor' => (float) $row->factor])->values(),
                'board_trackable'   => $material->isBoardTrackable(),
                // Same source as the Materials Library table, so one item is
                // never described two ways across the two screens.
                'stock_handling'    => $material->stock_handling,
                'handling_label'    => $material->handling_label,
                // Stores must be able to see that a draft is not yet usable,
                // rather than discovering it when a check-in is refused.
                'is_draft'          => ($material->item_status ?? 'Active') !== 'Active',
                'quantity_on_hand'  => $onHand,
                'quantity_reserved' => $reserved,
                'available'         => $available,
                'min_stock_level'   => (float) ($material->stock?->min_stock_level ?? 0),
                'location'          => $material->stock?->location_bin ?? 'Not Set',
                'warehouse_code'    => $material->stock?->warehouse_code ?? 'MAIN',
                'is_stocked'        => $material->stock !== null,
                'recent_project_issue_count' => (int) ($material->recent_project_issue_count ?? 0),
                'project_specification_count' => (int) ($material->project_specification_count ?? 0),
                'project_usage_score' => ((int) ($material->recent_project_issue_count ?? 0) * 3)
                    + (int) ($material->project_specification_count ?? 0),
                'is_frequently_used' => (int) ($material->recent_project_issue_count ?? 0) >= 3
                    || (int) ($material->project_specification_count ?? 0) >= 5,
                'can_set_stock_quantity' => !$isBoard
                    && !$material->is_serialized
                    && !$material->is_batch_controlled,
                '_stock_value'      => $isBoard
                    ? (float) ($bc?->in_stores_value ?? 0)
                    : $onHand * (float) $material->unit_cost,
            ];
        };

        $paginator->getCollection()->transform(function ($material) use ($formatMaterial) {
            $row = $formatMaterial($material);
            unset($row['_stock_value']);
            return $row;
        });

        // Counted straight off the lean projection rather than through
        // $formatMaterial: that closure reads workstation, UOM and conversion
        // relations the summary never reports, and on a lean row each of those
        // would lazy-load once per catalogue item. Same definitions as the row
        // formatter, deliberately kept side by side with it.
        $summary = [
            'total_items' => 0,
            'stocked_item_count' => 0,
            'unstocked_item_count' => 0,
            'total_value' => 0.0,
            'low_stock_count' => 0,
            'out_of_stock_count' => 0,
            'board_item_count' => 0,
            'reusable_item_count' => 0,
        ];

        foreach ($summaryMaterials as $material) {
            $isBoard = $material->isBoardTrackable();
            $bc      = $isBoard ? $boardCounts->get($material->id) : null;
            $stock   = $material->stock;

            $reserved  = (float) ($stock?->quantity_reserved ?? 0);
            $onHand    = $bc
                ? (float) $bc->in_stores_cnt
                : (float) ($stock?->quantity_on_hand ?? 0);
            $available = $bc
                ? max(0.0, (float) $bc->available_cnt - $reserved)
                : (float) ($stock ? ($stock->quantity_on_hand - $reserved) : 0);
            $minLevel    = (float) ($stock?->min_stock_level ?? 0);
            $disposition = $material->issue_disposition
                ?? ($material->material_type === 'reusable' ? 'returnable' : 'consumed');

            $summary['total_items']++;
            $stock ? $summary['stocked_item_count']++ : $summary['unstocked_item_count']++;
            $summary['total_value'] += $isBoard
                ? (float) ($bc?->in_stores_value ?? 0)
                : $onHand * (float) $material->unit_cost;

            if ($minLevel > 0 && $available <= $minLevel) {
                $summary['low_stock_count']++;
            }
            if ($stock && $available <= 0) {
                $summary['out_of_stock_count']++;
            }
            if ($isBoard) {
                $summary['board_item_count']++;
            }
            if (! $isBoard && $disposition === 'returnable') {
                $summary['reusable_item_count']++;
            }
        }

        $summary['total_value'] = round($summary['total_value'], 2);

        $response = [
            'data'   => $paginator,
            'status' => 'success',
        ];

        // Drafts are deliberately excluded from selection — you cannot plan
        // against an item Stores has no way to classify. But silently omitting
        // them makes a freshly typed master list look like it never saved, so
        // say how many matched and were held back.
        if ($includeUnstocked) {
            $held = LibraryMaterial::query()
                ->where('item_status', '!=', 'Active')
                ->when($request->filled('search'), fn ($scope) => $scope->search($request->search))
                ->when($request->filled('workstation_id'), fn ($scope) => $scope->where('workstation_id', $request->workstation_id))
                ->count();

            if ($held > 0) {
                $response['unfinished'] = [
                    'count' => $held,
                    'message' => $held === 1
                        ? '1 matching item is still being set up and cannot be selected yet.'
                        : "{$held} matching items are still being set up and cannot be selected yet.",
                ];
            }
        }

        // Project material selectors need availability, not the company's
        // aggregate inventory valuation. Keep that finance/stores summary out
        // of the operational selection response.
        if ($request->input('selection_context') !== 'project') {
            $response['summary'] = $summary;
        }

        return response()->json($response);
    }

    /**
     * Process a stock check-in (Add to inventory)
     */
    /*
     * The six movement endpoints in this controller are adapters now.
     *
     * Each keeps its own published contract — its validation, its role check and
     * the exact response body its callers already parse — but the rules that
     * decide whether a movement may happen live in StockMovementPoster, which
     * every one of them, and the multi-line /movements endpoint, goes through.
     *
     * That is the whole point of the change. Batch receiving used to be a
     * reduced copy of this method: no lot number, no expiry, no serial numbers,
     * no goods-receipt reconciliation. The same delivery therefore obeyed
     * different rules depending on which screen someone happened to open.
     */
    public function checkIn(Request $request, StockMovementPoster $poster): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'Only Stores team members can check stock in.'], 403);
        }

        $request->validate([
            'material_id' => 'required|exists:library_materials,id',
            'quantity' => 'required|numeric|min:0.01',
            'entered_uom_id' => 'nullable|integer|exists:units_of_measure,id',
            'warehouse_code' => 'sometimes|string',
            'location' => 'nullable|string',
            'reference_no' => 'nullable|string',
            'notes' => 'nullable|string',
            'type' => 'nullable|string',
            'logged_at' => 'nullable|date',
            'lot_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date|after_or_equal:today',
            'receipt_unit_cost' => 'nullable|numeric|min:0',
            'serial_numbers' => 'nullable|array',
            'serial_numbers.*' => 'string|max:150',
            'grn_item_id' => 'nullable|integer|exists:goods_receipt_note_items,id',
        ]);

        $result = $poster->post('receive', $request->except('type'));
        $log = $result['log'];
        $boards = $result['boards'];
        $material = LibraryMaterial::find($request->material_id);

        return response()->json([
            'message'      => 'Stock updated successfully',
            'data'         => $log,
            'batch_number' => $log->batch_number,
            'status'       => 'success',
            'labels_required' => count($boards) > 0,
            'label_status' => count($boards) > 0 ? 'pending_print' : 'not_applicable',
            'label_count' => count($boards),
            'boards'       => array_map(fn($b) => [
                'id'            => $b->id,
                'tracking_code' => $b->tracking_code,
                'scan_url'      => config('app.frontend_url', config('app.url')) . '/stores/boards/' . $b->tracking_code,
                'length'        => $b->length,
                'width'         => $b->width,
                'thickness'     => $b->thickness,
                'batch_number'  => $b->batch_number,
                'material'      => ['name' => $material?->material_name, 'code' => $material?->material_code],
            ], $boards),
        ]);
    }

    /**
     * Process a stock check-out (Deduct from inventory)
     *
     * Individually tracked board/sheet materials must NOT be checked out through
     * this generic endpoint. Reusable tools still use this normal quantity flow.
     */
    public function checkOut(Request $request, StockMovementPoster $poster): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'Only Stores team members can issue stock.'], 403);
        }

        $request->validate([
            'material_id' => 'required|exists:library_materials,id',
            'quantity' => 'required|numeric|min:0.01',
            'entered_uom_id' => 'nullable|integer|exists:units_of_measure,id',
            'project_id' => 'nullable|exists:projects,id',
            'project_material_id' => 'nullable|exists:element_materials,id',
            'notes' => 'nullable|string',
            'logged_at' => 'nullable|date',
            'inventory_lot_id' => 'nullable|exists:inventory_lots,id',
            'serial_item_ids' => 'nullable|array',
            'serial_item_ids.*' => 'integer|exists:inventory_serial_items,id',
        ]);

        $log = $poster->post('issue', $request->all())['log'];

        return response()->json([
            'message' => 'Stock issued successfully',
            'data' => $log,
            'batch_number' => $log->batch_number,
            'status' => 'success'
        ]);
    }

    /**
     * Update stock policy settings and the working balance for simple
     * quantity-tracked stock during initial stores setup.
     */
    public function updateStockSettings(Request $request): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'Only Stores team members can update stock settings.'], 403);
        }

        $validated = $request->validate([
            'material_id'     => 'required|exists:library_materials,id',
            'min_stock_level' => 'nullable|numeric|min:0',
            'location_bin'    => 'nullable|string|max:50',
            'warehouse_code'  => 'nullable|string|max:20',
            'stock_quantity' => 'nullable|numeric|min:0',
            // Required only when the count actually moves the balance — the form
            // always posts the current figure back, and re-sending it unchanged
            // is not an adjustment.
            'stock_adjustment_reason' => 'nullable|string|min:5|max:500',
        ]);

        $material = LibraryMaterial::findOrFail($validated['material_id']);
        $stock = DB::transaction(function () use ($request, $validated, $material) {
            $stock = Stock::firstOrCreate(
                ['material_id' => $material->id],
                ['quantity_on_hand' => 0, 'quantity_reserved' => 0]
            );
            $stock = Stock::whereKey($stock->id)->lockForUpdate()->firstOrFail();

            if ($request->has('min_stock_level')) $stock->min_stock_level = $request->min_stock_level;
            if ($request->has('location_bin')) $stock->location_bin = $request->location_bin;
            if ($request->has('warehouse_code')) $stock->warehouse_code = $request->warehouse_code;
            $stock->save();

            if ($request->filled('stock_quantity')) {
                if ($material->isBoardTrackable() || $material->is_serialized || $material->is_batch_controlled) {
                    throw ValidationException::withMessages([
                        'stock_quantity' => 'Tracked boards, lots and serial items must enter through Receive Stock.',
                    ]);
                }

                $counted = (float) $validated['stock_quantity'];
                if ($counted < (float) $stock->quantity_reserved) {
                    throw ValidationException::withMessages([
                        'stock_quantity' => 'Stock quantity cannot be below the currently reserved quantity.',
                    ]);
                }

                // This used to assign quantity_on_hand directly, which made it a
                // second stock writer that left no movement behind — the reason
                // most balances stopped reconciling to their own ledger. The
                // counted figure is now posted as an adjustment like any other
                // movement, so the ledger stays the only account of the balance.
                $difference = round($counted - (float) $stock->quantity_on_hand, 2);
                if (abs($difference) >= 0.01) {
                    if (blank($validated['stock_adjustment_reason'] ?? null)) {
                        throw ValidationException::withMessages([
                            'stock_adjustment_reason' => 'Say what the new count is based on — this posts a stock adjustment.',
                        ]);
                    }

                    app(InventoryService::class)->adjustStock(
                        $material->id,
                        $difference,
                        'adjustment',
                        [
                            'warehouse_code' => $stock->warehouse_code,
                            'notes' => 'Counted balance set to '.$counted.' — '.$validated['stock_adjustment_reason'],
                            'logged_at' => now(),
                        ],
                    );
                    $stock->refresh();
                }
            }

            return $stock;
        });

        return response()->json([
            'message' => 'Stock settings updated successfully',
            'data' => $stock,
            'status' => 'success'
        ]);
    }

    /**
     * Apply one shelf decision to many materials at once.
     *
     * Reorder levels and bin locations are typed for hundreds of items during
     * setup, one modal at a time, and a whole shelf usually shares an answer.
     * Stock on hand already had row selection built — checkboxes, select-all,
     * the lot — with nothing that consumed it; this is what it now does.
     *
     * Balances are deliberately NOT settable here. Changing a counted quantity
     * posts a stock adjustment and needs a reason per material, so it stays on
     * the single-material form where that reason can be given.
     */
    public function bulkStockSettings(Request $request): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'Only Stores team members can update stock settings.'], 403);
        }

        $validated = $request->validate([
            'material_ids' => 'required|array|min:1|max:500',
            'material_ids.*' => 'integer|exists:library_materials,id',
            'min_stock_level' => 'nullable|numeric|min:0',
            'location_bin' => 'nullable|string|max:50',
            'warehouse_code' => 'nullable|string|max:20',
        ]);

        // A request naming no field to change would report success having done
        // nothing, which is worse than saying so.
        $changes = array_filter(
            $request->only(['min_stock_level', 'location_bin', 'warehouse_code']),
            fn ($value, $key) => $request->has($key) && $value !== null && $value !== '',
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changes === []) {
            throw ValidationException::withMessages([
                'settings' => 'Choose at least one setting to apply — a reorder level, a bin or a warehouse.',
            ]);
        }

        $ids = array_values(array_unique($validated['material_ids']));

        DB::transaction(function () use ($ids, $changes) {
            foreach ($ids as $materialId) {
                Stock::firstOrCreate(
                    ['material_id' => $materialId],
                    ['quantity_on_hand' => 0, 'quantity_reserved' => 0],
                );
            }

            Stock::whereIn('material_id', $ids)->update($changes);
        });

        $count = count($ids);
        $fields = implode(', ', array_keys($changes));

        return response()->json([
            'message' => "Updated {$fields} on {$count} ".($count === 1 ? 'material' : 'materials').'.',
            'updated' => $count,
            'applied' => array_keys($changes),
            'status' => 'success',
        ]);
    }

    /**
     * Process a stock return (Add back to inventory from project)
     */
    public function returns(Request $request, StockMovementPoster $poster): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'Only Stores team members can record returns.'], 403);
        }

        $request->validate([
            'material_id' => 'required|exists:library_materials,id',
            'quantity' => 'required|numeric|min:0.01',
            'entered_uom_id' => 'nullable|integer|exists:units_of_measure,id',
            'original_issue_log_id' => 'required|exists:inventory_logs,id',
            'notes' => 'nullable|string',
            'inventory_lot_id' => 'nullable|exists:inventory_lots,id',
            'serial_item_ids' => 'nullable|array',
            'serial_item_ids.*' => 'integer|exists:inventory_serial_items,id',
        ]);

        $log = $poster->post('return', $request->all())['log'];

        return response()->json([
            'message' => 'Material returned successfully',
            'data' => $log,
            'status' => 'success'
        ]);
    }

    /**
     * Mark stock as defective (Deduct from inventory)
     */
    public function markDefective(Request $request, StockMovementPoster $poster): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'Only Stores team members can mark stock defective.'], 403);
        }

        $request->validate([
            'material_id' => 'required|exists:library_materials,id',
            'quantity' => 'required|numeric|min:0.01',
            'entered_uom_id' => 'nullable|integer|exists:units_of_measure,id',
            'notes' => 'required|string|min:5',
            'inventory_lot_id' => 'nullable|exists:inventory_lots,id',
            'serial_item_ids' => 'nullable|array',
            'serial_item_ids.*' => 'integer|exists:inventory_serial_items,id',
        ]);

        $log = $poster->post('damage', $request->all())['log'];

        return response()->json([
            'message' => 'Stock marked as defective and removed from inventory',
            'data' => $log,
            'status' => 'success'
        ]);
    }

    /**
     * Process batch check-in (multiple materials with same batch number)
     */
    public function batchCheckIn(Request $request, StockMovementPoster $poster): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'Only Stores team members can check stock in.'], 403);
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.material_id' => 'required|exists:library_materials,id',
            'items.*.project_material_id' => 'nullable|exists:element_materials,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.entered_uom_id' => 'nullable|integer|exists:units_of_measure,id',
            'items.*.receipt_unit_cost' => 'nullable|numeric|min:0',
            'items.*.reference_no' => 'nullable|string',
            'items.*.notes' => 'nullable|string',
            // Accepted now, and honoured, because the poster is the same code
            // the single receipt uses. This endpoint used to drop them silently.
            'items.*.lot_number' => 'nullable|string|max:100',
            'items.*.expiry_date' => 'nullable|date|after_or_equal:today',
            'items.*.location' => 'nullable|string|max:50',
            'items.*.serial_numbers' => 'nullable|array',
            'items.*.serial_numbers.*' => 'string|max:150',
            'warehouse_code' => 'sometimes|string',
            'logged_at' => 'nullable|date'
        ]);

        return $this->postBatch($poster, 'receive', $request, [
            'warehouse_code' => $request->warehouse_code ?? 'MAIN',
            'logged_at' => $request->logged_at ?? now(),
        ], 'Batch check-in processed successfully');
    }

    /**
     * Process batch check-out (multiple materials with same batch number)
     */
    public function batchCheckOut(Request $request, StockMovementPoster $poster): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'Only Stores team members can issue stock.'], 403);
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.material_id' => 'required|exists:library_materials,id',
            'items.*.project_material_id' => 'nullable|exists:element_materials,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.entered_uom_id' => 'nullable|integer|exists:units_of_measure,id',
            'items.*.reference_no' => 'nullable|string',
            'items.*.notes' => 'nullable|string',
            'items.*.usage_type' => 'nullable|string|in:consumable,reusable',
            'items.*.requestor' => 'nullable|string',
            'items.*.inventory_lot_id' => 'nullable|exists:inventory_lots,id',
            'items.*.serial_item_ids' => 'nullable|array',
            'items.*.serial_item_ids.*' => 'integer|exists:inventory_serial_items,id',
            'requestor_name' => 'required|string|max:255',
            'reference_no' => 'nullable|string|max:255',
            'project_id' => 'nullable|exists:projects,id',
            'logged_at' => 'nullable|date'
        ]);

        return $this->postBatch($poster, 'issue', $request, [
            'project_id' => $request->project_id ?: null,
            'logged_at' => $request->logged_at ?? now(),
        ], 'Batch check-out processed successfully', function (array $item) use ($request) {
            return [
                'recipient_name' => $item['requestor'] ?? $request->requestor_name ?? null,
                'reference_no' => $item['reference_no'] ?? $request->reference_no ?? null,
                'notes' => $item['notes'] ?? 'Project material issue',
            ];
        });
    }

    /**
     * Post every line of a batch under one batch number, in one transaction.
     *
     * Either the whole list posts or none of it does. Receiving a delivery is
     * one act to the person doing it, and a half-posted one — some lines in,
     * some rejected, no record of which — is exactly the state that made people
     * count the shelf twice.
     */
    private function postBatch(
        StockMovementPoster $poster,
        string $type,
        Request $request,
        array $shared,
        string $message,
        ?callable $perItem = null,
    ): JsonResponse {
        $logs = [];
        $boards = [];
        $batchNumber = null;

        try {
            DB::transaction(function () use ($poster, $type, $request, $shared, $perItem, &$logs, &$boards, &$batchNumber) {
                $batchNumber = $poster->newBatchNumber();

                foreach ($request->items as $item) {
                    $line = array_merge(
                        $item,
                        $shared,
                        $perItem ? $perItem($item) : [],
                        ['batch_number' => $batchNumber],
                    );

                    $result = $poster->post($type, $line);
                    $logs[] = $result['log'];
                    $boards = array_merge($boards, $result['boards']);
                }
            });
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'status' => 'error'], 422);
        }

        return response()->json(array_filter([
            'message'         => $message,
            'batch_number'    => $batchNumber,
            'items_processed' => count($logs),
            'data'            => $logs,
            'boards_created'  => $type === 'receive' ? count($boards) : null,
            'status'          => 'success',
        ], fn ($value) => $value !== null));
    }

    private function assertMaterialsApproved($materialsData): void
    {
        if (! (bool) data_get($materialsData?->project_info, 'approval_status.all_approved', false)) {
            throw ValidationException::withMessages([
                'project_material_id' => 'Project Officer and Production must sign off the material list before Stores can issue it.',
            ]);
        }
    }

    public function inventoryLogs(Request $request): JsonResponse
    {
        // materialCategory.parent feeds the appended board_trackable attribute,
        // which the Project Material Desk uses to keep tracked boards out of the
        // bulk return list. Without it that accessor lazy-loads once per row.
        // projectMaterial.element carries the element a movement served, so the
        // desk can group custody and history the same way it groups the issue
        // list. Eager-loaded because the alternative is a query per row.
        $query = InventoryLog::with([
            // user is narrowed to the two columns the movement list shows. The
            // full model drags its roles ($with) into every row and, before the
            // appends were removed, two EXISTS queries per row on top.
            // enquiry.deliverables: project_scope is a real column with an accessor
            // over that table, and accessors run when the model is serialized —
            // so an unloaded relation is one query per enquiry on the page.
            'material.materialCategory.parent', 'enteredUom', 'user:id,name', 'project.enquiry.deliverables',
            'projectMaterial:id,project_element_id', 'projectMaterial.element:id,name',
            'financePosting.costLine:id,ref,status,nature,net_amount,base_net_amount,quantity,unit_rate,verified_at',
        ]);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('usage_type')) {
            $query->where('usage_type', $request->usage_type);
        }

        if ($request->filled('material_id')) {
            $query->where('material_id', $request->material_id);
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->filled('batch_number')) {
            $query->where('batch_number', $request->batch_number);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('logged_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('logged_at', '<=', $request->end_date);
        }

        $logs = $query->orderBy('logged_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 50));

        // Tell the activity list which board check-ins still have live boards, so
        // the overview can lock those rows instead of failing mid bulk-delete.
        $boardBatches = $logs->getCollection()
            ->filter(fn (InventoryLog $log) => $log->usage_type === 'reusable'
                && $log->type === 'check_in'
                && filled($log->batch_number))
            ->pluck('batch_number')
            ->unique()
            ->values();

        $activeByBatch = $boardBatches->isEmpty()
            ? collect()
            : Board::query()
                ->whereIn('batch_number', $boardBatches)
                ->whereNotIn('status', ['Consumed', 'Scrapped'])
                ->selectRaw('batch_number, COUNT(*) as active_count')
                ->groupBy('batch_number')
                ->pluck('active_count', 'batch_number');

        $logs->setCollection(
            $logs->getCollection()->map(function (InventoryLog $log) use ($activeByBatch) {
                $activeBoards = 0;
                if ($log->usage_type === 'reusable' && $log->type === 'check_in' && filled($log->batch_number)) {
                    $activeBoards = (int) ($activeByBatch[$log->batch_number] ?? 0);
                }

                $log->setAttribute('active_board_count', $activeBoards);
                $log->setAttribute('can_delete', $activeBoards === 0);
                $log->setAttribute(
                    'delete_blocked_reason',
                    $activeBoards > 0
                        ? "{$activeBoards} active board(s) still use batch [{$log->batch_number}]. Scrap or consume them before deleting this receipt."
                        : null
                );

                return $log;
            })
        );

        return response()->json([
            'data'   => $logs,
            'status' => 'success',
        ]);
    }

    /**
     * Repair a historical project issue that predates requirement-level links.
     * Stock never moves here. Posted Finance facts remain immutable; those need
     * a Finance reversal/repost rather than a hidden foreign-key edit.
     */
    public function linkProjectMaterial(Request $request, InventoryLog $inventoryLog): JsonResponse
    {
        if (! auth()->user()?->hasAnyRole(['Manager', 'Super Admin'])) {
            return response()->json(['message' => 'A Manager must approve project-material linkage corrections.'], 403);
        }
        $validated = $request->validate([
            'project_material_id' => 'required|integer|exists:element_materials,id',
            'reason' => 'required|string|min:8|max:500',
        ]);
        if (! in_array($inventoryLog->type, ['check_out', 'issue', 'consumption'], true) || ! $inventoryLog->project_id) {
            return response()->json(['message' => 'Only an unlinked project stock issue can be corrected here.'], 422);
        }
        if ($inventoryLog->project_material_id) {
            return response()->json(['message' => 'This movement is already linked to an approved material line.'], 422);
        }

        DB::transaction(function () use ($inventoryLog, $validated) {
            $movement = InventoryLog::with(['financePosting', 'returns.financePosting'])->lockForUpdate()->findOrFail($inventoryLog->id);
            $postedMovement = collect([$movement->financePosting])
                ->merge($movement->returns->pluck('financePosting'))
                ->filter()->first(fn ($posting) => $posting->status === 'posted' || $posting->cost_line_id);
            if ($postedMovement) {
                throw ValidationException::withMessages([
                    'movement' => 'Finance has already posted this movement. Use a Finance reversal and repost so the verified ledger remains immutable.',
                ]);
            }

            $project = \App\Models\Project::findOrFail($movement->project_id);
            $planned = \App\Models\ElementMaterial::with('element.taskMaterialsData.task')
                ->lockForUpdate()->findOrFail($validated['project_material_id']);
            $materialsData = $planned->element?->taskMaterialsData;
            if ((int) $materialsData?->task?->project_enquiry_id !== (int) $project->enquiry_id) {
                throw ValidationException::withMessages(['project_material_id' => 'Choose an approved material line from the same project.']);
            }
            if ((int) $planned->library_material_id !== (int) $movement->material_id) {
                throw ValidationException::withMessages(['project_material_id' => 'The approved line must use the same Material Library item as this stock movement.']);
            }
            $this->assertMaterialsApproved($materialsData);

            $alreadyLinked = (float) InventoryLog::where('project_material_id', $planned->id)
                ->whereIn('type', ['check_out', 'issue', 'consumption'])->sum(DB::raw('ABS(quantity)'))
                - (float) InventoryLog::where('project_material_id', $planned->id)->fulfilmentReopeningReturns()->sum('quantity');
            $movementNet = abs((float) $movement->quantity)
                - (float) $movement->returns()->fulfilmentReopeningReturns()->sum('quantity');
            if ($movementNet > max(0, (float) $planned->quantity - $alreadyLinked) + 0.00001) {
                throw ValidationException::withMessages(['project_material_id' => 'This issue is larger than the quantity remaining on that approved material line.']);
            }

            $auditNote = trim(($movement->notes ? $movement->notes.' · ' : '').'Requirement link corrected by '.auth()->user()->name.': '.$validated['reason']);
            $movement->update(['project_material_id' => $planned->id, 'notes' => $auditNote]);
            $movement->returns()->update(['project_material_id' => $planned->id]);

            $outbox = app(\App\Modules\ProcurementStores\Services\StoresFinanceOutbox::class);
            $outbox->queue($movement->fresh(), 'issue_cost');
            $movement->returns()->get()->each(fn ($return) => $outbox->queue($return, 'return_credit'));
        });

        return response()->json(['message' => 'Movement linked to the approved material line. Stock was not moved again; Finance posting was queued where required.']);
    }

    /**
     * Chronological stock card for one material. balance_after is written in the
     * same transaction as every movement, so this is audit evidence rather than
     * a balance reconstructed from today's stock record.
     */
    public function materialLedger(Request $request): JsonResponse
    {
        if (! auth()->user()?->hasAnyRole(['Stores', 'Manager', 'Finance', 'Finance Manager', 'Accounts', 'Accountant', 'Super Admin'])) {
            return response()->json(['message' => 'You are not permitted to view the material ledger.'], 403);
        }

        $validated = $request->validate([
            'material_id' => 'required|integer|exists:library_materials,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $material = LibraryMaterial::with('baseUom:id,code,name')->findOrFail($validated['material_id']);
        $query = InventoryLog::with(['enteredUom:id,code,name', 'user:id,name', 'project:id,project_id'])
            ->where('material_id', $material->id);

        $openingBalance = 0.0;
        if (! empty($validated['start_date'])) {
            $openingBalance = (float) (InventoryLog::where('material_id', $material->id)
                ->whereDate('logged_at', '<', $validated['start_date'])
                ->orderByDesc('logged_at')->orderByDesc('id')->value('balance_after') ?? 0);
            $query->whereDate('logged_at', '>=', $validated['start_date']);
        }
        if (! empty($validated['end_date'])) {
            $query->whereDate('logged_at', '<=', $validated['end_date']);
        }

        $rows = $query->orderBy('logged_at')->orderBy('id')->get()->map(fn (InventoryLog $log) => [
            'id' => $log->id,
            'date' => ($log->logged_at ?: $log->created_at)?->toIso8601String(),
            'type' => $log->type,
            'reference' => $log->reference_no ?: $log->batch_number,
            'batch_number' => $log->batch_number,
            'quantity' => (float) $log->quantity,
            'balance_after' => (float) $log->balance_after,
            'entered_quantity' => $log->entered_quantity !== null ? (float) $log->entered_quantity : null,
            'entered_uom' => $log->enteredUom ? ['code' => $log->enteredUom->code, 'name' => $log->enteredUom->name] : null,
            'conversion_factor' => $log->uom_conversion_factor !== null ? (float) $log->uom_conversion_factor : null,
            'recorded_unit_cost' => $log->receipt_unit_cost !== null ? (float) $log->receipt_unit_cost : null,
            'project' => $log->project?->project_id,
            'recipient' => $log->recipient_name,
            'notes' => $log->notes,
            'recorded_by' => $log->user?->name,
        ]);

        return response()->json(['data' => [
            'material' => [
                'id' => $material->id, 'code' => $material->material_code,
                'name' => $material->material_name,
                'base_uom' => $material->baseUom?->code ?: $material->unit_of_measure,
                'current_quantity' => (float) ($material->stock?->quantity_on_hand ?? 0),
                'current_unit_cost' => (float) $material->unit_cost,
            ],
            'opening_balance' => $openingBalance,
            'closing_balance' => $rows->isNotEmpty() ? (float) $rows->last()['balance_after'] : $openingBalance,
            'rows' => $rows,
        ]]);
    }

    /**
     * Export movement logs to PDF with filtering
     */
    public function inventoryLogsPdf(Request $request)
    {
        $query = InventoryLog::with(['material', 'enteredUom', 'user', 'project.enquiry']);

        // Apply filters (same as inventoryLogs)
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('material_id')) {
            $query->where('material_id', $request->material_id);
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        // Apply Search (Matches frontend filteredLogs logic)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('batch_number', 'like', "%{$search}%")
                  ->orWhereHas('material', function($mq) use ($search) {
                      $mq->where('material_name', 'like', "%{$search}%")
                         ->orWhere('material_code', 'like', "%{$search}%");
                  })
                  ->orWhereHas('project', function($pq) use ($search) {
                      $pq->where('project_id', 'like', "%{$search}%");
                  });
            });
        }

        // Apply Date Filters (Using logged_at for business logic)
        if ($request->filled('start_date')) {
            $query->whereDate('logged_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('logged_at', '<=', $request->end_date);
        }

        $logs = $query->orderBy('logged_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.inventory-logs', [
            'logs' => $logs,
            'filters' => $request->all()
        ]);

        $fileName = 'inventory-movement-report-' . now()->format('Y-m-d') . '.pdf';
        return $pdf->download($fileName);
    }

    /**
     * Delete an inventory log and revert the stock adjustment.
     *
     * Reusable / board material check-in logs cannot be deleted while board records
     * created from that batch still exist — deleting the log would leave orphaned
     * boards with no matching stock entry.
     */
    public function destroyLog($id): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'Only Stores team members can delete inventory logs.'], 403);
        }

        return DB::transaction(function () use ($id) {
            $log = InventoryLog::findOrFail($id);

            // Guard: refuse if boards were created from this batch
            $isBoardCheckIn = $log->usage_type === 'reusable' && $log->type === 'check_in' && $log->batch_number;
            if ($isBoardCheckIn) {
                $boardCount = Board::where('batch_number', $log->batch_number)
                    ->whereNotIn('status', ['Consumed', 'Scrapped'])
                    ->count();

                if ($boardCount > 0) {
                    return response()->json([
                        'message' => "Cannot delete this log — {$boardCount} active board(s) were created from batch [{$log->batch_number}]. "
                            . 'Scrap or consume all boards in the batch before deleting the log.',
                        'status' => 'error',
                    ], 422);
                }
            }

            // Reverse the stock movement — but NOT for a board check-in log.
            // By the time deletion is allowed for a board batch, every board has been
            // Consumed/Scrapped and each already decremented quantity_on_hand through the
            // board lifecycle (fulfil/allocate/scrap). Subtracting the original check-in
            // quantity again would double-count and drive on-hand negative.
            if (!$isBoardCheckIn) {
                $stock = Stock::where('material_id', $log->material_id)->first();
                if ($stock) {
                    $stock->quantity_on_hand -= $log->quantity;
                    $stock->save();
                }
            }

            $log->delete();

            return response()->json([
                'message' => 'Inventory log deleted and stock reverted successfully',
                'status'  => 'success',
            ]);
        });
    }

    /**
     * Aggregate approved, unissued project material demand against stock and
     * open purchase orders. This is planning information only: it never reserves
     * stock or silently chooses which project receives a constrained item.
     */
    public function materialDemandForecast(): JsonResponse
    {
        if (! auth()->user()?->hasAnyRole(['Stores', 'Procurement', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'You are not permitted to view material demand forecasts.'], 403);
        }

        /*
         * The demand half of this answer now comes from ProjectMaterialDemand,
         * which the material picker reads too. It used to be worked out inline
         * here, and only here — so Stores could see that a material was fully
         * spoken for while a buyer choosing that same material saw nothing of
         * it. One definition, so the two screens cannot disagree.
         */
        $pendingLines = app(\App\Modules\ProcurementStores\Services\ProjectMaterialDemand::class)->pendingLines();

        if ($pendingLines->isEmpty()) {
            return response()->json(['data' => [], 'summary' => [
                'materials' => 0, 'fully_covered' => 0, 'at_risk' => 0, 'covered_by_incoming' => 0,
            ]]);
        }

        $materialIds = $pendingLines->pluck('library_material_id')->unique()->values();
        $materials = \App\Modules\MaterialsLibrary\Models\LibraryMaterial::with('stock')
            ->whereIn('id', $materialIds)->get()->keyBy('id');

        $boardMaterialIds = $materials->filter(fn ($material) => $material->isBoardTrackable())
            ->pluck('id')->unique();
        $boardAvailable = Board::query()->whereIn('library_material_id', $boardMaterialIds)
            ->where('status', 'Available')->selectRaw('library_material_id, COUNT(*) AS quantity')
            ->groupBy('library_material_id')->pluck('quantity', 'library_material_id');

        $incoming = \App\Modules\ProcurementStores\Models\PurchaseOrderItem::query()
            ->whereNotNull('material_id')
            ->whereHas('purchaseOrder', fn ($query) => $query->whereNotIn('status', ['cancelled', 'rejected', 'completed']))
            ->withSum('goodsReceiptNoteItems as received_total', 'received_quantity')
            ->get(['id', 'material_id', 'quantity'])
            ->groupBy('material_id')
            ->map(fn ($rows) => (float) $rows->sum(fn ($row) => max(0, (float) $row->quantity - (float) ($row->received_total ?? 0))));

        $rows = $pendingLines->groupBy('library_material_id')->map(function ($materialLines, $materialId) use ($materials, $boardAvailable, $incoming) {
            $material = $materials->get($materialId);
            // Shaped here rather than in the service: `approved` is the word
            // this screen has always used for the specified quantity, and the
            // service speaks of it as `specified` for callers with no notion of
            // a materials-task approval.
            $projectRows = $materialLines->map(fn (array $line) => [
                'project_id' => $line['project_id'],
                'project_code' => $line['project_code'],
                'project_title' => $line['project_title'],
                'project_material_id' => $line['project_material_id'],
                'element' => $line['element'],
                'approved' => $line['specified'],
                'issued' => $line['issued'],
                'pending' => $line['pending'],
                'required_by' => $line['required_by'],
            ])->values();

            $pending = (float) $projectRows->sum('pending');
            $reserved = (float) ($material?->stock?->quantity_reserved ?? 0);
            $available = $material?->isBoardTrackable()
                ? max(0, (float) ($boardAvailable[$materialId] ?? 0) - $reserved)
                : max(0, (float) ($material?->stock?->quantity_on_hand ?? 0) - $reserved);
            $incomingQuantity = (float) ($incoming[$materialId] ?? 0);
            $immediateShortage = max(0, $pending - $available);
            $projectedShortage = max(0, $pending - $available - $incomingQuantity);
            $status = $immediateShortage <= 0 ? 'fully_covered' : ($projectedShortage <= 0 ? 'covered_by_incoming' : ($available > 0 ? 'partially_covered' : 'shortage'));

            return [
                'material_id' => (int) $materialId,
                'material_name' => $material?->material_name ?? 'Material',
                'material_code' => $material?->material_code,
                'unit' => $material?->unit_of_measure,
                'pending_demand' => round($pending, 4),
                'available' => round($available, 4),
                'incoming' => round($incomingQuantity, 4),
                'immediate_shortage' => round($immediateShortage, 4),
                'projected_shortage' => round($projectedShortage, 4),
                'coverage_percent' => $pending > 0 ? min(100, round(($available / $pending) * 100, 1)) : 100,
                'earliest_required_by' => $projectRows->pluck('required_by')->filter()->sort()->first(),
                'status' => $status,
                'projects' => $projectRows,
            ];
        })->filter()->sortByDesc('projected_shortage')->values();

        return response()->json(['data' => $rows, 'summary' => [
            'materials' => $rows->count(),
            'fully_covered' => $rows->where('status', 'fully_covered')->count(),
            'at_risk' => $rows->whereIn('status', ['partially_covered', 'shortage'])->count(),
            'covered_by_incoming' => $rows->where('status', 'covered_by_incoming')->count(),
        ]]);
    }

    /**
     * Get outstanding reusable items grouped by job/project.
     *
     * Two sub-categories:
     *  - Quantity or serial tracked reusable items linked by project_id FK
     *  - Board materials: issued via board requests; reference_no holds the job_ref
     */
    public function outstandingReusables(): JsonResponse
    {
        // Net the balances in SQL first and hydrate only the scopes that still
        // hold stock. Loading every reusable movement ever recorded made this
        // endpoint's cost track total movement history, when the response only
        // ever describes the projects currently holding something.
        $balanceExpression = "SUM(CASE WHEN type = 'check_out' THEN ABS(quantity) ELSE -quantity END) > 0";

        $outstandingScope = fn () => InventoryLog::query()
            ->where('usage_type', 'reusable')
            ->whereIn('type', ['check_out', 'return']);

        $outstandingProjectIds = $outstandingScope()
            ->whereNotNull('project_id')
            ->groupBy('project_id', 'material_id')
            ->havingRaw($balanceExpression)
            ->pluck('project_id')->unique()->values();

        $outstandingJobRefs = $outstandingScope()
            ->whereNull('project_id')->whereNotNull('reference_no')
            ->groupBy('reference_no', 'material_id')
            ->havingRaw($balanceExpression)
            ->pluck('reference_no')->unique()->values();

        $logs = $outstandingProjectIds->isEmpty() && $outstandingJobRefs->isEmpty()
            ? collect()
            : InventoryLog::with(['material', 'project.enquiry'])
                ->where('usage_type', 'reusable')
                ->whereIn('type', ['check_out', 'return'])
                ->where(fn ($scope) => $scope
                    ->whereIn('project_id', $outstandingProjectIds)
                    ->orWhere(fn ($job) => $job->whereNull('project_id')->whereIn('reference_no', $outstandingJobRefs)))
                ->get();

        // A board issue is closed by physical identity, not only by a `return`
        // quantity. Consumed and scrapped identities close project custody but
        // must never increase stock or create a Finance return credit.
        $boardsByIssue = Board::query()
            ->whereIn('original_issue_log_id', $logs->where('type', 'check_out')->pluck('id'))
            ->get(['id', 'original_issue_log_id', 'status'])
            ->groupBy('original_issue_log_id');

        $materialSummary = function ($groupedLogs) use ($boardsByIssue) {
            return $groupedLogs->groupBy('material_id')->map(function ($ml) use ($boardsByIssue) {
                $material = $ml->first()->material;
                if (!$material) return null;
                $issues = $ml->where('type', 'check_out')->map(function (InventoryLog $issue) use ($ml, $boardsByIssue) {
                    $issueQuantity = abs((float) $issue->quantity);
                    $linkedReturns = (float) $ml->where('type', 'return')
                        ->where('original_issue_log_id', $issue->id)->sum('quantity');
                    $linkedBoards = $boardsByIssue->get($issue->id, collect());
                    $closedBoards = $linkedBoards->whereIn('status', ['Available', 'Quarantine', 'Consumed', 'Scrapped'])->count();
                    // Returned boards and recovered offcuts already have a return
                    // movement. max(), rather than addition, prevents one parent
                    // consumption plus its offcut recovery closing two units.
                    $resolved = $linkedBoards->isNotEmpty()
                        ? min($issueQuantity, max($linkedReturns, (float) $closedBoards))
                        : min($issueQuantity, $linkedReturns);

                    return [
                        'id' => $issue->id,
                        'batch_number' => $issue->batch_number,
                        'issued_at' => ($issue->logged_at ?? $issue->created_at)?->toIso8601String(),
                        'recipient_name' => $issue->recipient_name,
                        'issued' => $issueQuantity,
                        'returned' => $linkedReturns,
                        'resolved' => $resolved,
                        'remaining' => max(0, $issueQuantity - $resolved),
                    ];
                })->values();
                $issued = (float) $issues->sum('issued');
                $returned = (float) $issues->sum('returned');
                $resolved = (float) $issues->sum('resolved');
                $balance = (float) $issues->sum('remaining');
                $openIssues = $issues->filter(fn (array $issue) => $issue['remaining'] > 0)->values();
                return $balance > 0 ? [
                    'material_id'   => $material->id,
                    'material_name' => $material->material_name,
                    'material_code' => $material->material_code,
                    'unit'          => $material->unit_of_measure,
                    'issued'        => $issued,
                    'returned'      => $returned,
                    'resolved'      => $resolved,
                    'balance'       => $balance,
                    'issues'        => $openIssues,
                ] : null;
            })->filter()->values();
        };

        // 1. Project-linked reusables
        $byProject = $logs->filter(fn($l) => $l->project_id)
            ->groupBy('project_id')
            ->map(function ($projectLogs, $projectId) use ($materialSummary) {
                $project = $projectLogs->first()->project;
                if (!$project) return null;
                $items = $materialSummary($projectLogs);
                return $items->count() > 0 ? [
                    'ref_type'     => 'project',
                    'project_id'   => $projectId,
                    'project_code' => $project->project_id ?? 'N/A',
                    'project_title'=> $project->enquiry?->title ?? 'N/A',
                    'oldest_issued_at' => $projectLogs->where('type', 'check_out')->min(fn ($log) => $log->logged_at ?? $log->created_at)?->toIso8601String(),
                    'days_outstanding' => (int) optional($projectLogs->where('type', 'check_out')->min(fn ($log) => $log->logged_at ?? $log->created_at))->diffInDays(now()),
                    'custodians' => $projectLogs->where('type', 'check_out')->pluck('recipient_name')->filter()->unique()->values(),
                    'items'        => $items,
                ] : null;
            })->filter()->values();

        // 2. Board materials issued by job_ref (stored in reference_no by BoardRequestController)
        $byJob = $logs->filter(fn($l) => !$l->project_id && $l->reference_no)
            ->groupBy('reference_no')
            ->map(function ($jobLogs, $jobRef) use ($materialSummary) {
                $items = $materialSummary($jobLogs);
                return $items->count() > 0 ? [
                    'ref_type' => 'job',
                    'job_ref'  => $jobRef,
                    'oldest_issued_at' => $jobLogs->where('type', 'check_out')->min(fn ($log) => $log->logged_at ?? $log->created_at)?->toIso8601String(),
                    'days_outstanding' => (int) optional($jobLogs->where('type', 'check_out')->min(fn ($log) => $log->logged_at ?? $log->created_at))->diffInDays(now()),
                    'custodians' => $jobLogs->where('type', 'check_out')->pluck('recipient_name')->filter()->unique()->values(),
                    'items'    => $items,
                ] : null;
            })->filter()->values();

        return response()->json([
            'data'   => $byProject->merge($byJob)->values(),
            'status' => 'success',
        ]);
    }
}
