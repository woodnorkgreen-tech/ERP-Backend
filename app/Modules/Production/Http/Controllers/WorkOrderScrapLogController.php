<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderScrapLog;
use App\Models\ElementMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkOrderScrapLogController extends Controller
{
    public function index(WorkOrder $workOrder): JsonResponse
    {
        $logs = WorkOrderScrapLog::with(['elementMaterial.element', 'createdBy'])
            ->where('work_order_id', $workOrder->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $logs
        ]);
    }

    public function store(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $validated = $request->validate([
            'element_material_id' => 'required|exists:element_materials,id',
            'stage' => 'required|string|max:50',
            'reason' => 'nullable|string|max:255',
            'quantity' => 'required|numeric|min:0',
            'unit' => 'nullable|string|max:50',
            'notes' => 'nullable|string'
        ]);

        $material = ElementMaterial::findOrFail($validated['element_material_id']);

        $log = WorkOrderScrapLog::create([
            'work_order_id' => $workOrder->id,
            'element_material_id' => $validated['element_material_id'],
            'stage' => $validated['stage'],
            'reason' => $validated['reason'] ?? null,
            'quantity' => $validated['quantity'],
            'unit' => $validated['unit'] ?? $material->unit_of_measurement,
            'notes' => $validated['notes'] ?? null,
            'created_by' => auth()->id()
        ]);

        $log->load(['elementMaterial.element', 'createdBy']);

        return response()->json([
            'success' => true,
            'message' => 'Scrap log entry created',
            'data' => $log
        ], 201);
    }

    public function destroy(WorkOrder $workOrder, WorkOrderScrapLog $scrapLog): JsonResponse
    {
        if ($scrapLog->work_order_id !== $workOrder->id) {
            return response()->json([
                'success' => false,
                'message' => 'Scrap log does not belong to this work order'
            ], 403);
        }

        $scrapLog->delete();

        return response()->json([
            'success' => true,
            'message' => 'Scrap log entry deleted'
        ]);
    }
}
