<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'department.access' => \App\Http\Middleware\CheckDepartmentAccess::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'project.access' => \App\Http\Middleware\CheckProjectAccess::class,
        ]);

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // Exclude API routes from CSRF verification for mobile app
        $middleware->validateCsrfTokens(except: [
            'api/*',  // Exclude all API routes from CSRF
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle unauthenticated requests for API
        $exceptions->renderable(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            // If it's an API request (Accept: application/json or /api/* route), return JSON  
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated. Please login again.',
                    'error' => 'Token expired or invalid'
                ], 401);
            }
            
            // For web requests, use default behavior (redirect to login)
            return null;
        });
    })->create();
