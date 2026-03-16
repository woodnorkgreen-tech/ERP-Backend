<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Modules\Projects\Models\EnquiryTask;

class EnsureFinancialClearance
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Intercept task status update requests.
        $taskRouteParam = $request->route('task');
        
        if ($taskRouteParam && $request->isMethod('put') && $request->has('status')) {
            $status = $request->input('status');
            
            // Only care if they are trying to start or complete the task
            if (in_array($status, ['in_progress', 'completed'])) {
                
                $task = $taskRouteParam instanceof EnquiryTask 
                    ? $taskRouteParam 
                    : EnquiryTask::find($taskRouteParam);
                
                if ($task) {
                    $governance = app(\App\Services\Governance\ProjectGovernanceService::class);
                    $result = $governance->evaluateTask($task);

                    if (!$result->isAuthorized()) {
                        return response()->json([
                            'message' => $result->getMessage()
                        ], 403);
                    }
                }
            }
        }

        return $next($request);
    }
}
