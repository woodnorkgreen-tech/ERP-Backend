<?php

namespace App\Modules\ClientService\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientService\Services\HandoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HandoverController extends Controller
{
    protected $handoverService;

    public function __construct(HandoverService $handoverService)
    {
        $this->handoverService = $handoverService;
    }

    /**
     * Display a listing of submitted handovers.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['client_id', 'search', 'feedback_source', 'rating_class', 'timeliness', 'sort_by', 'page']);
            $handovers = $this->handoverService->getHandovers($filters);

            return response()->json($handovers);
        } catch (\Exception $e) {
            \Log::error('Error fetching handovers: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to retrieve handovers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get aggregate statistics for submitted handovers.
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $stats = $this->handoverService->getHandoverStats();
            return response()->json($stats);
        } catch (\Exception $e) {
            \Log::error('Error fetching handover stats: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to retrieve handover statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified handover detail.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $handover = $this->handoverService->getHandoverDetails($id);

            if (!$handover) {
                return response()->json([
                    'message' => 'Handover survey not found'
                ], 404);
            }

            return response()->json($handover);
        } catch (\Exception $e) {
            \Log::error('Error fetching handover detail: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to retrieve handover detail',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
