<?php

namespace App\Modules\Projects\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use App\Modules\Projects\Services\ProjectsDashboardService;
use App\Constants\Permissions;

/**
 * @OA\Schema(
 *     schema="DashboardMetrics",
 *     @OA\Property(property="total_enquiries", type="integer", example=25),
 *     @OA\Property(property="active_enquiries", type="integer", example=12),
 *     @OA\Property(property="completed_enquiries", type="integer", example=8),
 *     @OA\Property(property="pending_enquiries", type="integer", example=5),
 *     @OA\Property(property="total_tasks", type="integer", example=45),
 *     @OA\Property(property="completed_tasks", type="integer", example=32),
 *     @OA\Property(property="overdue_tasks", type="integer", example=3),
 *     @OA\Property(property="total_projects", type="integer", example=15)
 * )
 *
 * @OA\Schema(
 *     schema="ActivityItem",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="type", type="string", enum={"task_completed","enquiry_created","task_assigned","quote_approved"}),
 *     @OA\Property(property="description", type="string", example="Task 'Site Survey' was completed"),
 *     @OA\Property(property="user_name", type="string", example="John Doe"),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="AlertItem",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="type", type="string", enum={"overdue_task","upcoming_deadline","unassigned_task"}),
 *     @OA\Property(property="title", type="string", example="Overdue Task Alert"),
 *     @OA\Property(property="message", type="string", example="Task 'Design Review' is 2 days overdue"),
 *     @OA\Property(property="priority", type="string", enum={"low","medium","high","urgent"}),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 */
class DashboardController extends Controller
{
    protected ProjectsDashboardService $dashboardService;

    public function __construct(ProjectsDashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    /**
     * @OA\Get(
     *     path="/api/projects/dashboard/enquiry-metrics",
     *     summary="Get enquiry metrics for dashboard",
     *     tags={"Dashboard"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Enquiry metrics retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/DashboardMetrics"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=500, description="Internal server error")
     * )
     */
    public function enquiryMetrics(Request $request): JsonResponse
    {
        // Check permissions
        if (!Auth::user()->hasPermissionTo(Permissions::DASHBOARD_PROJECTS) &&
            !Auth::user()->hasRole(['Super Admin', 'Project Manager', 'Project Officer', 'HR'])) {
            return response()->json([
                'message' => 'Unauthorized access to dashboard metrics'
            ], 403);
        }

        try {
            $metrics = $this->dashboardService->getEnquiryMetrics();

            return response()->json([
                'data' => $metrics,
                'message' => 'Enquiry metrics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve enquiry metrics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/projects/dashboard/task-metrics",
     *     summary="Get task metrics for dashboard",
     *     tags={"Dashboard"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Task metrics retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/DashboardMetrics"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=500, description="Internal server error")
     * )
     */
    public function taskMetrics(Request $request): JsonResponse
    {
        // Check permissions
        if (!Auth::user()->hasPermissionTo(Permissions::DASHBOARD_PROJECTS) &&
            !Auth::user()->hasRole(['Super Admin', 'Project Manager', 'Project Officer', 'HR'])) {
            return response()->json([
                'message' => 'Unauthorized access to dashboard metrics'
            ], 403);
        }

        try {
            $metrics = $this->dashboardService->getTaskMetrics();

            return response()->json([
                'data' => $metrics,
                'message' => 'Task metrics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve task metrics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get project metrics for dashboard
     */
    public function projectMetrics(Request $request): JsonResponse
    {
        // Check permissions
        if (!Auth::user()->hasPermissionTo(Permissions::DASHBOARD_PROJECTS) &&
            !Auth::user()->hasRole(['Super Admin', 'Project Manager', 'Project Officer', 'HR'])) {
            return response()->json([
                'message' => 'Unauthorized access to dashboard metrics'
            ], 403);
        }

        try {
            $metrics = $this->dashboardService->getProjectMetrics();

            return response()->json([
                'data' => $metrics,
                'message' => 'Project metrics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve project metrics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get financial metrics for dashboard
     */
    public function financialMetrics(Request $request): JsonResponse
    {
        // Check permissions
        if (!Auth::user()->hasPermissionTo(Permissions::DASHBOARD_PROJECTS) &&
            !Auth::user()->hasRole(['Super Admin', 'Project Manager', 'Project Officer', 'HR'])) {
            return response()->json([
                'message' => 'Unauthorized access to dashboard metrics'
            ], 403);
        }

        try {
            $metrics = $this->dashboardService->getFinancialMetrics();

            return response()->json([
                'data' => $metrics,
                'message' => 'Financial metrics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve financial metrics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/projects/dashboard/recent-activities",
     *     summary="Get recent activities for dashboard",
     *     tags={"Dashboard"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Number of activities to retrieve",
     *         @OA\Schema(type="integer", default=10, minimum=1, maximum=50)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Recent activities retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/ActivityItem")),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=500, description="Internal server error")
     * )
     */
    public function recentActivities(Request $request): JsonResponse
    {
        // Check permissions
        if (!Auth::user()->hasPermissionTo(Permissions::DASHBOARD_PROJECTS) &&
            !Auth::user()->hasRole(['Super Admin', 'Project Manager', 'Project Officer', 'HR'])) {
            return response()->json([
                'message' => 'Unauthorized access to dashboard activities'
            ], 403);
        }

        try {
            $limit = $request->get('limit', 10);
            $activities = $this->dashboardService->getRecentActivities($limit);

            return response()->json([
                'data' => $activities,
                'message' => 'Recent activities retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve recent activities',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get alerts for dashboard
     */
    public function alerts(Request $request): JsonResponse
    {
        // Check permissions
        if (!Auth::user()->hasPermissionTo(Permissions::DASHBOARD_PROJECTS) &&
            !Auth::user()->hasRole(['Super Admin', 'Project Manager', 'Project Officer', 'HR'])) {
            return response()->json([
                'message' => 'Unauthorized access to dashboard alerts'
            ], 403);
        }

        try {
            $alerts = $this->dashboardService->getAlerts();

            return response()->json([
                'data' => $alerts,
                'message' => 'Alerts retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve alerts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/projects/dashboard/command-center",
     *     summary="Get Project Command Center data",
     *     tags={"Dashboard"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Success"),
     *     @OA\Response(response=403, description="Unauthorized")
     * )
     */
    public function commandCenter(Request $request): JsonResponse
    {
         if (!Auth::user()->hasPermissionTo(Permissions::DASHBOARD_PROJECTS) &&
            !Auth::user()->hasRole(['Super Admin', 'Project Manager', 'Project Officer', 'HR'])) {
            return response()->json([
                'message' => 'Unauthorized access to command center'
            ], 403);
        }

         try {
             $data = $this->dashboardService->getCommandCenterData();
             return response()->json([
                 'data' => $data,
                 'message' => 'Command Center data retrieved'
             ]);
         } catch (\Exception $e) {
             return response()->json([
                 'message' => 'Failed to retrieve command center data',
                 'error' => $e->getMessage()
             ], 500);
         }
    }

    /**
     * @OA\Get(
     *     path="/api/projects/dashboard",
     *     summary="Get comprehensive dashboard data",
     *     tags={"Dashboard"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Dashboard data retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="enquiry_metrics", ref="#/components/schemas/DashboardMetrics"),
     *                 @OA\Property(property="task_metrics", ref="#/components/schemas/DashboardMetrics"),
     *                 @OA\Property(property="project_metrics", ref="#/components/schemas/DashboardMetrics"),
     *                 @OA\Property(property="recent_activities", type="array", @OA\Items(ref="#/components/schemas/ActivityItem")),
     *                 @OA\Property(property="alerts", type="array", @OA\Items(ref="#/components/schemas/AlertItem"))
     *             ),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=500, description="Internal server error")
     * )
     */
    public function dashboard(Request $request): JsonResponse
    {
        // Check permissions
        if (!Auth::user()->hasPermissionTo(Permissions::DASHBOARD_PROJECTS) &&
            !Auth::user()->hasRole(['Super Admin', 'Project Manager', 'Project Officer', 'HR'])) {
            return response()->json([
                'message' => 'Unauthorized access to dashboard'
            ], 403);
        }

        try {
            $data = [
                'enquiry_metrics' => $this->dashboardService->getEnquiryMetrics(),
                'task_metrics' => $this->dashboardService->getTaskMetrics(),
                'project_metrics' => $this->dashboardService->getProjectMetrics(),
                'financial_metrics' => $this->dashboardService->getFinancialMetrics(),
                'recent_activities' => $this->dashboardService->getRecentActivities(10),
                'alerts' => $this->dashboardService->getAlerts(),
            ];

            return response()->json([
                'data' => $data,
                'message' => 'Dashboard data retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve dashboard data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Filter dashboard data
     */
    public function filterDashboard(Request $request): JsonResponse
    {
        // Check permissions
        if (!Auth::user()->hasPermissionTo(Permissions::DASHBOARD_PROJECTS) &&
            !Auth::user()->hasRole(['Super Admin', 'Project Manager', 'Project Officer', 'HR'])) {
            return response()->json([
                'message' => 'Unauthorized access to dashboard filtering'
            ], 403);
        }

        try {
            $filters = $request->all();
            $filteredData = $this->dashboardService->getFilteredDashboardData($filters);

            return response()->json([
                'data' => $filteredData,
                'message' => 'Filtered dashboard data retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to filter dashboard data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/projects/dashboard/export/pdf",
     *     summary="Export dashboard data to PDF",
     *     tags={"Dashboard"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="filters", type="object",
     *                 @OA\Property(property="date_from", type="string", format="date"),
     *                 @OA\Property(property="date_to", type="string", format="date"),
     *                 @OA\Property(property="department_id", type="integer"),
     *                 @OA\Property(property="status", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="PDF export generated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="file_path", type="string"),
     *                 @OA\Property(property="download_url", type="string")
     *             ),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=500, description="Internal server error")
     * )
     */
    public function exportToPDF(Request $request): JsonResponse
    {
        // Check permissions
        if (!Auth::user()->hasPermissionTo(Permissions::DASHBOARD_PROJECTS) &&
            !Auth::user()->hasRole(['Super Admin', 'Project Manager', 'Project Officer', 'HR'])) {
            return response()->json([
                'message' => 'Unauthorized access to dashboard export'
            ], 403);
        }

        try {
            $filters = $request->get('filters', []);
            $filePath = $this->dashboardService->exportToPDF($filters);

            return response()->json([
                'data' => [
                    'file_path' => $filePath,
                    'download_url' => url('storage/' . $filePath)
                ],
                'message' => 'PDF export generated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate PDF export',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/projects/dashboard/export/excel",
     *     summary="Export dashboard data to Excel",
     *     tags={"Dashboard"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="filters", type="object",
     *                 @OA\Property(property="date_from", type="string", format="date"),
     *                 @OA\Property(property="date_to", type="string", format="date"),
     *                 @OA\Property(property="department_id", type="integer"),
     *                 @OA\Property(property="status", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Excel export generated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="file_path", type="string"),
     *                 @OA\Property(property="download_url", type="string")
     *             ),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=500, description="Internal server error")
     * )
     */
    public function exportToExcel(Request $request): JsonResponse
    {
        // Check permissions
        if (!Auth::user()->hasPermissionTo(Permissions::DASHBOARD_PROJECTS) &&
            !Auth::user()->hasRole(['Super Admin', 'Project Manager', 'Project Officer', 'HR'])) {
            return response()->json([
                'message' => 'Unauthorized access to dashboard export'
            ], 403);
        }

        try {
            $filters = $request->get('filters', []);
            $filePath = $this->dashboardService->exportToExcel($filters);

            return response()->json([
                'data' => [
                    'file_path' => $filePath,
                    'download_url' => url('storage/' . $filePath)
                ],
                'message' => 'Excel export generated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate Excel export',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
