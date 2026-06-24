<?php

namespace App\Modules\HR\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Thrown when an overtime / compensation transition is attempted from a state that
 * doesn't allow it (e.g. approving an entry that is already credited to the ledger).
 *
 * Implementing render() lets the framework turn it straight into a clean 422 so callers
 * don't each need try/catch — the invariant lives in one place (OvertimeService).
 */
class OvertimeStateException extends RuntimeException
{
    public function render($request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
