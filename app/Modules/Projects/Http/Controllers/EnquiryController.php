<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Models\ProjectEnquiry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controller;
use App\Modules\Projects\Services\EnquiryWorkflowService;
use App\Constants\Permissions;
use App\Constants\EnquiryConstants;
use App\Modules\Projects\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Modules\Projects\Services\FinanceService;
use App\Modules\Projects\Actions\ApproveQuoteAction;
use App\Modules\Projects\Actions\ReleaseFinanceGateAction;
use App\Services\Governance\ProjectGovernanceService;

/**
 * @OA\Schema(
 *     schema="ProjectEnquiry",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="enquiry_number", type="string", example="ENQ-11-2025-001"),
 *     @OA\Property(property="title", type="string", example="Office Branding Project"),
 *     @OA\Property(property="description", type="string", example="Complete branding solution for new office"),
 *             @OA\Property(property="status", type="string", enum={"draft","pending","in_progress","quote_approved","completed","cancelled"}),
 *     @OA\Property(property="priority", type="string", enum={"low","medium","high","urgent"}),
 *     @OA\Property(property="client_id", type="integer"),
 *     @OA\Property(property="contact_person", type="string", example="John Smith"),
 *     @OA\Property(property="estimated_budget", type="number", format="float", nullable=true),
 *     @OA\Property(property="date_received", type="string", format="date"),
 *     @OA\Property(property="expected_delivery_date", type="string", format="date", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class EnquiryController extends Controller
{
    protected $notificationService;
    protected $financeService;
    protected $governanceService;
    protected $sequencingService;

    public function __construct(
        NotificationService $notificationService, 
        FinanceService $financeService,
        ProjectGovernanceService $governanceService,
        \App\Modules\Projects\Services\SequencingService $sequencingService
    ) {
        $this->notificationService = $notificationService;
        $this->financeService = $financeService;
        $this->governanceService = $governanceService;
        $this->sequencingService = $sequencingService;
    }

    /**
     * Get a list of approved projects with WNG- job numbers
     * Sorted by latest first (WNG-01-2026-010, WNG-01-2026-009, etc.)
     */
    public function approvedWngList(): JsonResponse
    {
        $projects = ProjectEnquiry::where('quote_approved', true)
            ->whereNotNull('job_number')
            ->select('id', 'job_number', 'title')
            // Sort by Year DESC, Month DESC, Sequential Number DESC
            // Format: WNG-MM-YYYY-NNN
            ->orderByRaw('
                CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(job_number, "-", 3), "-", -1) AS UNSIGNED) DESC,
                CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(job_number, "-", 2), "-", -1) AS UNSIGNED) DESC,
                CAST(SUBSTRING_INDEX(job_number, "-", -1) AS UNSIGNED) DESC
            ')
            ->take(100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $projects
        ]);
    }


    /**
     * @OA\Get(
     *     path="/api/projects/enquiries",
     *     summary="Get all enquiries with pagination and filters",
     *     tags={"Enquiries"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by title, client name, or contact person",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by status",
     *         @OA\Schema(type="string", enum={"draft","pending","in_progress","quote_approved","completed","cancelled"})
     *     ),
     *     @OA\Parameter(
     *         name="client_id",
     *         in="query",
     *         description="Filter by client ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="department_id",
     *         in="query",
     *         description="Filter by department ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Enquiries retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/ProjectEnquiry")),
     *                 @OA\Property(property="current_page", type="integer"),
     *                 @OA\Property(property="last_page", type="integer"),
     *                 @OA\Property(property="per_page", type="integer"),
     *                 @OA\Property(property="total", type="integer")
     *             ),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProjectEnquiry::with('client', 'department', 'projectOfficer', 'enquiryTasks.assignedUsers', 'enquiryTasks.assignedTo');

        $query = app(\Illuminate\Pipeline\Pipeline::class)
            ->send($query)
            ->through([
                \App\Modules\Projects\Filters\Enquiry\SearchFilter::class,
                \App\Modules\Projects\Filters\Enquiry\StatusFilter::class,
                \App\Modules\Projects\Filters\Enquiry\ViewTypeFilter::class,
                \App\Modules\Projects\Filters\Enquiry\DateRangeFilter::class,
                \App\Modules\Projects\Filters\Enquiry\ClientFilter::class,
                \App\Modules\Projects\Filters\Enquiry\OfficerFilter::class,
            ])
            ->thenReturn();

        // 2. Apply "Sub-Tab" filtering (Status Groups) - Kept for flexibility for now
        if ($request->filled('sub_status') && $request->sub_status !== 'all') {
            $subStatus = $request->sub_status;
            $activeProjectStatuses = ['quote_approved', 'planning', 'in_progress'];
            $completedOnlyStatuses = ['completed'];
            $cancelledStatuses = ['cancelled'];
            $closedStatuses = array_merge($completedOnlyStatuses, $cancelledStatuses);
            
            if ($subStatus === 'new' || $subStatus === 'pipeline') {
                $query->whereNotIn('status', array_merge($activeProjectStatuses, $closedStatuses));
            } elseif ($subStatus === 'in_progress_active' || $subStatus === 'active') {
                $query->whereIn('status', $activeProjectStatuses);
            } elseif ($subStatus === 'completed' || $subStatus === 'finished') {
                $query->whereIn('status', $completedOnlyStatuses);
            } elseif ($subStatus === 'cancelled' || $subStatus === 'canceled') {
                $query->whereIn('status', $cancelledStatuses);
            } elseif ($subStatus === 'internal_job' || $subStatus === 'sponsorship') {
                $query->where('workflow_preset_type', $subStatus);
            } elseif ($subStatus === 'in_progress_enquiry') {
                $query->whereIn('status', ['site_survey_completed', 'design_completed', 'design_approved', 'materials_specified', 'budget_created', 'quote_prepared']);
            } elseif ($subStatus === 'pre_prod') {
                $query->whereIn('status', ['quote_approved', 'planning']);
            }
        }

        $view = $request->input('view', 'enquiries');
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $allowedSorts = ['created_at', 'expected_delivery_date', 'estimated_budget', 'title', 'priority', 'job_number'];

        if ($view === 'projects' && !$request->has('sort_by')) {
            $sortBy = 'job_number';
        }

        if (in_array($sortBy, $allowedSorts)) {
            if ($sortBy === 'priority') {
                $direction = strtolower($sortOrder) === 'asc' ? 'ASC' : 'DESC';
                $query->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low') $direction");
            } elseif ($sortBy === 'job_number') {
                $direction = strtolower($sortOrder) === 'desc' ? 'DESC' : 'ASC';
                $query->orderByRaw("
                    CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(job_number, '-', 3), '-', -1) AS UNSIGNED) $direction,
                    CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(job_number, '-', 2), '-', -1) AS UNSIGNED) $direction,
                    CAST(SUBSTRING_INDEX(job_number, '-', -1) AS UNSIGNED) $direction
                ");
            } else {
                $query->orderBy($sortBy, $sortOrder);
            }
        } else {
            $query->orderBy('created_at', 'desc');
        }

        // Paginate results (Defaulting to 15)
        $perPage = $request->get('per_page', EnquiryConstants::PAGINATION_PER_PAGE);
        $enquiries = $query->paginate($perPage);

        // Enrich with payment progress for receivables/projects view
        $enquiries->getCollection()->transform(function ($enquiry) {
            $progress = $this->financeService->getPaymentProgress($enquiry);
            $enquiry->payment_progress_percentage = $progress['percentage'];
            return $enquiry;
        });

        return response()->json([
            'data' => \App\Modules\Projects\Resources\EnquiryResource::collection($enquiries)->response()->getData(true),
            'message' => 'Enquiries retrieved successfully'
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/projects/enquiries",
     *     summary="Create a new project enquiry",
     *     tags={"Enquiries"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"date_received","client_id","title","contact_person","status"},
     *             @OA\Property(property="date_received", type="string", format="date", example="2024-01-15"),
     *             @OA\Property(property="expected_delivery_date", type="string", format="date", nullable=true, example="2024-02-15"),
     *             @OA\Property(property="client_id", type="integer", example=1),
     *             @OA\Property(property="title", type="string", example="Office Branding Project"),
     *             @OA\Property(property="description", type="string", example="Complete branding solution for new office"),
     *             @OA\Property(property="project_scope", type="string", example="Logo design, signage, and interior branding"),
     *             @OA\Property(property="priority", type="string", enum={"low","medium","high","urgent"}, example="high"),
     *             @OA\Property(property="contact_person", type="string", example="John Smith"),
     *             @OA\Property(property="status", type="string", enum={"draft","pending","in_progress","quote_approved","completed","cancelled"}, example="pending"),
     *             @OA\Property(property="department_id", type="integer", nullable=true, example=1),
     *             @OA\Property(property="estimated_budget", type="number", format="float", nullable=true, example=50000.00),
     *             @OA\Property(property="venue", type="string", nullable=true, example="Downtown Office Building"),
     *             @OA\Property(property="assigned_po", type="integer", nullable=true, example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Enquiry created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", ref="#/components/schemas/ProjectEnquiry")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function store(\App\Http\Requests\Modules\Projects\Enquiry\StoreEnquiryRequest $request): JsonResponse
    {
        Log::info('Enquiry store request received', [
            'user_id' => Auth::id(),
            'payload' => $request->except(['project_scope']),
        ]);

        $validatedData = $request->validated();
        
        // Handle field name alias for enquiry_title
        if ($request->has('enquiry_title') && !isset($validatedData['title'])) {
            $validatedData['title'] = $request->enquiry_title;
        }

        try {
            DB::beginTransaction();

            // Generate numbers using SequencingService
            $validatedData['enquiry_number'] = $this->sequencingService->generateEnquiryNumber();
            
            $validatedData['created_by'] = Auth::id();
            $validatedData['assigned_to'] = $validatedData['assigned_po'] ?? Auth::id();

            $enquiry = ProjectEnquiry::create($validatedData);

            // Auto-create initial tasks based on workflow preset
            app(\App\Modules\Projects\Services\EnquiryWorkflowService::class)->initializeWorkflow($enquiry);

            DB::commit();

            return response()->json([
                'message' => 'Enquiry created successfully',
                'data' => new \App\Modules\Projects\Resources\EnquiryResource($enquiry->load('client', 'department'))
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Enquiry creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to create enquiry',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/projects/enquiries/{enquiry}",
     *     summary="Get enquiry details",
     *     tags={"Enquiries"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="enquiry",
     *         in="path",
     *         required=true,
     *         description="Enquiry ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Enquiry details retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/ProjectEnquiry"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Enquiry not found"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function show(ProjectEnquiry $enquiry): JsonResponse
    {
        return response()->json([
            'data' => $enquiry->load('client', 'department', 'projectOfficer', 'enquiryTasks'),
            'message' => 'Enquiry retrieved successfully'
        ]);
    }

    /**
     * Get project by enquiry ID
     */
    public function getByProjectEnquiryId($enquiryId)
    {
        $project = \App\Models\Project::where('enquiry_id', $enquiryId)->first();

        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        return response()->json([
            'data' => $project,
            'message' => 'Project retrieved successfully'
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/projects/enquiries/{enquiry}/complete-details",
     *     summary="Get complete enquiry details with aggregated task data",
     *     tags={"Enquiries"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="enquiry",
     *         in="path",
     *         required=true,
     *         description="Enquiry ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Complete enquiry details with aggregated data",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Enquiry not found"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function getCompleteDetails(ProjectEnquiry $enquiry): JsonResponse
    {
        // Load all relationships
        $enquiry->load([
            'client',
            'department',
            'projectOfficer',
            'enquiryTasks' => function ($query) {
                // Access Control: All users can see tasks in the project (Transparency)
                // Interaction is gated via is_authorized flag on the task model
                $query->with([
                    'assignedUser',
                    'quoteData',
                    'budgetData',
                    'materialsData',
                    'procurementData',
                    'productionData',
                    'handoverSurvey'
                ]);
            }
        ]);

        // Initialize aggregated data
        $completeDetails = [
            'enquiry' => $enquiry,
            'tasks' => $enquiry->enquiryTasks,
            'timeline' => [],
            'financials' => [
                'quote_total' => 0,
                'materials_cost' => 0,
                'production_cost' => 0,
                'logistics_cost' => 0,
                'total_cost' => 0,
                'profit' => 0,
                'profit_margin' => 0,
            ],
            'production' => [
                'total_items' => 0,
                'completed' => 0,
                'in_progress' => 0,
                'pending' => 0,
                'completion_percentage' => 0,
            ],
            'materials' => [
                'total_items' => 0,
                'approved' => 0,
                'pending' => 0,
                'rejected' => 0,
            ],
            'logistics' => null,
            'team' => [],
            'documents' => [],
            'metrics' => [
                'days_since_enquiry' => now()->diffInDays($enquiry->created_at),
                'days_to_delivery' => $enquiry->expected_delivery_date ? now()->diffInDays($enquiry->expected_delivery_date, false) : null,
                'on_schedule' => true,
                'overall_progress' => 0,
                'tasks_completed' => 0,
                'tasks_in_progress' => 0,
                'tasks_pending' => 0,
            ],
        ];

        // Process each task and aggregate data
        foreach ($enquiry->enquiryTasks as $task) {
            // Build timeline
            $completeDetails['timeline'][] = [
                'task_type' => $task->task_type,
                'status' => $task->status,
                'assigned_to' => $task->assignedUser?->name,
                'due_date' => $task->due_date,
                'completed_at' => $task->completed_at,
                'created_at' => $task->created_at,
            ];

            // Count task statuses for metrics
            if ($task->status === 'completed') {
                $completeDetails['metrics']['tasks_completed']++;
            } elseif ($task->status === 'in_progress') {
                $completeDetails['metrics']['tasks_in_progress']++;
            } else {
                $completeDetails['metrics']['tasks_pending']++;
            }

            // Add team members
            if ($task->assignedUser) {
                $completeDetails['team'][] = [
                    'user_id' => $task->assignedUser->id,
                    'name' => $task->assignedUser->name,
                    'email' => $task->assignedUser->email,
                    'role' => $task->task_type,
                ];
            }

            // Aggregate task-specific data
            $taskData = $task->taskData;
            
            if ($task->task_type === 'Quote' || $task->task_type === 'Quote Approval') {
                // Extract quote financial data
                if ($taskData && isset($taskData->grand_total)) {
                    $completeDetails['financials']['quote_total'] = (float) $taskData->grand_total;
                }
            }

            if ($task->task_type === 'Materials') {
                // Extract materials data
                if ($taskData && isset($taskData->materials)) {
                    $materials = is_string($taskData->materials) ? json_decode($taskData->materials, true) : $taskData->materials;
                    if (is_array($materials)) {
                        $completeDetails['materials']['total_items'] = count($materials);
                        foreach ($materials as $material) {
                            if (isset($material['approval_status'])) {
                                if ($material['approval_status'] === 'approved') {
                                    $completeDetails['materials']['approved']++;
                                    if (isset($material['total_cost'])) {
                                        $completeDetails['financials']['materials_cost'] += (float) $material['total_cost'];
                                    }
                                } elseif ($material['approval_status'] === 'pending') {
                                    $completeDetails['materials']['pending']++;
                                } elseif ($material['approval_status'] === 'rejected') {
                                    $completeDetails['materials']['rejected']++;
                                }
                            }
                        }
                    }
                }
            }

            if ($task->task_type === 'Production') {
                // Extract production data
                if ($taskData && isset($taskData->elements)) {
                    $elements = is_string($taskData->elements) ? json_decode($taskData->elements, true) : $taskData->elements;
                    if (is_array($elements)) {
                        $completeDetails['production']['total_items'] = count($elements);
                        foreach ($elements as $element) {
                            if (isset($element['production_status'])) {
                                if ($element['production_status'] === 'completed') {
                                    $completeDetails['production']['completed']++;
                                } elseif ($element['production_status'] === 'in_progress') {
                                    $completeDetails['production']['in_progress']++;
                                } else {
                                    $completeDetails['production']['pending']++;
                                }
                            }
                        }
                        if ($completeDetails['production']['total_items'] > 0) {
                            $completeDetails['production']['completion_percentage'] = 
                                round(($completeDetails['production']['completed'] / $completeDetails['production']['total_items']) * 100, 1);
                        }
                    }
                }
            }

            if ($task->task_type === 'Logistics') {
                // Extract logistics data
                if ($taskData) {
                    $completeDetails['logistics'] = [
                        'scheduled' => true,
                        'loading_date' => $taskData->loading_date ?? null,
                        'delivery_date' => $taskData->delivery_date ?? null,
                        'vehicle' => $taskData->vehicle_registration ?? null,
                        'driver' => $taskData->driver_name ?? null,
                        'status' => $task->status,
                    ];
                    if (isset($taskData->transport_cost)) {
                        $completeDetails['financials']['logistics_cost'] = (float) $taskData->transport_cost;
                    }
                }
            }
        }

        // Calculate financial totals
        $completeDetails['financials']['total_cost'] = 
            $completeDetails['financials']['materials_cost'] +
            $completeDetails['financials']['production_cost'] +
            $completeDetails['financials']['logistics_cost'];
        
        $completeDetails['financials']['profit'] = 
            $completeDetails['financials']['quote_total'] - $completeDetails['financials']['total_cost'];
        
        if ($completeDetails['financials']['quote_total'] > 0) {
            $completeDetails['financials']['profit_margin'] = 
                round(($completeDetails['financials']['profit'] / $completeDetails['financials']['quote_total']) * 100, 1);
        }

        // Calculate overall progress
        $totalTasks = count($enquiry->enquiryTasks);
        if ($totalTasks > 0) {
            $completeDetails['metrics']['overall_progress'] = 
                round(($completeDetails['metrics']['tasks_completed'] / $totalTasks) * 100);
        }

        // Deduplicate team members
        $completeDetails['team'] = collect($completeDetails['team'])
            ->unique('user_id')
            ->values()
            ->toArray();

        // Sort timeline by date
        usort($completeDetails['timeline'], function ($a, $b) {
            return strtotime($a['created_at']) - strtotime($b['created_at']);
        });

        return response()->json([
            'data' => $completeDetails,
            'message' => 'Complete enquiry details retrieved successfully'
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/projects/enquiries/{enquiry}",
     *     summary="Update enquiry details",
     *     tags={"Enquiries"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="enquiry",
     *         in="path",
     *         required=true,
     *         description="Enquiry ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string", example="Updated Office Branding Project"),
     *             @OA\Property(property="description", type="string", example="Updated project description"),
     *     @OA\Property(property="status", type="string", enum={"draft","pending","in_progress","quote_approved","completed","cancelled"}),
     *             @OA\Property(property="priority", type="string", enum={"low","medium","high","urgent"}),
     *             @OA\Property(property="estimated_budget", type="number", format="float"),
     *             @OA\Property(property="expected_delivery_date", type="string", format="date"),
     *             @OA\Property(property="contact_person", type="string", example="Jane Doe"),
     *             @OA\Property(property="assigned_po", type="integer", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Enquiry updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", ref="#/components/schemas/ProjectEnquiry")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=404, description="Enquiry not found"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function update(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {

        // Handle field name alias for enquiry_title
        if ($request->has('enquiry_title') && !$request->has('title')) {
            $request->merge(['title' => $request->enquiry_title]);
        }

        $validator = Validator::make($request->all(), [
            'date_received' => 'sometimes|required|date',
            'expected_delivery_date' => 'sometimes|nullable|date|after_or_equal:date_received',
            'client_id' => 'sometimes|required|integer|exists:clients,id',
            'title' => 'sometimes|required|string|max:255',
            'enquiry_title' => 'nullable|string|max:255', // Allow enquiry_title as alias
            'description' => 'sometimes|nullable|string',
            'project_scope' => 'nullable', // Allow array or string
            'priority' => 'nullable|string|in:' . implode(',', EnquiryConstants::getAllPriorities()),
            'contact_person' => 'sometimes|nullable|string|max:255',
            'project_officer_id' => 'nullable|integer|exists:users,id',
            'status' => 'sometimes|required|string|in:' . implode(',', EnquiryConstants::getAllStatuses()),
            'department_id' => 'nullable|integer|exists:departments,id',
            'assigned_department' => 'nullable|string|max:255',
            'project_deliverables' => 'nullable|string',
            'assigned_po' => 'nullable|integer|exists:users,id',
            'follow_up_notes' => 'nullable|string',
            'venue' => 'nullable|string|max:255',
            'site_survey_skipped' => 'boolean',
            'site_survey_skip_reason' => 'nullable|string|required_if:site_survey_skipped,true',
            'selected_workflow_tasks' => 'nullable|array',
            'workflow_preset_type' => 'nullable|string',
            'client_approved_quote' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Validate project officer role if provided
        if ($request->has('project_officer_id') && $request->project_officer_id) {
            $user = \App\Models\User::find($request->project_officer_id);
            if (!$user || !$user->hasRole(['Project Officer', 'Project Manager'])) {
                return response()->json([
                    'message' => 'Invalid project officer assignment',
                    'errors' => ['project_officer_id' => ['Selected user is not a valid project officer']]
                ], 422);
            }
        }

        // Check if Project Officer changed and send notification
    if ($request->has('project_officer_id') && $request->project_officer_id) {
        $oldPO = $enquiry->getOriginal('project_officer_id');
        $newPO = $request->project_officer_id;
        
        if ($oldPO !== $newPO) {
            // PO has changed, send notification to new PO
            $newProjectOfficer = \App\Models\User::find($newPO);
            if ($newProjectOfficer) {
                try {
                    $this->notificationService->sendProjectOfficerAssigned(
                        $enquiry->fresh(['client', 'creator']),
                        $newProjectOfficer,
                        Auth::user()
                    );
                } catch (\Exception $e) {
                    \Log::error("Failed to send PO assignment notification: " . $e->getMessage());
                }
            }
        }
    }

    $enquiry->update($request->only([
            'date_received',
            'expected_delivery_date',
            'client_id',
            'title',
            'description',
            'project_scope',
            'priority',
            'contact_person',
            'project_officer_id',
            'status',
            'department_id',
            'assigned_department',
            'project_deliverables',
            'assigned_po',
            'follow_up_notes',
            'venue',
            'site_survey_skipped',
            'site_survey_skip_reason',
            'selected_workflow_tasks',
            'workflow_preset_type',
            'client_approved_quote',
        ]));

        // Sync workflow tasks (create any newly selected tasks)
        app(EnquiryWorkflowService::class)->initializeWorkflow($enquiry);

        return response()->json([
            'message' => 'Enquiry updated successfully',
            'data' => $enquiry->load('client', 'department', 'projectOfficer')
        ]);
    }

    /**
     * Update only the project deliverables/scope
     */
    public function updateDeliverables(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'project_scope' => 'present|array',
        ]);

        if ($validator->fails()) {
            \Log::error('Deliverables update validation failed', [
                'enquiry_id' => $enquiry->id,
                'errors' => $validator->errors()->toArray(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'received' => $request->all() // For debugging
            ], 422);
        }

        try {
            DB::beginTransaction();

            $newScope = $request->project_scope;

            $enquiry->update([
                'project_scope' => $newScope,
                // Also update the text-based deliverables field for redundancy/legacy compatibility
                'project_deliverables' => is_array($newScope) ? implode(' | ', $newScope) : $newScope
            ]);

            // Notification Logic: If a quote task already exists or enquiry is quote_approved
            $hasQuote = $enquiry->enquiryTasks()->where('type', 'quote')->exists() || $enquiry->quote_approved;
            
            if ($hasQuote) {
                $this->notificationService->sendDeliverablesUpdated(
                    $enquiry->fresh(),
                    Auth::user(),
                    $newScope
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'Deliverables updated successfully',
                'data' => new \App\Modules\Projects\Resources\EnquiryResource($enquiry->load('client', 'department', 'projectOfficer'))
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update deliverables',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/projects/enquiries/{enquiry}",
     *     summary="Delete enquiry",
     *     tags={"Enquiries"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="enquiry",
     *         in="path",
     *         required=true,
     *         description="Enquiry ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Enquiry deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Enquiry not found"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function destroy(ProjectEnquiry $enquiry): JsonResponse
    {
        $enquiry->delete();

        return response()->json([
            'message' => 'Enquiry deleted successfully'
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/projects/enquiries/{enquiry}/approve-quote",
     *     summary="Approve enquiry quote",
     *     tags={"Enquiries"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="enquiry",
     *         in="path",
     *         required=true,
     *         description="Enquiry ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Quote approved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", ref="#/components/schemas/ProjectEnquiry")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Enquiry not found"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function approveQuote(Request $request, ProjectEnquiry $enquiry, ApproveQuoteAction $action): JsonResponse
    {
        try {
            $action->execute($enquiry, Auth::id());

            return response()->json([
                'message' => 'Quote approved successfully. Job number generated and subsequent actions triggered.',
                'data' => $enquiry->fresh(['client', 'department'])
            ]);
        } catch (\Exception $e) {
            Log::error('Error approving quote', [
                'enquiry_id' => $enquiry->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Error approving quote: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updatePhase(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {

        // Implementation for updating enquiry phase
        // This might involve updating status or other phase-related fields
        return response()->json([
            'message' => 'Phase updated successfully',
            'data' => $enquiry
        ]);
    }

    /**
     * Log a new payment against an enquiry
     */
    public function logPayment(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'payment_date' => 'nullable|date',
            'payment_method' => 'nullable|string',
            'transaction_reference' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        try {
            $payment = $this->financeService->logPayment($enquiry, $validated);

            return response()->json([
                'message' => 'Payment logged successfully',
                'data' => $payment->load('recorder'),
                'progress' => $this->financeService->getPaymentProgress($enquiry)
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to log payment: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing payment
     */
    public function updatePayment(Request $request, ProjectEnquiry $enquiry, $paymentId): JsonResponse
    {
        $payment = \App\Models\EnquiryPayment::findOrFail($paymentId);
        
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'payment_date' => 'nullable|date',
            'payment_method' => 'nullable|string',
            'transaction_reference' => 'nullable|string',
            'reason' => 'required|string|min:5', // Mandatory reason for correction
        ]);

        try {
            $updatedPayment = $this->financeService->updatePayment($payment, $validated, $validated['reason']);

            return response()->json([
                'message' => 'Payment updated successfully',
                'data' => $updatedPayment->load('recorder'),
                'progress' => $this->financeService->getPaymentProgress($enquiry)
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to update payment: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Delete a payment
     */
    public function deletePayment(Request $request, ProjectEnquiry $enquiry, $paymentId): JsonResponse
    {
        $payment = \App\Models\EnquiryPayment::findOrFail($paymentId);
        
        $validated = $request->validate([
            'reason' => 'required|string|min:5', // Mandatory reason for deletion
        ]);

        try {
            $this->financeService->deletePayment($payment, $validated['reason']);

            return response()->json([
                'message' => 'Payment deleted successfully',
                'progress' => $this->financeService->getPaymentProgress($enquiry)
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete payment: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get finance progress and payment history
     */
    public function getFinanceProgress(ProjectEnquiry $enquiry): JsonResponse
    {
        try {
            $progress = $this->financeService->getPaymentProgress($enquiry);
            $payments = $enquiry->payments()->with('recorder')->orderBy('payment_date', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'progress' => $progress,
                    'payments' => $payments
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch finance progress: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Manually release a project for production
     */
    public function releaseProject(Request $request, ProjectEnquiry $enquiry, ReleaseFinanceGateAction $action): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        try {
            $action->execute($enquiry, Auth::id(), $validated['notes'] ?? null);

            return response()->json([
                'message' => 'Project released for production successfully',
                'data' => $enquiry->fresh(['client', 'projectOfficer'])
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to release project: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get governance audit logs for an enquiry
     */
    public function getGovernanceTrace(ProjectEnquiry $enquiry): JsonResponse
    {
        try {
            $logs = \App\Models\GovernanceAuditLog::with('user')
                ->where('project_enquiry_id', $enquiry->id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $logs
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch governance logs: ' . $e->getMessage()], 500);
        }
    }
}
