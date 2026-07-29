<?php

namespace App\Modules\ClientService\Http\Controllers;

use App\Modules\ClientService\Services\ClientServiceDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    protected $dashboardService;

    public function __construct(ClientServiceDashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    /**
     * Get dashboard data for Client Service module
     */
    public function index(): JsonResponse
    {
        try {
            $stats = $this->dashboardService->getDashboardStats();
            $activity = $this->dashboardService->getActivityFeed();

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => $stats,
                    'activity' => $activity
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Client Service Dashboard Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dashboard data',
            ], 500);
        }
    }
}
