<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;

class SystemController extends Controller
{
    /**
     * Get the current system version/timestamp
     */
    public function getVersion(): JsonResponse
    {
        // Default to current time if not set (ensures initial state is valid)
        $version = Cache::rememberForever('system_version', function () {
            return now()->timestamp;
        });

        return response()->json([
            'version' => $version
        ]);
    }

    /**
     * Trigger a system refresh by updating the version
     */
    public function triggerRefresh(Request $request): JsonResponse
    {
        // Only allow admins to trigger refresh
        if (!$request->user()->hasRole(['Super Admin', 'Admin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $newVersion = now()->timestamp;
        Cache::forever('system_version', $newVersion);

        \Log::info('System refresh triggered by user ' . $request->user()->id . ' at ' . now());

        return response()->json([
            'message' => 'System refresh triggered successfully',
            'version' => $newVersion
        ]);
    }
}
