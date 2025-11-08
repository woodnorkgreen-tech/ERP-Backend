<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckDepartmentAccess
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Super Admin has access to all departments
        if ($user->hasRole('Super Admin')) {
            return $next($request);
        }

        // Admin role has access to department management
        if ($user->hasRole('Admin')) {
            return $next($request);
        }

        // HR role has access to department management for HR purposes
        if ($user->hasRole('HR')) {
            return $next($request);
        }

        // Check if this is a projects route
        if ($this->isProjectsRoute($request)) {
            return $this->handleProjectsAccess($user, $request, $next);
        }

        // For Managers and Employees, check department access
        $requestedDepartmentId = $this->getRequestedDepartmentId($request);

        if ($requestedDepartmentId && $user->department_id !== $requestedDepartmentId) {
            return response()->json([
                'error' => 'Access denied. You can only access your own department.',
                'user_department' => $user->department_id,
                'requested_department' => $requestedDepartmentId
            ], 403);
        }

        return $next($request);
    }

    /**
     * Get the department ID from the request.
     */
    private function getRequestedDepartmentId(Request $request): ?int
    {
        // Check route parameters
        if ($request->route('department')) {
            return (int) $request->route('department');
        }

        // Check query parameters
        if ($request->has('department_id')) {
            return (int) $request->input('department_id');
        }

        // Check request body for department_id
        if ($request->has('department_id')) {
            return (int) $request->input('department_id');
        }

        // For employee-related routes, check if accessing specific employee
        if ($request->route('employee')) {
            $employee = \App\Modules\HR\Models\Employee::find($request->route('employee'));
            return $employee ? $employee->department_id : null;
        }

        return null;
    }

    /**
     * Check if the current request is for projects routes
     */
    private function isProjectsRoute(Request $request): bool
    {
        $path = $request->path();
        return str_starts_with($path, 'api/projects') ||
               str_starts_with($path, 'projects') ||
               $request->routeIs('projects.*');
    }

    /**
     * Handle access control for projects routes
     */
    private function handleProjectsAccess($user, Request $request, $next)
    {
        // Super Admin has access to all projects
        if ($user->hasRole('Super Admin')) {
            return $next($request);
        }

        // Check if user belongs to projects department
        if ($user->department && strtolower($user->department->name) === 'projects') {
            return $next($request);
        }

        // Check if user has projects-related roles
        if ($user->hasRole(['Project Officer', 'Project Lead', 'Project Manager'])) {
            return $next($request);
        }

        return response()->json([
            'error' => 'Access denied. Projects module is only accessible to Super Admin and Projects department users.',
            'user_department' => $user->department ? $user->department->name : null,
            'user_roles' => $user->roles->pluck('name')->toArray()
        ], 403);
    }
}