<?php

namespace App\Modules\Design\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignJob;
use Illuminate\Http\JsonResponse;

class DesignDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'active_jobs' => DesignJob::whereNotIn('status', ['cancelled', 'handed_off'])->count(),
                'graphic_in_progress' => DesignItem::where('stream', 'graphic')->whereIn('status', ['pending', 'in_design', 'submitted'])->count(),
                'structural_in_progress' => DesignItem::where('stream', 'structural')->whereIn('status', ['pending', 'in_design', 'submitted'])->count(),
                'print_ready' => DesignItem::where('stream', 'graphic')->where('status', 'print_ready')->count(),
                'production_ready' => DesignItem::where('stream', 'structural')->where('status', 'production_ready')->count(),
                'handed_off' => DesignItem::where('status', 'handed_off')->count(),
            ],
        ]);
    }
}
