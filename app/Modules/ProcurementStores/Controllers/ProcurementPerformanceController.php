<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ProcurementStores\Services\ProcurementPerformance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where the buying time goes, and which suppliers keep their word.
 *
 * Read-only and derived — nothing here is stored, so the figures cannot drift
 * from the transactions they describe.
 */
class ProcurementPerformanceController extends Controller
{
    private const ROLES = ['Stores', 'Procurement', 'Manager', 'Super Admin'];

    private function permitted(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(self::ROLES);
    }

    public function index(Request $request, ProcurementPerformance $performance): JsonResponse
    {
        if (! $this->permitted()) {
            return response()->json(['message' => 'You are not permitted to view procurement performance.'], 403);
        }

        $from = $request->query('from');
        $to = $request->query('to');

        $stages = $performance->stages($from, $to);

        return response()->json([
            'data' => [
                'stages' => $stages['stages'],
                'suppliers' => $performance->suppliers($from, $to)->all(),
            ],
            'summary' => $stages['summary'],
            'period' => ['from' => $from, 'to' => $to],
        ]);
    }
}
