<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\InventoryLot;
use App\Modules\ProcurementStores\Models\InventorySerialItem;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Models\StoresFinancePosting;
use App\Modules\ProcurementStores\Jobs\ProcessStoresFinancePosting;
use App\Modules\ProcurementStores\Services\BoardRegistrationService;
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

    private function validateControlledMovement(Request $request, LibraryMaterial $material, string $type): void
    {
        $quantity = (float) $request->quantity;
        if ($material->is_serialized) {
            if ($quantity !== (float) (int) $quantity) {
                throw ValidationException::withMessages(['quantity' => 'Serialized stock must move in whole units.']);
            }
            $field = $type === 'check_in' ? 'serial_numbers' : 'serial_item_ids';
            $values = array_values(array_filter($request->input($field, []), fn ($value) => $value !== null && $value !== ''));
            if (count($values) !== (int) $quantity || count($values) !== count(array_unique($values))) {
                throw ValidationException::withMessages([$field => "Provide exactly {$quantity} unique serialized units."]);
            }
            $request->merge([$field => $values]);
        }
        if ($type === 'return' && $material->is_batch_controlled && !$material->is_serialized && !$request->filled('inventory_lot_id')) {
            throw ValidationException::withMessages(['inventory_lot_id' => 'Select the original lot for this return.']);
        }
    }

    /**
     * Diagnostic test endpoint.
     */
    public function test(): JsonResponse
    {
        return response()->json([
            'message' => 'Procurement and Stores Module is active',
            'status' => 'success'
        ]);
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
        $summaryMaterials = (clone $query)->get();
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

        $summaryRows = $summaryMaterials->map($formatMaterial);
        $paginator->getCollection()->transform(function ($material) use ($formatMaterial) {
            $row = $formatMaterial($material);
            unset($row['_stock_value']);
            return $row;
        });

        $summary = [
            'total_items' => $summaryRows->count(),
            'stocked_item_count' => $summaryRows->where('is_stocked', true)->count(),
            'unstocked_item_count' => $summaryRows->where('is_stocked', false)->count(),
            'total_value' => round((float) $summaryRows->sum('_stock_value'), 2),
            'low_stock_count' => $summaryRows->filter(fn ($row) =>
                $row['min_stock_level'] > 0 && $row['available'] <= $row['min_stock_level']
            )->count(),
            'out_of_stock_count' => $summaryRows->filter(fn ($row) =>
                $row['is_stocked'] && $row['available'] <= 0
            )->count(),
            'board_item_count' => $summaryRows->where('board_trackable', true)->count(),
            'reusable_item_count' => $summaryRows->filter(fn ($row) =>
                $row['issue_disposition'] === 'returnable' && !$row['board_trackable']
            )->count(),
        ];

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
    public function checkIn(Request $request): JsonResponse
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

        $material = LibraryMaterial::with(['materialCategory.parent', 'workstation'])->findOrFail($request->material_id);

        if (($material->item_status ?? 'Active') !== 'Active') {
            return response()->json(['message' => "Only Active Material Library items can be received. This item is {$material->item_status}."], 422);
        }

        if ($material->is_batch_controlled && !$request->filled('lot_number')) {
            return response()->json(['message' => 'A supplier or internal lot number is required for this material.'], 422);
        }
        if ($material->is_expiry_controlled && !$request->filled('expiry_date')) {
            return response()->json(['message' => 'An expiry date is required for this material.'], 422);
        }
        $this->validateControlledMovement($request, $material, 'check_in');

        if ($material->isBoardTrackable() && (float) $request->quantity !== (float) (int) $request->quantity) {
            return response()->json([
                'message' => 'Board sheets must be received as a whole number because each sheet receives its own tracking code.',
            ], 422);
        }

        // An unpriced board is an unissuable board. Catch it at receipt, while the
        // delivery note is still in hand, rather than at the materials desk.
        if ($material->isBoardTrackable()
            && ! $request->filled('receipt_unit_cost')
            && (float) $material->unit_cost <= 0
            && (float) ($material->default_unit_cost ?? 0) <= 0) {
            return response()->json([
                'message' => "[{$material->material_name}] has no price yet. Enter the receipt price per board, or set a "
                    . 'default price on the material in the Material Library — boards received without a value cannot be '
                    . 'issued to a project.',
            ], 422);
        }

        $log    = null;
        $boards = [];

        // Wrap adjustStock + createBoardRecords in ONE transaction so a board
        // creation failure rolls back the stock increment, preventing a state
        // where quantity_on_hand is incremented but no board records exist.
        DB::transaction(function () use ($request, $material, &$log, &$boards) {
            $grnItem = null;
            if ($request->filled('grn_item_id')) {
                $grnItem = GoodsReceiptNoteItem::with(['goodsReceiptNote', 'purchaseOrderItem', 'inspection'])->lockForUpdate()->findOrFail($request->integer('grn_item_id'));
                if ((int) $grnItem->material_id !== (int) $request->material_id || ! $grnItem->accepted) {
                    throw ValidationException::withMessages(['grn_item_id' => 'This GRN line does not match the selected accepted material.']);
                }
                if ($grnItem->inventory_log_id || $grnItem->stock_status === 'posted') {
                    throw ValidationException::withMessages(['grn_item_id' => 'This GRN line has already been added to Stores stock.']);
                }
                // The PO line is an immutable buying-unit snapshot. Do not make
                // an older approved receipt change meaning when the catalogue's
                // current buying unit is edited later.
                $expectedUomId = (int) ($grnItem->purchaseOrderItem?->uom_id
                    ?: $material->purchase_uom_id
                    ?: $material->base_uom_id);
                if ((int) ($request->entered_uom_id ?: $material->base_uom_id) !== $expectedUomId) {
                    throw ValidationException::withMessages(['entered_uom_id' => 'Complete this GRN line in the buying unit recorded on the purchase order.']);
                }
            }

            $service = new InventoryService();
            $meta = $request->all();
            if ($grnItem) {
                $meta['expected_entered_uom_id'] = $expectedUomId;
                $meta['reference_no'] = $grnItem->goodsReceiptNote?->grn_number;
                $meta['notes'] = trim(($request->notes ? $request->notes.' · ' : '')."Completed from GRN {$meta['reference_no']}");
            }
            $log = $service->adjustStock(
                $request->material_id,
                $request->quantity,
                'check_in',
                $meta
            );

            if ($grnItem) {
                $factor = (float) ($log->uom_conversion_factor ?: 1);
                $approvedReceiptQuantity = $grnItem->inspection
                    ? (float) $grnItem->inspection->accepted_quantity
                    : (float) $grnItem->received_quantity;
                $expectedStockQuantity = $approvedReceiptQuantity * $factor;
                if (abs(abs((float) $log->quantity) - $expectedStockQuantity) > 0.00001) {
                    throw ValidationException::withMessages([
                        'quantity' => "Receive the full quantity approved for Stores ({$approvedReceiptQuantity}); rejected or quarantined quantities must not enter available stock.",
                    ]);
                }
            }

            if ($material->isBoardTrackable()) {
                $registration = new BoardRegistrationService();
                $boards = $registration->createBoardRecords(
                    material:    $material,
                    quantity:    (int) $request->quantity,
                    batchNumber: $log->batch_number,
                    length:      $request->length    ?? null,
                    width:       $request->width     ?? null,
                    thickness:   $request->thickness ?? null,
                    userId:      auth()->id(),
                    // What this delivery actually cost per board. Without it the
                    // boards inherit a catalogue average that is still zero on a
                    // first receipt, and every one of them is unissuable.
                    unitValue:   $request->filled('receipt_unit_cost') ? (float) $request->receipt_unit_cost : null,
                );
                $log->update(['usage_type' => 'reusable']);
            }

            if ($grnItem) {
                // Manually checking a GRN line into Stock is a store
                // confirmation too — see GoodsReceiptNoteController::store().
                $grnItem->update([
                    'entered_uom_id' => $request->entered_uom_id ?: $material->base_uom_id,
                    'stock_quantity' => abs((float) $log->quantity),
                    'receipt_unit_cost' => $request->receipt_unit_cost,
                    'stock_status' => 'posted',
                    'inventory_log_id' => $log->id,
                    'unit_price' => $request->receipt_unit_cost,
                    'store_status' => 'confirmed',
                    'confirmed_by' => auth()->id(),
                    'confirmed_at' => now(),
                ]);
            }
        });

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
                'material'      => ['name' => $material->material_name, 'code' => $material->material_code],
            ], $boards),
        ]);
    }

    /**
     * Process a stock check-out (Deduct from inventory)
     *
     * Individually tracked board/sheet materials must NOT be checked out through
     * this generic endpoint. Reusable tools still use this normal quantity flow.
     */
    public function checkOut(Request $request): JsonResponse
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

        $material = LibraryMaterial::with(['materialCategory.parent', 'uomConversions'])->find($request->material_id);
        if ($material?->isBoardTrackable()) {
            return response()->json([
                'message' => "'{$material->material_name}' is a tracked board material. "
                    . 'Issue it via a Board Request so individual boards are assigned to the job.',
                'status' => 'error',
                'redirect' => 'board_request',
            ], 422);
        }
        $this->validateControlledMovement($request, $material, 'check_out');

        if ($request->filled('project_material_id')) {
            $project = \App\Models\Project::findOrFail($request->project_id);
            $planned = \App\Models\ElementMaterial::with('element.taskMaterialsData.task')->findOrFail($request->project_material_id);
            $materialsData = $planned->element?->taskMaterialsData;
            // Validate identity before approval so a line from another project
            // can never be presented as an approval problem.
            if ((int) $materialsData?->task?->project_enquiry_id !== (int) $project->enquiry_id
                || (int) $planned->library_material_id !== (int) $material->id) {
                throw ValidationException::withMessages(['project_material_id' => 'This material line does not belong to the selected project.']);
            }
            $this->assertMaterialsApproved($materialsData);
            $issued = (float) InventoryLog::where('project_material_id', $planned->id)
                ->whereIn('type', ['check_out', 'issue', 'consumption'])->sum(DB::raw('ABS(quantity)'))
                - (float) InventoryLog::where('project_material_id', $planned->id)->fulfilmentReopeningReturns()->sum('quantity');
            if ((float) $request->quantity > max(0, (float) $planned->quantity - $issued)) {
                throw ValidationException::withMessages(['quantity' => 'Quantity exceeds the remaining approved project requirement.']);
            }
        }

        $service = new InventoryService();

        // Sufficiency is checked inside adjustStock's row lock. Testing it here
        // with an unlocked read only produced a second, racier answer.
        $log = $service->adjustStock(
            $request->material_id,
            -$request->quantity,
            'check_out',
            $request->all()
        );

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
     * Process a stock return (Add back to inventory from project)
     */
    public function returns(Request $request): JsonResponse
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

        // Board materials must be returned through the Board Lifecycle endpoint so that
        // individual Board records transition back to Available and stock stays in sync.
        $material = LibraryMaterial::with(['materialCategory.parent', 'uomConversions'])->find($request->material_id);
        if ($material?->isBoardTrackable()) {
            return response()->json([
                'message'  => "'{$material->material_name}' is a tracked board material. "
                    . 'Return individual boards via POST /boards/{id}/transition with status=Available.',
                'status'   => 'error',
                'redirect' => 'board_lifecycle',
            ], 422);
        }
        $this->validateControlledMovement($request, $material, 'return');

        $returnQuantityBase = (float) $request->quantity;
        if ($request->filled('entered_uom_id') && (int) $request->entered_uom_id !== (int) $material->base_uom_id) {
            $factor = (float) ($material->uomConversions
                ->first(fn ($row) => (int) $row->from_uom_id === (int) $request->entered_uom_id
                    && (int) $row->to_uom_id === (int) $material->base_uom_id)?->factor ?? 0);
            if ($factor <= 0) {
                throw ValidationException::withMessages(['entered_uom_id' => 'This return unit has no conversion to the stock unit.']);
            }
            $returnQuantityBase *= $factor;
        }

        $log = DB::transaction(function () use ($request, $returnQuantityBase) {
            $issue = InventoryLog::query()->lockForUpdate()
                ->find($request->integer('original_issue_log_id'));
            if (! $issue) {
                throw ValidationException::withMessages([
                    'original_issue_log_id' => 'This issue is no longer available. Refresh the project custody list and select the current issue.',
                ]);
            }

            if (! in_array($issue->type, ['check_out', 'issue', 'consumption'], true)) {
                throw ValidationException::withMessages(['original_issue_log_id' => 'Select an original stock issue.']);
            }
            if ((int) $issue->material_id !== $request->integer('material_id')) {
                throw ValidationException::withMessages(['material_id' => 'The returned material must match the original issue.']);
            }
            if ($issue->usage_type !== 'reusable') {
                throw ValidationException::withMessages([
                    'original_issue_log_id' => 'Consumable issues are final and cannot be returned to stock.',
                ]);
            }

            $issued = abs((float) $issue->quantity);
            $alreadyReturned = (float) InventoryLog::where('original_issue_log_id', $issue->id)
                ->where('type', 'return')->sum('quantity');
            if ($alreadyReturned + $returnQuantityBase > $issued + 0.00001) {
                throw ValidationException::withMessages([
                    'quantity' => 'Return quantity exceeds the unreturned quantity from the original issue.',
                ]);
            }

            $meta = $request->all();
            $meta['project_id'] = $issue->project_id;
            $meta['project_material_id'] = $issue->project_material_id;
            $meta['reference_no'] = $issue->reference_no;

            return app(InventoryService::class)->adjustStock(
                $request->integer('material_id'),
                (float) $request->quantity,
                'return',
                $meta,
            );
        });

        return response()->json([
            'message' => 'Material returned successfully',
            'data' => $log,
            'status' => 'success'
        ]);
    }

    /**
     * Mark stock as defective (Deduct from inventory)
     */
    public function markDefective(Request $request): JsonResponse
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

        // Board materials must be scrapped through the Board Lifecycle endpoint so that
        // individual Board records transition to Scrapped and stock stays in sync.
        $material = LibraryMaterial::with('materialCategory.parent')->find($request->material_id);
        if ($material?->isBoardTrackable()) {
            return response()->json([
                'message'  => "'{$material->material_name}' is a tracked board material. "
                    . 'Scrap individual boards via POST /boards/{id}/transition with status=Scrapped.',
                'status'   => 'error',
                'redirect' => 'board_lifecycle',
            ], 422);
        }
        $this->validateControlledMovement($request, $material, 'defective');

        $service = new InventoryService();

        // Sufficiency is checked inside adjustStock's row lock — see checkOut().
        $log = $service->adjustStock(
            $request->material_id, 
            -$request->quantity, // Negative for defective removal
            'defective', 
            $request->all()
        );

        return response()->json([
            'message' => 'Stock marked as defective and removed from inventory',
            'data' => $log,
            'status' => 'success'
        ]);
    }

    /**
     * Process batch check-in (multiple materials with same batch number)
     */
    public function batchCheckIn(Request $request): JsonResponse
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
            'warehouse_code' => 'sometimes|string',
            'logged_at' => 'nullable|date'
        ]);

        $service    = new InventoryService();
        $logs       = [];
        $allBoards  = [];
        $batchNumber = null;

        $batchMaterials = LibraryMaterial::with(['materialCategory.parent', 'workstation'])
            ->whereIn('id', collect($request->items)->pluck('material_id'))
            ->get()
            ->keyBy('id');

        foreach ($request->items as $item) {
            $material = $batchMaterials->get($item['material_id']);
            if ($material?->isBoardTrackable() && (float) $item['quantity'] !== (float) (int) $item['quantity']) {
                return response()->json([
                    'message' => "Board sheets for '{$material->material_name}' must be received as a whole number.",
                ], 422);
            }
            if ($material?->isBoardTrackable()
                && ! (isset($item['receipt_unit_cost']) && (float) $item['receipt_unit_cost'] > 0)
                && (float) $material->unit_cost <= 0
                && (float) ($material->default_unit_cost ?? 0) <= 0) {
                return response()->json([
                    'message' => "'{$material->material_name}' has no price yet. Enter the receipt price per board, or set a "
                        . 'default price on the material in the Material Library — boards received without a value cannot be '
                        . 'issued to a project.',
                ], 422);
            }
        }

        // Single outer transaction: if any item's board creation fails the
        // entire batch rolls back — no partial stock increments without records.
        DB::transaction(function () use ($request, $service, $batchMaterials, &$logs, &$allBoards, &$batchNumber) {
            $batchNumber = $service->generateBatchNumber();

            foreach ($request->items as $item) {
                $meta = array_merge($item, [
                    'batch_number'   => $batchNumber,
                    'warehouse_code' => $request->warehouse_code ?? 'MAIN',
                    'logged_at'      => $request->logged_at ?? now(),
                ]);

                $log    = $service->adjustStock($item['material_id'], $item['quantity'], 'check_in', $meta);
                $logs[] = $log;

                $material = $batchMaterials->get($item['material_id']);

                if ($material?->isBoardTrackable()) {
                    $registration = new BoardRegistrationService();
                    $boards = $registration->createBoardRecords(
                        material:    $material,
                        quantity:    (int) $item['quantity'],
                        batchNumber: $batchNumber,
                        length:      $item['length']    ?? null,
                        width:       $item['width']     ?? null,
                        thickness:   $item['thickness'] ?? null,
                        userId:      auth()->id(),
                        unitValue:   isset($item['receipt_unit_cost']) ? (float) $item['receipt_unit_cost'] : null,
                    );
                    $log->update(['usage_type' => 'reusable']);
                    $allBoards = array_merge($allBoards, $boards);
                }
            }
        });

        return response()->json([
            'message'         => 'Batch check-in processed successfully',
            'batch_number'    => $batchNumber,
            'items_processed' => count($logs),
            'data'            => $logs,
            'boards_created'  => count($allBoards),
            'status'          => 'success',
        ]);
    }

    /**
     * Process batch check-out (multiple materials with same batch number)
     */
    public function batchCheckOut(Request $request): JsonResponse
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
            'requestor_name' => 'required|string|max:255',
            'reference_no' => 'nullable|string|max:255',
            'project_id' => 'nullable|exists:projects,id',
            'logged_at' => 'nullable|date'
        ]);

        $service = new InventoryService();
        
        // Validate all items before processing any — board guard first, then stock
        foreach ($request->items as $item) {
            $material = LibraryMaterial::with('materialCategory.parent')->find($item['material_id']);

            // Board materials must go through the board request flow so individual
            // Board records are allocated and stock stays in sync.
            if ($material?->isBoardTrackable()) {
                return response()->json([
                    'message'  => "'{$material->material_name}' is a tracked board material. "
                        . 'Issue it via a Board Request so individual boards are assigned to the job.',
                    'status'   => 'error',
                    'redirect' => 'board_request',
                ], 422);
            }

            // Availability is validated atomically by InventoryService after
            // converting the entered issue unit to the stock unit.
        }

        // All items validated, process the batch
        $batchNumber = $service->generateBatchNumber();
        // One project pick is one operational decision: either every selected
        // line posts or none does. This avoids a half-issued preparation list.
        $logs = DB::transaction(function () use ($request, $service, $batchNumber) {
            $posted = [];
            foreach ($request->items as $item) {
                $material = LibraryMaterial::with(['materialCategory.parent', 'uomConversions'])->findOrFail($item['material_id']);
                $movementQuantity = $this->quantityInBaseUnit($material, (float) $item['quantity'], $item['entered_uom_id'] ?? null);
                if (! empty($item['project_material_id'])) {
                    if (! $request->project_id) {
                        throw ValidationException::withMessages(['project_id' => 'A project is required for approved material issues.']);
                    }

                    $project = \App\Models\Project::findOrFail($request->project_id);
                    $planned = \App\Models\ElementMaterial::with('element.taskMaterialsData.task')
                        ->lockForUpdate()->findOrFail($item['project_material_id']);
                    $materialsData = $planned->element?->taskMaterialsData;

                    // Project ownership, catalogue identity and departmental
                    // sign-off are all authoritative server-side controls.
                    if ((int) $materialsData?->task?->project_enquiry_id !== (int) $project->enquiry_id) {
                        throw ValidationException::withMessages([
                            'items' => "{$planned->description} does not belong to this project.",
                        ]);
                    }
                    if ((int) $planned->library_material_id !== (int) $item['material_id']) {
                        throw ValidationException::withMessages([
                            'items' => "{$planned->description} is linked to a different Material Library item.",
                        ]);
                    }
                    $this->assertMaterialsApproved($materialsData);

                    $netIssued = (float) InventoryLog::where('project_material_id', $planned->id)
                        ->whereIn('type', ['check_out', 'issue', 'consumption'])->sum(DB::raw('ABS(quantity)'))
                        - (float) InventoryLog::where('project_material_id', $planned->id)
                            ->fulfilmentReopeningReturns()->sum('quantity');

                    // Pre-linkage issues are allocated FIFO across repeated
                    // approved lines for the same catalogue material. This keeps
                    // historical projects truthful without charging the same
                    // legacy issue to every repeated line.
                    $legacyIssued = (float) InventoryLog::where('project_id', $project->id)
                        ->where('material_id', $planned->library_material_id)
                        ->whereNull('project_material_id')
                        ->whereIn('type', ['check_out', 'issue', 'consumption'])
                        ->sum(DB::raw('ABS(quantity)'))
                        - (float) InventoryLog::where('project_id', $project->id)
                            ->where('material_id', $planned->library_material_id)
                            ->whereNull('project_material_id')
                            ->fulfilmentReopeningReturns()->sum('quantity');
                    $earlierRequirement = (float) \App\Models\ElementMaterial::query()
                        ->where('library_material_id', $planned->library_material_id)
                        ->where('is_included', true)
                        ->where('id', '<', $planned->id)
                        ->whereHas('element', fn ($query) => $query->where('task_materials_data_id', $materialsData->id))
                        ->sum('quantity');
                    $legacyForThisLine = min(
                        (float) $planned->quantity,
                        max(0, $legacyIssued - $earlierRequirement),
                    );
                    $netIssued += $legacyForThisLine;
                    $remaining = max(0, (float) $planned->quantity - $netIssued);
                    if ($movementQuantity > $remaining + 0.00001) {
                        throw ValidationException::withMessages([
                            'items' => "{$planned->description} has only {$remaining} remaining on the approved requirement.",
                        ]);
                    }

                }

                $meta = array_merge($item, [
                    'batch_number' => $batchNumber,
                    'project_id' => $request->project_id ?? null,
                    'recipient_name' => $item['requestor'] ?? $request->requestor_name ?? null,
                    'reference_no' => $item['reference_no'] ?? $request->reference_no ?? null,
                    'notes' => $item['notes'] ?? 'Project material issue',
                    'logged_at' => $request->logged_at ?? now(),
                ]);

                $posted[] = $service->adjustStock(
                    $item['material_id'],
                    -$item['quantity'],
                    'check_out',
                    $meta,
                );
            }

            return $posted;
        });

        return response()->json([
            'message' => 'Batch check-out processed successfully',
            'batch_number' => $batchNumber,
            'items_processed' => count($logs),
            'data' => $logs,
            'status' => 'success'
        ]);
    }

    private function assertMaterialsApproved($materialsData): void
    {
        if (! (bool) data_get($materialsData?->project_info, 'approval_status.all_approved', false)) {
            throw ValidationException::withMessages([
                'project_material_id' => 'Project Officer and Production must sign off the material list before Stores can issue it.',
            ]);
        }
    }

    /** Convert a user-entered movement quantity for validation against base-unit requirements. */
    private function quantityInBaseUnit(LibraryMaterial $material, float $quantity, mixed $enteredUomId): float
    {
        if (! $enteredUomId || (int) $enteredUomId === (int) $material->base_uom_id) {
            return $quantity;
        }

        if ((int) $enteredUomId !== (int) $material->issue_uom_id) {
            throw ValidationException::withMessages(['items' => "{$material->material_name} must be issued in its stock unit or configured issuing unit."]);
        }

        $factor = (float) ($material->uomConversions
            ->first(fn ($row) => (int) $row->from_uom_id === (int) $enteredUomId
                && (int) $row->to_uom_id === (int) $material->base_uom_id)?->factor ?? 0);
        if ($factor <= 0) {
            throw ValidationException::withMessages(['items' => "{$material->material_name} has no valid issuing-unit conversion."]);
        }

        return $quantity * $factor;
    }

    /**
     * Fetch recent stock movement logs with filtering
     */
    public function inventoryLogs(Request $request): JsonResponse
    {
        // materialCategory.parent feeds the appended board_trackable attribute,
        // which the Project Material Desk uses to keep tracked boards out of the
        // bulk return list. Without it that accessor lazy-loads once per row.
        // projectMaterial.element carries the element a movement served, so the
        // desk can group custody and history the same way it groups the issue
        // list. Eager-loaded because the alternative is a query per row.
        $query = InventoryLog::with([
            'material.materialCategory.parent', 'enteredUom', 'user', 'project.enquiry',
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

        $requirements = \App\Models\ElementMaterial::with([
                'libraryMaterial.stock',
                'element.taskMaterialsData.task',
            ])
            ->where('is_included', true)
            ->whereNotNull('library_material_id')
            ->orderBy('id')
            ->get()
            ->filter(fn ($line) => (bool) data_get($line->element?->taskMaterialsData?->project_info, 'approval_status.all_approved', false));

        $enquiryIds = $requirements->map(fn ($line) => $line->element?->taskMaterialsData?->task?->project_enquiry_id)
            ->filter()->unique()->values();
        $projects = \App\Models\Project::with('enquiry:id,title,client_id')
            ->whereIn('enquiry_id', $enquiryIds)->get()->keyBy('enquiry_id');
        $projectIds = $projects->pluck('id');

        $movements = InventoryLog::query()
            ->whereIn('project_id', $projectIds)
            ->whereIn('type', ['check_out', 'issue', 'consumption', 'return'])
            ->get(['id', 'type', 'quantity', 'material_id', 'project_id', 'project_material_id', 'original_issue_log_id', 'return_kind', 'notes']);

        $issueByLine = $movements->whereIn('type', ['check_out', 'issue', 'consumption'])
            ->whereNotNull('project_material_id')->groupBy('project_material_id')
            ->map(fn ($rows) => (float) $rows->sum(fn ($row) => abs((float) $row->quantity)));
        $reopeningReturnByLine = $movements->where('type', 'return')
            ->filter(fn ($row) => $row->return_kind !== 'recovered_offcut' && ! str_starts_with((string) $row->notes, 'Offcut '))
            ->whereNotNull('project_material_id')->groupBy('project_material_id')
            ->map(fn ($rows) => (float) $rows->sum('quantity'));

        $boardMaterialIds = $requirements->filter(fn ($line) => $line->libraryMaterial?->isBoardTrackable())
            ->pluck('library_material_id')->unique();
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

        $rows = $requirements->groupBy('library_material_id')->map(function ($materialLines, $materialId) use ($projects, $issueByLine, $reopeningReturnByLine, $boardAvailable, $incoming) {
            $material = $materialLines->first()->libraryMaterial;
            $projectRows = $materialLines->map(function ($line) use ($projects, $issueByLine, $reopeningReturnByLine) {
                $task = $line->element?->taskMaterialsData?->task;
                $project = $projects->get($task?->project_enquiry_id);
                if (! $project) return null;
                $approved = (float) $line->quantity;
                $issued = max(0, (float) ($issueByLine[$line->id] ?? 0) - (float) ($reopeningReturnByLine[$line->id] ?? 0));
                $pending = max(0, $approved - $issued);
                return $pending > 0 ? [
                    'project_id' => $project->id,
                    'project_code' => $project->project_id,
                    'project_title' => $project->enquiry?->title ?? 'Project',
                    'project_material_id' => $line->id,
                    'element' => $line->element?->name ?? 'Project materials',
                    'approved' => round($approved, 4),
                    'issued' => round($issued, 4),
                    'pending' => round($pending, 4),
                    'required_by' => $project->start_date?->toDateString(),
                ] : null;
            })->filter()->values();
            if ($projectRows->isEmpty()) return null;

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
