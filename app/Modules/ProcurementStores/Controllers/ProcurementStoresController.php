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
        $query = LibraryMaterial::with(['workstation', 'stock']);

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

        $paginator->getCollection()->transform(fn($material) => [
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
            'quantity_on_hand'  => (float) ($material->stock?->quantity_on_hand ?? 0),
            'quantity_reserved' => (float) ($material->stock?->quantity_reserved ?? 0),
            'available'         => (float) ($material->stock ? ($material->stock->quantity_on_hand - $material->stock->quantity_reserved) : 0),
            'min_stock_level'   => (float) ($material->stock?->min_stock_level ?? 0),
            'location'          => $material->stock?->location_bin ?? 'Not Set',
        ]);

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

        $service = new InventoryService();
        $log = $service->adjustStock(
            $request->material_id,
            $request->quantity,
            'check_in',
            $request->all()
        );

        // ── Board material hook ───────────────────────────────────────────────
        // If the checked-in material is reusable and board-eligible, create
        // individual board records so each sheet is physically trackable.
        $boards = [];
        $material = LibraryMaterial::with(
            ['materialCategory.parent', 'workstation']
        )->find($request->material_id);

        if ($material && $material->material_type === 'reusable') {
            $registration = new BoardRegistrationService();

            try {
                $registration->validateMaterial($material);

                // createBoardRecords — NOT registerBatch.
                // adjustStock() already handled stock + inventory_log above.
                // registerBatch() would double both.
                $boards = $registration->createBoardRecords(
                    material:    $material,
                    quantity:    (int) $request->quantity,
                    batchNumber: $log->batch_number,
                    length:      $request->length    ?? null,
                    width:       $request->width     ?? null,
                    thickness:   $request->thickness ?? null,
                    userId:      auth()->id(),
                );

                // Mark the inventory log entry as reusable so reports can filter correctly
                $log->update(['usage_type' => 'reusable']);

            } catch (\InvalidArgumentException $e) {
                // Material is reusable but not board-eligible (e.g. Timber) — skip silently
            }
        }

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
                'material'      => ['name' => $material?->material_name, 'code' => $material?->material_code],
            ], $boards),
        ]);
    }

    /**
     * Process a stock check-out (Deduct from inventory)
     *
     * Board materials (material_type = reusable) must NOT be checked out through
     * this generic endpoint — they require an explicit board request so individual
     * boards are tracked. Redirect the caller to use the board request flow.
     */
    public function checkOut(Request $request): JsonResponse
    {
        $request->validate([
            'material_id' => 'required|exists:library_materials,id',
            'quantity' => 'required|numeric|min:0.01',
            'project_id' => 'nullable|exists:projects,id',
            'notes' => 'nullable|string',
            'logged_at' => 'nullable|date'
        ]);

        $material = LibraryMaterial::find($request->material_id);
        if ($material && $material->material_type === 'reusable') {
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
        $request->validate([
            'material_id' => 'required|exists:library_materials,id',
            'quantity' => 'required|numeric|min:0.01',
            'project_id' => 'nullable|exists:projects,id',
            'notes' => 'nullable|string'
        ]);

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
        $request->validate([
            'material_id' => 'required|exists:library_materials,id',
            'quantity' => 'required|numeric|min:0.01',
            'notes' => 'required|string|min:5'
        ]);

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
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.material_id' => 'required|exists:library_materials,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.reference_no' => 'nullable|string',
            'items.*.notes' => 'nullable|string',
            'warehouse_code' => 'sometimes|string',
            'logged_at' => 'nullable|date'
        ]);

        $service = new InventoryService();
        $batchNumber = $service->generateBatchNumber();
        $logs = [];
        $allBoards = [];

        foreach ($request->items as $item) {
            $meta = array_merge(
                $item,
                [
                    'batch_number'  => $batchNumber,
                    'warehouse_code'=> $request->warehouse_code ?? 'MAIN',
                    'logged_at'     => $request->logged_at ?? now(),
                ]
            );

            $log = $service->adjustStock(
                $item['material_id'],
                $item['quantity'],
                'check_in',
                $meta
            );
            $logs[] = $log;

            // Board hook — same logic as single checkIn()
            $material = LibraryMaterial::with(
                ['materialCategory.parent', 'workstation']
            )->find($item['material_id']);

            if ($material && $material->material_type === 'reusable') {
                $registration = new BoardRegistrationService();
                try {
                    $registration->validateMaterial($material);
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
                } catch (\InvalidArgumentException) {
                    // Reusable but not board-eligible — skip
                }
            }
        }

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
        
        // Validate stock availability for all items first
        foreach ($request->items as $item) {
            $stock = Stock::where('material_id', $item['material_id'])->first();
            if (!$stock || ($stock->quantity_on_hand - $stock->quantity_reserved) < $item['quantity']) {
                $material = LibraryMaterial::find($item['material_id']);
                return response()->json([
                    'message' => "Insufficient stock for material: {$material->material_name}",
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
        return DB::transaction(function () use ($id) {
            $log = InventoryLog::findOrFail($id);

            // Guard: refuse if boards were created from this batch
            if ($log->usage_type === 'reusable' && $log->type === 'check_in' && $log->batch_number) {
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

            $stock = Stock::where('material_id', $log->material_id)->first();
            if ($stock) {
                $stock->quantity_on_hand -= $log->quantity;
                $stock->save();
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
