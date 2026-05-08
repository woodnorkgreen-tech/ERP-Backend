<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Services\LeaveManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveDashboardController extends Controller
{
    public function __construct(private readonly LeaveManagementService $leaveService)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $dashboard = $this->leaveService->getDashboard(
            $request->user(),
            $request->integer('employee_id') ?: null,
            $request->integer('year') ?: null
        );

        return response()->json([
            'success' => true,
            'data' => $dashboard,
        ]);
    }

    public function projects(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->leaveService->getHandoverProjects(),
        ]);
    }
}
