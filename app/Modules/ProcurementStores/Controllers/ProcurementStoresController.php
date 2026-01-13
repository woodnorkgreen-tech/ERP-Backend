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
            'status' => 'success'
        ]);
    }

    /**
     * Fetch recent stock movement logs
     */
    public function inventoryLogs(): JsonResponse
    {
        $logs = \App\Modules\ProcurementStores\Models\InventoryLog::with(['material', 'user'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $logs,
            'status' => 'success'
        ]);
    }
}
