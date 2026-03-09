<?php

namespace App\Modules\ProcurementStores\Controllers;
use App\Http\Controllers\Controller;

use Illuminate\Http\JsonResponse;

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
    public function inventory(\Illuminate\Http\Request $request): JsonResponse
    {
        $query = \App\Modules\MaterialsLibrary\Models\LibraryMaterial::with(['workstation', 'stock']);

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

        $materials = $query->get()->map(function ($material) {
                return [
                    'id' => $material->id,
                    'workstation_id' => $material->workstation_id,
                    'material_name' => $material->material_name,
                    'material_code' => $material->material_code,
                    'category' => $material->category,
                    'subcategory' => $material->subcategory,
                    'specification' => $material->specification ?? 'N/A',
                    'unit_of_measure' => $material->unit_of_measure,
                    'unit_cost' => $material->unit_cost,
                    'workstation' => $material->workstation, // Pass the whole relation
                    'workstation_name' => $material->workstation ? $material->workstation->name : 'N/A',
                    'attributes' => $material->attributes ?? [],
                    'is_active' => $material->is_active,
                    'notes' => $material->notes,
                    // Default to 0 if no stock record exists yet
                    'quantity_on_hand' => (float)($material->stock ? $material->stock->quantity_on_hand : 0),
                    'quantity_reserved' => (float)($material->stock ? $material->stock->quantity_reserved : 0),
                    'available' => (float)($material->stock ? ($material->stock->quantity_on_hand - $material->stock->quantity_reserved) : 0),
                    'min_stock_level' => (float)($material->stock ? $material->stock->min_stock_level : 0),
                    'location' => $material->stock ? $material->stock->location_bin : 'Not Set',
                ];
            });

        return response()->json([
            'data' => $materials,
            'status' => 'success'
        ]);
    }

    /**
     * Process a stock check-in (Add to inventory)
     */
    public function checkIn(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'material_id' => 'required|exists:library_materials,id',
            'quantity' => 'required|numeric|min:0.01',
            'warehouse_code' => 'sometimes|string',
            'location' => 'nullable|string',
            'reference_no' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);

        $service = new \App\Modules\ProcurementStores\Services\InventoryService();
        $log = $service->adjustStock(
            $request->material_id, 
            $request->quantity, 
            'check_in', 
            $request->all()
        );

        return response()->json([
            'message' => 'Stock updated successfully',
            'data' => $log,
            'batch_number' => $log->batch_number,
            'status' => 'success'
        ]);
    }

    /**
     * Process a stock check-out (Deduct from inventory)
     */
    public function checkOut(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'material_id' => 'required|exists:library_materials,id',
            'quantity' => 'required|numeric|min:0.01',
            'project_id' => 'nullable|exists:projects,id',
            'notes' => 'nullable|string'
        ]);

        $service = new \App\Modules\ProcurementStores\Services\InventoryService();
        
        // Check if we have enough stock
        $stock = \App\Modules\ProcurementStores\Models\Stock::where('material_id', $request->material_id)->first();
        if (!$stock || ($stock->quantity_on_hand - $stock->quantity_reserved) < $request->quantity) {
            return response()->json([
                'message' => 'Insufficient stock for this operation',
                'status' => 'error'
            ], 422);
        }

        $log = $service->adjustStock(
            $request->material_id, 
            -$request->quantity, // Negative for checkout
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
     * Update fixed stock settings (Min Level, Location)
     */
    public function updateStockSettings(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'material_id' => 'required|exists:library_materials,id',
            'min_stock_level' => 'nullable|numeric|min:0',
            'location_bin' => 'nullable|string|max:50',
            'warehouse_code' => 'nullable|string|max:20'
        ]);

        $stock = \App\Modules\ProcurementStores\Models\Stock::firstOrCreate(
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
    public function returns(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'material_id' => 'required|exists:library_materials,id',
            'quantity' => 'required|numeric|min:0.01',
            'project_id' => 'nullable|exists:projects,id',
            'notes' => 'nullable|string'
        ]);

        $service = new \App\Modules\ProcurementStores\Services\InventoryService();
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
    public function markDefective(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'material_id' => 'required|exists:library_materials,id',
            'quantity' => 'required|numeric|min:0.01',
            'notes' => 'required|string|min:5'
        ]);

        $service = new \App\Modules\ProcurementStores\Services\InventoryService();
        
        // Check if we have enough stock to mark as defective
        $stock = \App\Modules\ProcurementStores\Models\Stock::where('material_id', $request->material_id)->first();
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
    public function batchCheckIn(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.material_id' => 'required|exists:library_materials,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.reference_no' => 'nullable|string',
            'items.*.notes' => 'nullable|string',
            'warehouse_code' => 'sometimes|string',
        ]);

        $service = new \App\Modules\ProcurementStores\Services\InventoryService();
        $batchNumber = $service->generateBatchNumber();
        $logs = [];

        foreach ($request->items as $item) {
            $meta = array_merge(
                $item,
                [
                    'batch_number' => $batchNumber,
                    'warehouse_code' => $request->warehouse_code ?? 'MAIN'
                ]
            );
            
            $logs[] = $service->adjustStock(
                $item['material_id'],
                $item['quantity'],
                'check_in',
                $meta
            );
        }

        return response()->json([
            'message' => 'Batch check-in processed successfully',
            'batch_number' => $batchNumber,
            'items_processed' => count($logs),
            'data' => $logs,
            'status' => 'success'
        ]);
    }

    /**
     * Process batch check-out (multiple materials with same batch number)
     */
    public function batchCheckOut(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.material_id' => 'required|exists:library_materials,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.reference_no' => 'nullable|string',
            'items.*.notes' => 'nullable|string',
            'items.*.requestor' => 'nullable|string',
            'project_id' => 'nullable|exists:projects,id',
        ]);

        $service = new \App\Modules\ProcurementStores\Services\InventoryService();
        
        // Validate stock availability for all items first
        foreach ($request->items as $item) {
            $stock = \App\Modules\ProcurementStores\Models\Stock::where('material_id', $item['material_id'])->first();
            if (!$stock || ($stock->quantity_on_hand - $stock->quantity_reserved) < $item['quantity']) {
                $material = \App\Modules\MaterialsLibrary\Models\LibraryMaterial::find($item['material_id']);
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
                    'notes' => $item['notes'] ?? 'Batch check-out'
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
    public function inventoryLogs(\Illuminate\Http\Request $request): JsonResponse
    {
        $query = \App\Modules\ProcurementStores\Models\InventoryLog::with(['material', 'user', 'project.enquiry']);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('material_id')) {
            $query->where('material_id', $request->material_id);
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        $logs = $query->orderBy('created_at', 'desc')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $logs,
            'status' => 'success'
        ]);
    }

    /**
     * Delete an inventory log and revert the stock adjustment.
     */
    public function destroyLog($id): JsonResponse
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($id) {
            $log = \App\Modules\ProcurementStores\Models\InventoryLog::findOrFail($id);
            $stock = \App\Modules\ProcurementStores\Models\Stock::where('material_id', $log->material_id)->first();

            if ($stock) {
                // Revert the adjustment: 
                // If it was a check-in (positive quantity), we subtract it.
                // If it was a check-out (negative quantity), we add it back.
                $stock->quantity_on_hand -= $log->quantity;
                $stock->save();
            }

            $log->delete();

            return response()->json([
                'message' => 'Inventory log deleted and stock reverted successfully',
                'status' => 'success'
            ]);
        });
    }
}
