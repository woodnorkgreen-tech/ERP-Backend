<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserActive
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if the authenticated user has been deactivated
        if ($request->user() && !$request->user()->is_active) {
            
            // 1. Revoke current Access Token (for API/Sanctum requests)
            if ($request->user()->currentAccessToken()) {
                $request->user()->currentAccessToken()->delete();
            }

            // 2. Invalidate Web Session (for traditional web auth if present)
            if ($request->hasSession() && auth()->guard('web')->check()) {
                auth()->guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            // Return a clear 403 Forbidden response
            return response()->json([
                'message' => 'Your account has been deactivated. Please contact the administrator.'
            ], 403);
        }

        return $next($request);
    }
}
