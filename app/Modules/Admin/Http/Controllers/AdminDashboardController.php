<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\Project;
use App\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    /**
     * Get system-wide statistics and recent activity for the Super Admin dashboard.
     */
    public function index(): JsonResponse
    {
        $stats = [
            'totalUsers' => User::count(),
            'totalDepartments' => Department::count(),
            'activeProjects' => Project::whereNotIn('status', ['completed', 'cancelled'])->count(),
        ];

        $recentActivity = ActionLog::with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'message' => $this->formatLogMessage($log),
                    'user_name' => $log->user->name ?? 'System',
                    'time' => $log->created_at->diffForHumans(),
                    'action' => $log->action,
                ];
            });

        return response()->json([
            'data' => [
                'stats' => $stats,
                'recentActivity' => $recentActivity,
            ]
        ]);
    }

    /**
     * Format the log message for display.
     */
    private function formatLogMessage($log): string
    {
        $action = str_replace('_', ' ', $log->action);
        $model = class_basename($log->loggable_type);
        
        // Custom formatting for common actions
        if ($log->action === 'login') {
            return "logged into the system";
        }

        return "{$action} {$model}";
    }
}
