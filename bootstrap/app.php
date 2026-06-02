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
            'active' => \App\Http\Middleware\CheckUserActive::class, // Registering 'active' middleware for status checks
        ]);

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \App\Http\Middleware\CheckUserActive::class, // Prepend check to all API routes
        ]);

        // Exclude API routes from CSRF verification for mobile app
        $middleware->validateCsrfTokens(except: [
            'api/*',  // Exclude all API routes from CSRF
        ]);

    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->command('hr:process-actions')->daily();
        $schedule->command('attendance:sync-hikvision')->twiceDaily(8, 17);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle unauthenticated requests for API
        $exceptions->renderable(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated. Please login again.',
                    'error' => 'Token expired or invalid'
                ], 401);
            }
            return null;
        });

        // Handle generic exceptions for API to ensure JSON response
        $exceptions->renderable(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                // If it's a validation exception, let Laravel handle it normally (returns 422 with errors)
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    return null;
                }

                $status = 500;
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                    $status = $e->getStatusCode();
                }

                return response()->json([
                    'message' => $e->getMessage() ?: 'A system error occurred.',
                    'error' => class_basename($e),
                    'trace' => config('app.debug') ? $e->getTrace() : null
                ], $status);
            }
            return null;
        });
    })->create();
