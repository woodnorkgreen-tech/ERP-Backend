<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Super Admin bypasses all permission checks
        if ($user->hasRole('Super Admin')) {
            return $next($request);
        }

        // Check if user has the required permission
        if (!$user->can($permission)) {
            // Log the permission denial for audit
            \Log::warning('Permission denied', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'permission' => $permission,
                'route' => $request->route() ? $request->route()->getName() : $request->path(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'error' => 'Insufficient permissions',
                'required_permission' => $permission,
                'message' => 'You do not have permission to perform this action.'
            ], 403);
        }

        return $next($request);
    }
}
