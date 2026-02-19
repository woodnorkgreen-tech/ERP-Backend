<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderRework;
use App\Modules\Production\Models\WorkOrderReworkEvidence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WorkOrderReworkEvidenceController extends Controller
{
    public function index(WorkOrder $workOrder, WorkOrderRework $rework): JsonResponse
    {
        if ($rework->work_order_id !== $workOrder->id) {
            return response()->json([
                'success' => false,
                'message' => 'Rework does not belong to this work order'
            ], 403);
        }

        $items = WorkOrderReworkEvidence::where('work_order_rework_id', $rework->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'original_name' => $item->original_name,
                    'mime_type' => $item->mime_type,
                    'file_size' => $item->file_size,
                    'file_url' => Storage::disk('public')->url($item->file_path),
                    'uploaded_at' => $item->created_at?->toISOString()
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $items
        ]);
    }

    public function store(Request $request, WorkOrder $workOrder, WorkOrderRework $rework): JsonResponse
    {
        if ($rework->work_order_id !== $workOrder->id) {
            return response()->json([
                'success' => false,
                'message' => 'Rework does not belong to this work order'
            ], 403);
        }

        $validated = $request->validate([
            'file' => 'required|file|max:5120'
        ]);

        $file = $validated['file'];
        $path = $file->store("production/work-orders/{$workOrder->id}/reworks/{$rework->id}", 'public');

        $evidence = WorkOrderReworkEvidence::create([
            'work_order_rework_id' => $rework->id,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize() ?? 0,
            'uploaded_by' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Rework evidence uploaded',
            'data' => [
                'id' => $evidence->id,
                'original_name' => $evidence->original_name,
                'mime_type' => $evidence->mime_type,
                'file_size' => $evidence->file_size,
                'file_url' => Storage::disk('public')->url($evidence->file_path),
                'uploaded_at' => $evidence->created_at?->toISOString()
            ]
        ], 201);
    }

    public function destroy(WorkOrder $workOrder, WorkOrderRework $rework, WorkOrderReworkEvidence $evidence): JsonResponse
    {
        if ($rework->work_order_id !== $workOrder->id || $evidence->work_order_rework_id !== $rework->id) {
            return response()->json([
                'success' => false,
                'message' => 'Evidence does not belong to this rework'
            ], 403);
        }

        if (Storage::disk('public')->exists($evidence->file_path)) {
            Storage::disk('public')->delete($evidence->file_path);
        }

        $evidence->delete();

        return response()->json([
            'success' => true,
            'message' => 'Rework evidence deleted'
        ]);
    }
}
