<?php

namespace App\Modules\Assets\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetServiceLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AssetServiceLogController extends Controller
{
    /**
     * All service logs for one asset, newest first.
     */
    public function index(int $assetId): JsonResponse
    {
        $logs = AssetServiceLog::with('loggedBy:id,name')
            ->where('asset_id', $assetId)
            ->orderByDesc('service_date')
            ->get()
            ->map(fn ($l) => [
                'id'                => $l->id,
                'service_date'      => $l->service_date?->format('Y-m-d'),
                'service_type'      => $l->service_type,
                'notes'             => $l->notes,
                'serviced_by'       => $l->serviced_by,
                'next_service_date' => $l->next_service_date?->format('Y-m-d'),
                'logged_by_name'    => $l->loggedBy?->name,
                'created_at'        => $l->created_at?->format('Y-m-d'),
            ]);

        return response()->json(['data' => $logs]);
    }

    /**
     * Log a new service entry. If next_service_date is provided,
     * update the asset's own next_service_date field too.
     */
    public function store(Request $request, int $assetId): JsonResponse
    {
        $asset = Asset::findOrFail($assetId);

        $validated = $request->validate([
            'service_date'      => 'required|date',
            'service_type'      => 'required|in:Service,Maintenance,Repair,Inspection',
            'notes'             => 'nullable|string',
            'serviced_by'       => 'nullable|string|max:150',
            'next_service_date' => 'nullable|date|after:service_date',
        ]);

        $log = AssetServiceLog::create([
            ...$validated,
            'asset_id'  => $assetId,
            'logged_by' => auth()->id(),
        ]);

        // Keep the asset's own next_service_date in sync
        if (!empty($validated['next_service_date'])) {
            $asset->update(['next_service_date' => $validated['next_service_date']]);
        }

        return response()->json([
            'data'    => $log,
            'message' => 'Service record saved.',
        ], 201);
    }

    /**
     * Delete a service log entry.
     * Does NOT automatically revert the asset's next_service_date —
     * an admin can update that manually via the edit modal if needed.
     */
    public function destroy(int $assetId, int $logId): JsonResponse
    {
        $log = AssetServiceLog::where('asset_id', $assetId)->findOrFail($logId);
        $log->delete();

        return response()->json(['message' => 'Service record deleted.']);
    }
}
