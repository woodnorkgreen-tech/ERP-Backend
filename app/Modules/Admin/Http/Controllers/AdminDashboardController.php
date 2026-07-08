<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\Project;
use App\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class AdminDashboardController extends Controller
{
    /**
     * Get system-wide statistics and recent activity for the Super Admin dashboard.
     */
    public function index(): JsonResponse
    {
        $stats = [
            'totalUsers'       => User::count(),
            'activeUsers'      => User::where('is_active', true)->count(),
            'inactiveUsers'    => User::where('is_active', false)->count(),
            'totalDepartments' => Department::count(),
            'activeProjects'   => $this->activeProjectCount(),
            'recentLogins'     => User::whereNotNull('last_login_at')
                ->where('last_login_at', '>=', now()->subDays(7))
                ->count(),
        ];

        $roleDistribution = Role::withCount('users')
            ->orderByDesc('users_count')
            ->get()
            ->map(fn ($role) => [
                'role'  => $role->name,
                'users' => $role->users_count,
            ]);

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
                'roleDistribution' => $roleDistribution,
                'recentActivity' => $recentActivity,
            ]
        ]);
    }

    /**
     * Paginated, filterable audit trail of system actions.
     * Filters: action, user_id, type (user|role), from, to.
     */
    public function auditTrail(Request $request): JsonResponse
    {
        $query = ActionLog::with('user:id,name,email')->orderByDesc('created_at');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        if ($request->filled('type')) {
            $typeMap = ['user' => User::class, 'role' => Role::class];
            if (isset($typeMap[$request->type])) {
                $query->where('loggable_type', $typeMap[$request->type]);
            }
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to'));
        }

        $logs = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'total'        => $logs->total(),
                'per_page'     => $logs->perPage(),
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
            ],
        ]);
    }

    /**
     * Active project count, isolated so a change in the Projects module cannot
     * break the admin dashboard (the only cross-module coupling here).
     */
    private function activeProjectCount(): int
    {
        try {
            return Project::whereNotIn('status', ['completed', 'cancelled'])->count();
        } catch (\Throwable $e) {
            \Log::warning('Admin dashboard: active project count unavailable: ' . $e->getMessage());
            return 0;
        }
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
