<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Services\BoardRegistrationService;
use App\Modules\ProcurementStores\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcurementStoresController extends Controller
{
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
        $query = LibraryMaterial::with(['workstation', 'stock', 'materialCategory.parent']);

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

        $paginator = $query->latest()->paginate($request->get('per_page', 50));

        // For board-tracked (individual) materials, quantity_on_hand on the stock row
        // also counts ungraded Quarantine boards, which overstates what's actually
        // issuable. Derive the figures from board records instead so the master
        // inventory matches the board registry:
        //   on_hand   = boards physically in stores (Available + Quarantine)
        //   available = ready-to-issue (Available) minus soft reservations
        $boardMaterialIds = $paginator->getCollection()
            ->filter(fn($m) => optional($m->stock)->tracking_mode === Stock::TRACK_BY_AREA)
            ->pluck('id');

        $boardCounts = $boardMaterialIds->isNotEmpty()
            ? Board::whereIn('library_material_id', $boardMaterialIds)
                ->selectRaw("library_material_id,
                    SUM(CASE WHEN status = 'Available' THEN 1 ELSE 0 END) AS available_cnt,
                    SUM(CASE WHEN status IN ('Available', 'Quarantine') THEN 1 ELSE 0 END) AS in_stores_cnt")
                ->groupBy('library_material_id')
                ->get()
                ->keyBy('library_material_id')
            : collect();

        $paginator->getCollection()->transform(function ($material) use ($boardCounts) {
            $isBoard = optional($material->stock)->tracking_mode === Stock::TRACK_BY_AREA;
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
                'category'          => $material->category,
                'subcategory'       => $material->subcategory,
                'unit_of_measure'   => $material->unit_of_measure,
                'unit_cost'         => $material->unit_cost,
                'workstation'       => $material->workstation,
                'workstation_name'  => $material->workstation?->name ?? 'N/A',
                'attributes'        => $material->attributes ?? [],
                'is_active'         => $material->is_active,
                'notes'             => $material->notes,
                'material_type'     => $material->material_type ?? 'consumable',
                'board_trackable'   => $material->isBoardTrackable(),
                'stock_handling'    => $material->isBoardTrackable()
                    ? 'individual_board'
                    : ($material->material_type === 'reusable' ? 'reusable_item' : 'quantity'),
                'quantity_on_hand'  => $onHand,
                'quantity_reserved' => $reserved,
                'available'         => $available,
                'min_stock_level'   => (float) ($material->stock?->min_stock_level ?? 0),
                'location'          => $material->stock?->location_bin ?? 'Not Set',
            ];
        });

        return response()->json([
            'data'   => $paginator,
            'status' => 'success',
        ]);
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
            'warehouse_code' => 'sometimes|string',
            'location' => 'nullable|string',
            'reference_no' => 'nullable|string',
            'notes' => 'nullable|string',
            'usage_type' => 'nullable|string|in:consumable,reusable',
            'type' => 'nullable|string',
            'logged_at' => 'nullable|date'
        ]);

        $material = LibraryMaterial::with(['materialCategory.parent', 'workstation'])->findOrFail($request->material_id);

        if ($material->isBoardTrackable() && (float) $request->quantity !== (float) (int) $request->quantity) {
            return response()->json([
                'message' => 'Board sheets must be received as a whole number because each sheet receives its own tracking code.',
            ], 422);
        }

        $log    = null;
        $boards = [];

        // Wrap adjustStock + createBoardRecords in ONE transaction so a board
        // creation failure rolls back the stock increment, preventing a state
        // where quantity_on_hand is incremented but no board records exist.
        DB::transaction(function () use ($request, $material, &$log, &$boards) {
            $service = new InventoryService();
            $log = $service->adjustStock(
                $request->material_id,
                $request->quantity,
                'check_in',
                $request->all()
            );

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
                );
                $log->update(['usage_type' => 'reusable']);
            }
        });

        return response()->json([
            'message'      => 'Stock updated successfully',
            'data'         => $log,
            'batch_number' => $log->batch_number,
            'status'       => 'success',
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
            'project_id' => 'nullable|exists:projects,id',
            'notes' => 'nullable|string',
            'logged_at' => 'nullable|date'
        ]);

        $material = LibraryMaterial::with('materialCategory.parent')->find($request->material_id);
        if ($material?->isBoardTrackable()) {
            return response()->json([
                'message' => "'{$material->material_name}' is a tracked board material. "
                    . 'Issue it via a Board Request so individual boards are assigned to the job.',
                'status' => 'error',
                'redirect' => 'board_request',
            ], 422);
        }

        $service = new InventoryService();

        $stock = Stock::where('material_id', $request->material_id)->first();
        if (!$stock || ($stock->quantity_on_hand - $stock->quantity_reserved) < $request->quantity) {
            return response()->json([
                'message' => 'Insufficient stock for this operation',
                'status' => 'error'
            ], 422);
        }

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
     * Update stock policy settings: reorder level, physical location, warehouse.
     * Stock quantity is intentionally NOT adjustable here — it moves only through
     * inventory transactions (check_in, check_out, return, defective).
     */
    public function updateStockSettings(Request $request): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'Only Stores team members can update stock settings.'], 403);
        }

        $request->validate([
            'material_id'     => 'required|exists:library_materials,id',
            'min_stock_level' => 'nullable|numeric|min:0',
            'location_bin'    => 'nullable|string|max:50',
            'warehouse_code'  => 'nullable|string|max:20',
        ]);

        $stock = Stock::firstOrCreate(
            ['material_id' => $request->material_id],
            ['quantity_on_hand' => 0, 'quantity_reserved' => 0]
        );

        if ($request->has('min_stock_level')) {
            $stock->min_stock_level = $request->min_stock_level;
        }
        if ($request->has('location_bin')) {
            $stock->location_bin = $request->location_bin;
        }
        if ($request->has('warehouse_code')) {
            $stock->warehouse_code = $request->warehouse_code;
        }

        $stock->save();

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
            'project_id' => 'nullable|exists:projects,id',
            'notes' => 'nullable|string'
        ]);

        // Board materials must be returned through the Board Lifecycle endpoint so that
        // individual Board records transition back to Available and stock stays in sync.
        $material = LibraryMaterial::with('materialCategory.parent')->find($request->material_id);
        if ($material?->isBoardTrackable()) {
            return response()->json([
                'message'  => "'{$material->material_name}' is a tracked board material. "
                    . 'Return individual boards via POST /boards/{id}/transition with status=Available.',
                'status'   => 'error',
                'redirect' => 'board_lifecycle',
            ], 422);
        }

        $service = new InventoryService();
        $log = $service->adjustStock(
            $request->material_id, 
            $request->quantity, 
            'return', 
            $request->all()
        );

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
            'notes' => 'required|string|min:5'
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

        $service = new InventoryService();

        // Check if we have enough stock to mark as defective
        $stock = Stock::where('material_id', $request->material_id)->first();
        if (!$stock || $stock->quantity_on_hand < $request->quantity) {
            return response()->json([
                'message' => 'Insufficient stock on hand to mark as defective',
                'status' => 'error'
            ], 422);
        }

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
            'items.*.quantity' => 'required|numeric|min:0.01',
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
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.reference_no' => 'nullable|string',
            'items.*.notes' => 'nullable|string',
            'items.*.usage_type' => 'nullable|string|in:consumable,reusable',
            'items.*.requestor' => 'nullable|string',
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

            $stock = Stock::where('material_id', $item['material_id'])->first();
            if (!$stock || ($stock->quantity_on_hand - $stock->quantity_reserved) < $item['quantity']) {
                return response()->json([
                    'message' => 'Insufficient stock for material: ' . ($material?->material_name ?? $item['material_id']),
                    'status' => 'error'
                ], 422);
            }
        }

        // All items validated, process the batch
        $batchNumber = $service->generateBatchNumber();
        $logs = [];

        foreach ($request->items as $item) {
            $meta = array_merge(
                $item,
                [
                    'batch_number' => $batchNumber,
                    'project_id' => $request->project_id ?? null,
                    'notes' => $item['notes'] ?? 'Batch check-out',
                    'logged_at' => $request->logged_at ?? now()
                ]
            );
            
            $logs[] = $service->adjustStock(
                $item['material_id'],
                -$item['quantity'], // Negative for check-out
                'check_out',
                $meta
            );
        }

        return response()->json([
            'message' => 'Batch check-out processed successfully',
            'batch_number' => $batchNumber,
            'items_processed' => count($logs),
            'data' => $logs,
            'status' => 'success'
        ]);
    }

    /**
     * Fetch recent stock movement logs with filtering
     */
    public function inventoryLogs(Request $request): JsonResponse
    {
        $query = InventoryLog::with(['material', 'user', 'project.enquiry']);

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
     * Export movement logs to PDF with filtering
     */
    public function inventoryLogsPdf(Request $request)
    {
        $query = InventoryLog::with(['material', 'user', 'project.enquiry']);

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
     * Get outstanding reusable items grouped by job/project.
     *
     * Two sub-categories:
     *  - Consumable-class reusables: linked by project_id FK
     *  - Board materials: issued via board requests; reference_no holds the job_ref
     */
    public function outstandingReusables(): JsonResponse
    {
        $logs = InventoryLog::with(['material', 'project.enquiry'])
            ->where('usage_type', 'reusable')
            ->whereIn('type', ['check_out', 'return'])
            ->get();

        $materialSummary = function ($groupedLogs) {
            return $groupedLogs->groupBy('material_id')->map(function ($ml) {
                $material = $ml->first()->material;
                if (!$material) return null;
                $issued   = abs($ml->where('type', 'check_out')->sum('quantity'));
                $returned = (float) $ml->where('type', 'return')->sum('quantity');
                $balance  = $issued - $returned;
                return $balance > 0 ? [
                    'material_id'   => $material->id,
                    'material_name' => $material->material_name,
                    'material_code' => $material->material_code,
                    'unit'          => $material->unit_of_measure,
                    'issued'        => $issued,
                    'returned'      => $returned,
                    'balance'       => $balance,
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
                    'items'    => $items,
                ] : null;
            })->filter()->values();

        return response()->json([
            'data'   => $byProject->merge($byJob)->values(),
            'status' => 'success',
        ]);
    }
}
