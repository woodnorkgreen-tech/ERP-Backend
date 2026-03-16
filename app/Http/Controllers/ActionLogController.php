<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ActionLogController extends Controller
{
    /**
     * Get logs for a specific model instance.
     * Currently supports fetching by loggable_type and loggable_id.
     */
    public function index(Request $request, string $type, int $id): JsonResponse
    {
        // Map friendly type names to full class names
        $typeMap = [
            'quote' => \App\Models\TaskQuoteData::class,
            'budget' => \App\Models\TaskBudgetData::class,
            'materials' => \App\Models\TaskMaterialsData::class,
            'procurement' => \App\Models\TaskProcurementData::class,
            'production' => \App\Modules\Production\Models\WorkOrder::class,
            'project' => \App\Models\Project::class,
            'enquiry_task' => \App\Modules\Projects\Models\EnquiryTask::class,
        ];

        if (!array_key_exists($type, $typeMap)) {
            return response()->json(['message' => 'Invalid log type'], 400);
        }

        $modelClass = $typeMap[$type];

        $logs = ActionLog::where('loggable_type', $modelClass)
            ->where('loggable_id', $id)
            ->with('user:id,name,email') // Eager load user
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $logs
        ]);
    }
}
