<?php

namespace App\Modules\Design\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Design\Models\DesignHandoff;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Resources\DesignHandoffResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesignHandoffController extends Controller
{
    public function store(Request $request, DesignItem $item): JsonResponse
    {
        $data = $request->validate([
            'target_module' => 'required|in:printing,production,materials',
            'target_record_id' => 'nullable|integer',
        ]);

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
}
