<?php

namespace App\Modules\Projects\Http\Controllers\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Shared graceful error handling for Projects module controllers.
 *
 * Wrap a controller body in {@see self::safe()} so any exception is translated
 * into a clean JSON response with the right HTTP status — instead of leaking a
 * raw 500 / stack trace — while keeping a single, consistent shape the frontend
 * can rely on ({ message, errors? }).
 */
trait HandlesProjectErrors
{
    /**
     * Execute controller logic and convert any thrown exception into a
     * structured JSON response.
     *
     * @param  callable():JsonResponse  $fn               The controller body.
     * @param  string                   $context          Short label used in logs, e.g. "Complete project".
     * @param  int                      $businessStatus   HTTP status for plain (business-rule) exceptions.
     *                                                     Defaults to 422 for write endpoints; pass 500 for
     *                                                     pure reads where a failure is a server fault.
     */
    protected function safe(callable $fn, string $context = 'Operation', int $businessStatus = 422): JsonResponse
    {
        try {
            return $fn();
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'The requested resource was not found.',
            ], 404);
        } catch (AuthorizationException $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'You are not authorized to perform this action.',
            ], 403);
        } catch (HttpExceptionInterface $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Request could not be completed.',
            ], $e->getStatusCode());
        } catch (QueryException $e) {
            // Database faults are server-side problems — never leak SQL to the
            // client, and keep them as 500s.
            Log::error("{$context} failed (database): {$e->getMessage()}", [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'A database error occurred. Please try again.',
            ], 500);
        } catch (\Exception $e) {
            // Application/business-rule failures are thrown as plain exceptions by
            // the services/actions and carry a user-facing message. Treat them as
            // client-correctable (422) and surface the reason so the UI can show
            // *why*, rather than a generic server error.
            Log::warning("{$context} rejected: {$e->getMessage()}", [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => $e->getMessage() ?: 'The request could not be completed.',
            ], $businessStatus);
        } catch (Throwable $e) {
            // Genuine runtime faults (\Error, \TypeError, …) — log fully, return a
            // safe generic 500 without leaking internals.
            Log::error("{$context} failed: {$e->getMessage()}", [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Something went wrong. Please try again.',
            ], 500);
        }
    }

    /**
     * Run a non-critical side effect (e.g. sending a notification) without
     * letting its failure break the main operation. Logs and swallows.
     */
    protected function quietly(callable $fn, string $context = 'Side effect'): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::warning("{$context} failed (non-fatal): {$e->getMessage()}", [
                'exception' => get_class($e),
            ]);
        }
    }
}
