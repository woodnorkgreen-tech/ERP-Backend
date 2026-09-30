<?php

namespace App\Modules\Printing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Printing\Services\PrintingDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrintingDashboardController extends Controller
{
    public function __construct(private readonly PrintingDashboardService $dashboard)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->summary($request)]);
    }

    public function projectUsage(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->projectUsage($request)]);
    }

    public function monthlyConsumption(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'period' => ['nullable', 'in:month,week,all'],
            'month' => ['nullable', 'date_format:Y-m'],
            'week' => ['nullable', 'date_format:Y-m-d'],
            'material' => ['nullable', 'string', 'max:255'],
            'project' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'in:all,historical,live'],
        ]);

        return response()->json(['data' => $this->dashboard->monthlyConsumption($filters)]);
    }
}
