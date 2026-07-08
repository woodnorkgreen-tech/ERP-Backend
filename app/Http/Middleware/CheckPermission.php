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
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Super Admin bypasses all permission checks
        if ($user->hasRole('Super Admin')) {
            return $next($request);
        }

        // OR semantics: passing several permissions (e.g. permission:user.read,task.assign)
        // grants access if the user holds ANY of them. Previously only the first argument
        // was honoured, so every multi-permission route silently ignored the rest.
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return $next($request);
            }
        }

        // Log the permission denial for audit
        \Log::warning('Permission denied', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'permission' => implode(' | ', $permissions),
            'route' => $request->route() ? $request->route()->getName() : $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'error' => 'Insufficient permissions',
            'required_permission' => implode(' or ', $permissions),
            'message' => 'You do not have permission to perform this action.'
        ], 403);
    }
}
