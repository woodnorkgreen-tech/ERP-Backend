<?php

namespace App\Modules\Design\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Design\Models\DesignHandoff;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Resources\DesignHandoffResource;
use App\Modules\Design\Services\DesignHandoffService;
use App\Modules\Design\Services\DesignNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesignHandoffController extends Controller
{
    public function __construct(
        private readonly DesignHandoffService $handoffs,
        private readonly DesignNotificationService $notifications
    ) {
    }

    public function store(Request $request, DesignItem $item): JsonResponse
    {
        $data = $request->validate([
            'target_module' => 'required|in:printing,production,materials',
            'target_record_id' => 'nullable|integer',
        ]);

        if ($item->handoffs()->where('target_module', $data['target_module'])->exists()) {
            return response()->json([
                'message' => 'This design item has already been handed off to that module.',
            ], 422);
        }

        $handoff = DesignHandoff::create([
            'design_item_id' => $item->id,
            'target_module' => $data['target_module'],
            'target_record_id' => $data['target_record_id'] ?? null,
            'status' => 'pending',
            'payload_snapshot' => $item->load(['job', 'type', 'documents', 'bomItems'])->toArray(),
            'handed_off_by' => auth()->id(),
            'handed_off_at' => now(),
        ]);

        $item->update(['status' => 'handed_off', 'updated_by' => auth()->id()]);

        return response()->json([
            'message' => 'Design item handed off successfully',
            'data' => new DesignHandoffResource($handoff),
        ], 201);
    }

    public function accept(Request $request, DesignHandoff $handoff): JsonResponse
    {
        $data = $request->validate([
            'target_record_id' => 'nullable|integer',
        ]);

        return response()->json([
            'message' => 'Design handoff accepted successfully',
            'data' => new DesignHandoffResource(
                $this->handoffs->accept($handoff, $data['target_record_id'] ?? null)
            ),
        ]);
    }

    public function reject(Request $request, DesignHandoff $handoff): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $handoff = $this->handoffs->reject($handoff, $data['reason']);
        $this->notifications->notifyHandoffRejected($handoff);

        return response()->json([
            'message' => 'Design handoff rejected successfully',
            'data' => new DesignHandoffResource($handoff),
        ]);
    }
}
