<?php

namespace App\Modules\Projects\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use App\Modules\Projects\Services\ProjectsDashboardService;
use App\Modules\Projects\Http\Controllers\Concerns\HandlesProjectErrors;
use App\Constants\Permissions;
use App\Constants\EnquiryConstants;
use Carbon\Carbon;

/**
 * Lean projects dashboard — one endpoint, one payload.
 */
class DashboardController extends Controller
{
    use HandlesProjectErrors;

    public function __construct(private ProjectsDashboardService $dashboardService)
    {
    }

    /**
     * @OA\Get(
     *     path="/api/projects/dashboard",
     *     summary="Get the projects dashboard (KPIs + ranked signals)",
     *     tags={"Dashboard"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", description="current_month (default), month, or all", @OA\Schema(type="string")),
     *     @OA\Parameter(name="month", in="query", description="Target month as YYYY-MM (used when period=month)", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Dashboard data retrieved successfully"),
     *     @OA\Response(response=403, description="Unauthorized")
     * )
     */
    public function dashboard(Request $request): JsonResponse
    {
        $this->authorizeDashboard();

        $period = $this->resolvePeriod($request);

        return $this->safe(function () use ($period) {
            return response()->json([
                'data' => $this->dashboardService->getDashboard($period),
                'message' => 'Dashboard data retrieved successfully',
            ]);
        }, 'Dashboard', 500);
    }

    /**
     * Turn the request into a period: current month (default), a specific
     * month (YYYY-MM), or all-time (a null range).
     *
     * @return array{key:string,label:string,start:?Carbon,end:?Carbon}
     */
    private function resolvePeriod(Request $request): array
    {
        $period = $request->query('period', 'current_month');

        if ($period === 'all') {
            return ['key' => 'all', 'label' => 'All time', 'start' => null, 'end' => null];
        }

        $month = $period === 'month' ? $request->query('month') : null;
        $key = $period === 'month' ? 'month' : 'current_month';

        try {
            $anchor = $month
                ? Carbon::createFromFormat('Y-m', $month)->startOfMonth()
                : Carbon::now()->startOfMonth();
        } catch (\Throwable) {
            // Bad month string falls back to the current month.
            $anchor = Carbon::now()->startOfMonth();
            $key = 'current_month';
        }

        return [
            'key' => $key,
            'label' => $anchor->format('F Y'),
            'start' => $anchor->copy()->startOfMonth(),
            'end' => $anchor->copy()->endOfMonth(),
        ];
    }

    /**
     * Dashboard is visible to anyone with the projects-dashboard permission
     * or a workflow role.
     */
    private function authorizeDashboard(): void
    {
        if (
            !Auth::user()->hasPermissionTo(Permissions::DASHBOARD_PROJECTS) &&
            !Auth::user()->hasRole(EnquiryConstants::ROLES_WORKFLOW)
        ) {
            abort(403, 'Unauthorized access to project dashboard');
        }
    }
}
