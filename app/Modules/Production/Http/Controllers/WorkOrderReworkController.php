<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderRework;
use App\Modules\Production\Models\WorkOrderMidQcCheck;
use App\Modules\Production\Models\WorkOrderFinalQcCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkOrderReworkController extends Controller
{
    public function index(WorkOrder $workOrder): JsonResponse
    {
        $this->syncReworksFromQc($workOrder);

        $reworks = WorkOrderRework::where('work_order_id', $workOrder->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $reworks
        ]);
    }

    public function store(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:190',
            'reason' => 'required|string',
            'status' => 'nullable|in:open,in_progress,closed',
            'is_change_request' => 'nullable|boolean'
        ]);

        $rework = WorkOrderRework::create([
            'work_order_id' => $workOrder->id,
            'source_type' => 'manual',
            'source_ref' => null,
            'qc_stage' => null,
            'title' => $validated['title'],
            'reason' => $validated['reason'],
            'status' => $validated['status'] ?? 'open',
            'qc_status' => 'pending',
            'is_change_request' => $validated['is_change_request'] ?? false,
            'created_by' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Rework created',
            'data' => $rework
        ], 201);
    }

    public function update(Request $request, WorkOrder $workOrder, WorkOrderRework $rework): JsonResponse
    {
        if ($rework->work_order_id !== $workOrder->id) {
            return response()->json([
                'success' => false,
                'message' => 'Rework does not belong to this work order'
            ], 403);
        }

        $validated = $request->validate([
            'status' => 'sometimes|in:open,in_progress,closed',
            'assigned_workstation' => 'sometimes|nullable|string|max:120',
            'assigned_to' => 'sometimes|nullable|string|max:190',
            'target_date' => 'sometimes|nullable|date',
            'qc_status' => 'sometimes|in:pending,passed,failed',
            'qc_reason' => 'sometimes|nullable|string|max:255'
        ]);

        if (($validated['qc_status'] ?? null) === 'failed' && empty($validated['qc_reason'])) {
            return response()->json([
                'success' => false,
                'message' => 'Reason is required when a Rework QC fails.'
            ], 422);
        }

        $rework->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Rework updated',
            'data' => $rework
        ]);
    }

    private function syncReworksFromQc(WorkOrder $workOrder): void
    {
        $midFails = WorkOrderMidQcCheck::where('work_order_id', $workOrder->id)
            ->where('status', 'failed')
            ->get();

        foreach ($midFails as $check) {
            $existing = WorkOrderRework::where([
                'work_order_id' => $workOrder->id,
                'source_type' => 'mid_qc',
                'source_ref' => $check->workstation,
                'title' => $check->category . ': ' . $check->title
            ])->first();

            if (!$existing) {
                WorkOrderRework::create([
                    'work_order_id' => $workOrder->id,
                    'source_type' => 'mid_qc',
                    'source_ref' => $check->workstation,
                    'title' => $check->category . ': ' . $check->title,
                    'reason' => $check->notes,
                    'qc_stage' => $check->qc_stage,
                    'status' => 'open',
                    'qc_status' => 'pending',
                    'is_change_request' => false,
                    'created_by' => $check->checked_by
                ]);
            } elseif (!$existing->reason && $check->notes) {
                $existing->update(['reason' => $check->notes]);
            }
        }

        $finalFails = WorkOrderFinalQcCheck::where('work_order_id', $workOrder->id)
            ->where('status', 'failed')
            ->get();

        foreach ($finalFails as $check) {
            $existing = WorkOrderRework::where([
                'work_order_id' => $workOrder->id,
                'source_type' => 'final_qc',
                'source_ref' => null,
                'title' => $check->category . ': ' . $check->title
            ])->first();

            if (!$existing) {
                WorkOrderRework::create([
                    'work_order_id' => $workOrder->id,
                    'source_type' => 'final_qc',
                    'source_ref' => null,
                    'title' => $check->category . ': ' . $check->title,
                    'reason' => $check->failure_reason,
                    'qc_stage' => 'final_qc',
                    'status' => 'open',
                    'qc_status' => 'pending',
                    'is_change_request' => false,
                    'created_by' => $check->checked_by
                ]);
            } elseif (!$existing->reason && $check->failure_reason) {
                $existing->update(['reason' => $check->failure_reason]);
            }
        }
    }
}
