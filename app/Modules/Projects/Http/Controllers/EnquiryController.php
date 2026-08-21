<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Models\ProjectEnquiry;
use App\Modules\Projects\Http\Controllers\Concerns\HandlesProjectErrors;
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
use Illuminate\Validation\Rule;
use App\Modules\Projects\Services\FinanceService;
use App\Modules\Projects\Actions\ApproveQuoteAction;
use App\Modules\Projects\Actions\ReleaseFinanceGateAction;
use App\Modules\Projects\Actions\CompleteProjectAction;
use App\Modules\Projects\Actions\UpdateEnquiryAction;
use App\Modules\Projects\Services\ProjectWorkflowStateService;
use App\Services\Governance\ProjectGovernanceService;
use App\Services\ProjectFinancialAccess;

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
    use HandlesProjectErrors;

    protected $notificationService;
    protected $financeService;
    protected $governanceService;
    protected $sequencingService;

    public function __construct(
        NotificationService $notificationService, 
        FinanceService $financeService,
        ProjectGovernanceService $governanceService,
        \App\Modules\Projects\Services\SequencingService $sequencingService,
        protected ProjectFinancialAccess $projectFinancialAccess,
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
        return $this->safe(function () {
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
        }, 'List approved WNG projects');
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
        return $this->safe(fn () => $this->runIndex($request), 'List enquiries', 500);
    }

    private function runIndex(Request $request): JsonResponse
    {
        $view = $request->input('view', 'enquiries');

        if ($view === 'receivables') {
            abort_unless(
                $request->user()?->can(\App\Constants\Permissions::FINANCE_RECEIVABLES_READ),
                403,
                'You do not have access to project receivables.'
            );
        }

        // Billing needs only identity and finance-basis relationships. Loading the
        // full project graph here previously serialized every task, assignee and
        // deliverable for as many as 500 rows.
        $query = $view === 'receivables'
            ? ProjectEnquiry::with('client', 'payments', 'quoteApprovals', 'enquiryTasks.quoteData')
            : ProjectEnquiry::with(
                'client',
                'department',
                'projectOfficer',
                'enquiryTasks.assignedUsers',
                'enquiryTasks.assignedTo',
                'enquiryTasks.quoteData',
                'deliverables',
                'payments',
                'quoteApprovals'
            );

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

            if ($subStatus === 'new' || $subStatus === 'pipeline') {
                $query->whereNotIn('status', array_merge(
                    EnquiryConstants::getApprovedProjectStatuses(),
                    EnquiryConstants::getCompletedStatuses(),
                    EnquiryConstants::getClosedStatuses()
                ));
            } elseif ($subStatus === 'in_progress_active' || $subStatus === 'active') {
                $query->whereIn('status', EnquiryConstants::getApprovedProjectStatuses());
            } elseif ($subStatus === 'completed' || $subStatus === 'finished') {
                $query->whereIn('status', EnquiryConstants::getCompletedStatuses());
            } elseif ($subStatus === 'closed') {
                $query->whereIn('status', EnquiryConstants::getFormallyClosedStatuses());
            } elseif ($subStatus === 'cancelled' || $subStatus === 'canceled') {
                $query->whereIn('status', EnquiryConstants::getCancelledStatuses());
            } elseif ($subStatus === 'internal_job' || $subStatus === 'sponsorship') {
                $query->where('workflow_preset_type', $subStatus);
            } elseif ($subStatus === 'in_progress_enquiry') {
                $query->whereIn('status', EnquiryConstants::getInProgressEnquiryStatuses());
            } elseif ($subStatus === 'pre_prod') {
                $query->whereIn('status', EnquiryConstants::getPreProductionStatuses());
            }
        }

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
            $enquiry->finance_summary = $progress;
            $enquiry->payment_progress_percentage = $progress['percentage'];
            $enquiry->payment_total_quote = $progress['total_quote'];
            $enquiry->payment_total_paid = $progress['total_paid'];
            $enquiry->payment_remaining = $progress['remaining'];
            $enquiry->payment_threshold_amount = $progress['threshold_amount'];
            $enquiry->payment_amount_required_for_threshold = $progress['amount_required_for_threshold'];
            return $enquiry;
        });

        $resource = $view === 'receivables'
            ? \App\Modules\Projects\Resources\ReceivablesEnquiryResource::class
            : \App\Modules\Projects\Resources\EnquiryResource::class;

        return response()->json([
            'data' => $resource::collection($enquiries)->response()->getData(true),
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
                'data' => new \App\Modules\Projects\Resources\EnquiryResource($enquiry->load('client', 'department', 'deliverables'))
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
        return $this->safe(fn () => response()->json([
            'data' => $enquiry->load('client', 'department', 'projectOfficer', 'enquiryTasks'),
            'message' => 'Enquiry retrieved successfully'
        ]), 'Show enquiry');
    }

    /**
     * Get project by enquiry ID
     */
    public function getByProjectEnquiryId($enquiryId): JsonResponse
    {
        return $this->safe(function () use ($enquiryId) {
            $project = \App\Models\Project::where('enquiry_id', $enquiryId)->first();

            if (!$project) {
                return response()->json(['message' => 'Project not found'], 404);
            }

            return response()->json([
                'data' => $project,
                'message' => 'Project retrieved successfully'
            ]);
        }, 'Get project by enquiry');
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
        return $this->safe(fn () => $this->buildCompleteDetails($enquiry), 'Enquiry complete details', 500);
    }

    private function buildCompleteDetails(ProjectEnquiry $enquiry): JsonResponse
    {
        // Load all relationships
        $enquiry->load([
            'client',
            'department',
            'projectOfficer',
            'deliverables',
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
    public function update(Request $request, ProjectEnquiry $enquiry, CompleteProjectAction $completionAction, UpdateEnquiryAction $updateAction): JsonResponse
    {
        return $updateAction->execute($request, $enquiry, $completionAction);
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
            ], 422);
        }

        try {
            DB::beginTransaction();

            $newScope = $request->project_scope;

            $enquiry->update(['project_scope' => $newScope]);

            // Dynamic Synchronisation back to existing Quote & Material Tasks!
            if (is_array($newScope)) {
                // Build scopeId → name map for materials task propagation
                $scopeItems = [];
                foreach ($newScope as $scopeItem) {
                    $deliverableName = $scopeItem['name'] ?? '';
                    $deliverableId   = $scopeItem['uuid'] ?? $scopeItem['id'] ?? '';
                    if ($deliverableId) {
                        $scopeItems[$deliverableId] = $deliverableName;
                    }
                }

                // A. Sync Quote Task materials — propagate renames, category changes,
                //    and inject new scope items that don't yet have a matching element.
                $quoteTasks = \App\Modules\Projects\Models\EnquiryTask::where('project_enquiry_id', $enquiry->id)
                    ->where('type', 'quote')
                    ->get();

                // Build a full structured map: scopeId → { name, classification }
                // Items are always structured arrays (via EnquiryResource / project_scope accessor)
                $scopeStructured = [];
                foreach ($newScope as $scopeItem) {
                    $id = $scopeItem['uuid'] ?? $scopeItem['id'] ?? null;
                    if ($id) {
                        $scopeStructured[$id] = [
                            'name'           => $scopeItem['name'] ?? 'Scope Item',
                            'classification' => strtoupper($scopeItem['classification'] ?? 'PRE-DEFINED'),
                        ];
                    }
                }

                // Template mapping delegated to the single source of truth
                // @see App\Constants\ScopeClassification

                foreach ($quoteTasks as $quoteTask) {
                    $quoteData = \App\Models\TaskQuoteData::firstOrCreate(
                        ['enquiry_task_id' => $quoteTask->id]
                    );

                    $materials = is_array($quoteData->materials) ? $quoteData->materials : [];

                    // Map existing elements by their scopeId for quick lookup
                    $existingScopeIds = [];
                    $updatedMaterials = [];
                    foreach ($materials as $element) {
                        $scopeId = $element['scopeId'] ?? null;
                        if ($scopeId && isset($scopeStructured[$scopeId])) {
                            // Update name AND category from the new scope
                            $element['name']     = $scopeStructured[$scopeId]['name'];
                            $element['category'] = $scopeStructured[$scopeId]['classification'];
                        }
                        if ($scopeId) $existingScopeIds[$scopeId] = true;
                        $updatedMaterials[] = $element;
                    }

                    // Inject new scope items (in scope but not yet in quote materials)
                    foreach ($scopeStructured as $scopeId => $info) {
                        if (!isset($existingScopeIds[$scopeId])) {
                            $cls  = $info['classification'];
                            $updatedMaterials[] = [
                                'id'               => (string) \Illuminate\Support\Str::uuid(),
                                'scopeId'          => $scopeId,
                                'name'             => $info['name'],
                                'category'         => $cls,
                                'description'      => '',
                                'quantity'         => 1,
                                'baseTotal'        => 0,
                                'marginAmount'     => 0,
                                'marginPercentage' => \App\Constants\ScopeClassification::defaultMargin($cls),
                                'finalTotal'       => 0,
                                'templateId'       => \App\Constants\ScopeClassification::toTemplateId($cls),
                                'materials'        => [],
                            ];
                        }
                    }

                    // Remove elements that are scope-linked but no longer in scope
                    $updatedMaterials = array_values(array_filter($updatedMaterials, function ($el) use ($scopeStructured) {
                        $sid = $el['scopeId'] ?? null;
                        // Keep manually-added elements (no scopeId) and elements still in scope
                        return !$sid || isset($scopeStructured[$sid]);
                    }));

                    $quoteData->update(['materials' => $updatedMaterials]);
                }

                // B. Sync Material Task Elements
                $materialsTasks = \App\Modules\Projects\Models\EnquiryTask::where('project_enquiry_id', $enquiry->id)
                    ->where('type', 'materials')
                    ->get();

                foreach ($materialsTasks as $materialsTask) {
                    $materialsData = \App\Models\TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)->first();
                    if ($materialsData) {
                        // Direct DB updates for any ProjectElement linked to these scope IDs
                        foreach ($scopeItems as $scopeId => $newName) {
                            \App\Models\ProjectElement::where('task_materials_data_id', $materialsData->id)
                                ->where('scope_id', $scopeId)
                                ->update([
                                    'name' => $newName
                                ]);
                        }
                    }
                }
            }

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
                'data' => new \App\Modules\Projects\Resources\EnquiryResource($enquiry->load('client', 'department', 'projectOfficer', 'deliverables'))
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
     * Return a transparency snapshot of whether this project satisfies
     * delivery-completion conditions, and what is blocking it if not.
     *
     * Front-ends use this to render a "Complete Project" button (enabled/disabled)
     * and a checklist of outstanding items.
     */
    public function completionReadiness(ProjectEnquiry $enquiry, CompleteProjectAction $action): JsonResponse
    {
        return $this->safe(function () use ($enquiry, $action) {
            $readiness = $action->buildReadiness($enquiry);

            return response()->json([
                'success' => true,
                'data'    => $readiness,
            ]);
        }, 'Project completion readiness');
    }

    /**
     * Return whether this completed project can be formally closed.
     */
    public function closureReadiness(ProjectEnquiry $enquiry, CompleteProjectAction $action): JsonResponse
    {
        return $this->safe(function () use ($enquiry, $action) {
            $readiness = $action->buildClosureReadiness($enquiry);

            return response()->json([
                'success' => true,
                'data'    => $readiness,
            ]);
        }, 'Project closure readiness');
    }

    /**
     * Mark delivery as completed after verifying operational conditions are met.
     *
     * Conditions (enforced hard-gates — not bypassable via the general update endpoint):
     *  1. Project must be planning or in_progress.
     *  2. No non-closure tasks may be pending or in_progress.
     *
     * The action is logged to the governance audit trail with the acting user's identity.
     */
    public function completeProject(Request $request, ProjectEnquiry $enquiry, CompleteProjectAction $action): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $completed = $action->execute($enquiry, Auth::id(), $validated['notes'] ?? null);

            return response()->json([
                'message' => 'Project completed successfully.',
                'data'    => $completed->load('client', 'projectOfficer'),
            ]);
        } catch (\Exception $e) {
            Log::warning('completeProject blocked', [
                'enquiry_id' => $enquiry->id,
                'user_id'    => Auth::id(),
                'reason'     => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Move a completed project into the Closed tab after handover and report are done.
     */
    public function closeProject(Request $request, ProjectEnquiry $enquiry, CompleteProjectAction $action): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $closed = $action->close($enquiry, Auth::id(), $validated['notes'] ?? null);

            return response()->json([
                'message' => 'Project closed successfully.',
                'data'    => $closed->load('client', 'projectOfficer'),
            ]);
        } catch (\Exception $e) {
            Log::warning('closeProject blocked', [
                'enquiry_id' => $enquiry->id,
                'user_id'    => Auth::id(),
                'reason'     => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
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
        return $this->safe(function () use ($enquiry) {
            $enquiry->delete();

            return response()->json([
                'message' => 'Enquiry deleted successfully'
            ]);
        }, 'Delete enquiry');
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
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
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
        // Phase progression is driven automatically by task completion via SyncEnquiryStatusAction.
        // Manual phase overrides should use the update() endpoint with an explicit status field.
        return response()->json([
            'message' => 'Phase is managed automatically by task completion. Use the update endpoint to set status directly.',
            'data' => $enquiry->only(['id', 'status', 'current_phase'])
        ], 200);
    }

    /**
     * Log a new payment against an enquiry
     */
    public function logPayment(
        Request $request,
        ProjectEnquiry $enquiry
    ): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'received_amount' => 'required|numeric|gte:amount',
            'payment_date' => 'nullable|date',
            'payment_method' => 'required|in:bank_transfer,mpesa,cash,cheque',
            'payment_source_id' => 'required|integer|exists:payment_sources,id',
            'transaction_reference' => [
                Rule::requiredIf(fn () => $request->input('payment_method') !== 'cash'),
                'nullable',
                'string',
                'max:100',
            ],
            'notes' => 'nullable|string|max:1000',
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        try {
            $source = \App\Modules\Finance\Models\PaymentSource::query()
                ->whereKey($validated['payment_source_id'])->where('is_active', true)->firstOrFail();
            $expectedTypes = [
                'bank_transfer' => ['bank'], 'cheque' => ['bank'],
                'mpesa' => ['mobile_money'], 'cash' => ['petty_cash'],
            ];
            if (!in_array($source->type, $expectedTypes[$validated['payment_method']], true)) {
                throw new \DomainException('The receiving account does not match the selected payment method.');
            }
            if ($request->hasFile('evidence')) {
                $validated['evidence_path'] = $request->file('evidence')->store('finance/receipts', 'public');
            }
            $payment = $this->financeService->logPayment($enquiry, $validated);
            $progress = $this->financeService->getPaymentProgress($enquiry->fresh());

            return response()->json([
                'message' => 'Receipt recorded and awaiting independent verification.',
                'data' => $payment->load('recorder'),
                'progress' => $progress,
                'auto_released' => false,
            ]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to log payment.'], 500);
        }
    }

    /**
     * Headline figures and tab counts for the whole receivables book.
     *
     * The billing screen used to derive these in the browser, which meant it had
     * to hold every receivables project to show six numbers — it fetched page
     * one, read `last_page`, then fired every remaining page in parallel. Two
     * consequences: an N-request fan-out that grows with the business, and
     * totals that were only ever right because the client happened to have
     * loaded everything. Anything that capped or failed a page silently
     * understated the book.
     *
     * Computed with `FinanceService::getPaymentProgress` — the same call that
     * produces each row's figures — rather than a SQL aggregate. Quote basis,
     * approval snapshots and waivers decide a project's billable amount, and
     * none of that is expressible as a sum; a faster aggregate would be a
     * headline that disagreed with the rows beneath it.
     *
     * Chunked, so memory stays flat as the book grows.
     */
    public function receivablesSummary(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->can(\App\Constants\Permissions::FINANCE_RECEIVABLES_READ),
            403,
            'You do not have access to project receivables.'
        );

        $stats = [
            'awaiting_release' => 0,
            'in_production' => 0,
            'settled' => 0,
            'total_outstanding' => 0.0,
            'total_project_value' => 0.0,
            'total_paid' => 0.0,
        ];

        // Named for the tabs they drive, so a count and its tab cannot drift.
        $tabs = ['action' => 0, 'partial' => 0, 'mobilized' => 0, 'settled' => 0, 'all' => 0];

        // Pushed through the same filter pipeline the list uses, with the view
        // forced to `receivables`. Re-stating the status set here would let the
        // summary and the list drift onto different populations — and a headline
        // that counts a different set from the rows below it is worse than no
        // headline. The internal/external preset separation rides along for free.
        $request->merge(['view' => 'receivables']);

        $query = app(\Illuminate\Pipeline\Pipeline::class)
            ->send(ProjectEnquiry::with('payments', 'quoteApprovals', 'enquiryTasks.quoteData'))
            ->through([
                \App\Modules\Projects\Filters\Enquiry\ViewTypeFilter::class,
            ])
            ->thenReturn();

        $query->chunkById(200, function ($enquiries) use (&$stats, &$tabs) {
                foreach ($enquiries as $enquiry) {
                    $p = $this->financeService->getPaymentProgress($enquiry);

                    $quote = (float) $p['total_quote'];
                    $paid = (float) $p['total_paid'];
                    $remaining = (float) $p['remaining'];
                    $settled = $remaining <= 0 && $quote > 0;

                    $stats['total_project_value'] += $quote;
                    $stats['total_paid'] += $paid;
                    $stats['total_outstanding'] += $remaining;
                    $tabs['all']++;

                    if ($settled) {
                        $stats['settled']++;
                        $tabs['settled']++;
                    } elseif (in_array($enquiry->status, ['awaiting_deposit', 'quote_approved'], true)) {
                        $stats['awaiting_release']++;
                    } else {
                        $stats['in_production']++;
                    }

                    // Mirrors the client predicates exactly; they are the tab
                    // definitions and moving them must not redefine them.
                    if (! $p['has_approved_quote']
                        || ((float) $p['amount_required_for_threshold'] > 0 && empty($p['finance_released']))) {
                        $tabs['action']++;
                    }
                    if ($paid > 0 && $remaining > 0) {
                        $tabs['partial']++;
                    }
                    if ((float) $p['percentage'] >= (float) $p['threshold_percentage'] && $remaining > 0) {
                        $tabs['mobilized']++;
                    }
                }
        });

        foreach (['total_outstanding', 'total_project_value', 'total_paid'] as $key) {
            $stats[$key] = round($stats[$key], 2);
        }

        return response()->json(['data' => ['stats' => $stats, 'tabs' => $tabs]]);
    }

    public function receivablesPaymentSources(): JsonResponse
    {
        $sources = \App\Modules\Finance\Models\PaymentSource::query()
            ->where('is_active', true)
            ->whereIn('type', ['bank', 'mobile_money', 'petty_cash'])
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type', 'currency']);

        return response()->json(['data' => $sources]);
    }

    public function unallocatedReceipts(): JsonResponse
    {
        $receipts = \App\Modules\Finance\Models\ClientReceipt::query()
            ->with(['paymentSource:id,code,name,type,currency', 'allocations' => fn ($query) => $query
                ->whereNull('reversed_at')->with('enquiry:id,title,job_number')])
            ->withSum(['allocations as allocated_amount' => fn ($query) => $query->whereNull('reversed_at')], 'amount')
            ->orderByDesc('payment_date')
            ->get()
            ->filter(fn ($receipt) => (float) $receipt->received_amount > (float) ($receipt->allocated_amount ?? 0))
            ->values()
            ->map(function ($receipt) {
                $allocated = (float) ($receipt->allocated_amount ?? 0);
                return [
                    'id' => $receipt->id,
                    'received_amount' => (float) $receipt->received_amount,
                    'allocated_amount' => $allocated,
                    'available_amount' => (float) $receipt->received_amount - $allocated,
                    'payment_date' => $receipt->payment_date?->toDateString(),
                    'payment_method' => $receipt->payment_method,
                    'transaction_reference' => $receipt->transaction_reference,
                    'evidence_path' => $receipt->evidence_path,
                    'payment_source' => $receipt->paymentSource,
                    'allocations' => $receipt->allocations,
                ];
            });

        return response()->json(['data' => $receipts]);
    }

    public function allocateReceipt(
        Request $request,
        \App\Modules\Finance\Models\ClientReceipt $receipt
    ): JsonResponse {
        $validated = $request->validate([
            'enquiry_id' => 'required|integer|exists:project_enquiries,id',
            'amount' => 'required|numeric|gt:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $enquiry = ProjectEnquiry::findOrFail($validated['enquiry_id']);
        try {
            $allocation = $this->financeService->allocateReceipt($receipt, $enquiry, $validated);
            return response()->json([
                'message' => 'Receipt allocated and awaiting independent verification.',
                'data' => $allocation->load(['enquiry:id,title,job_number', 'paymentSource']),
            ], 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function verifyPayment(
        Request $request,
        ProjectEnquiry $enquiry,
        $paymentId,
        ReleaseFinanceGateAction $releaseAction
    ): JsonResponse {
        $payment = $enquiry->payments()->findOrFail($paymentId);

        try {
            $verified = $this->financeService->verifyPayment($payment, (int) $request->user()->id);
            $progress = $this->financeService->getPaymentProgress($enquiry->fresh());
            $autoReleased = false;

            if ($progress['is_threshold_met'] && !$enquiry->finance_released) {
                $releaseAction->execute($enquiry->fresh(), (int) $request->user()->id);
                $autoReleased = true;
                $progress = $this->financeService->getPaymentProgress($enquiry->fresh());
            }

            return response()->json([
                'message' => $autoReleased
                    ? 'Receipt verified and project released automatically.'
                    : 'Receipt verified successfully.',
                'data' => $verified,
                'progress' => $progress,
                'auto_released' => $autoReleased,
            ]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Update an existing payment
     */
    public function updatePayment(
        Request $request,
        ProjectEnquiry $enquiry,
        $paymentId
    ): JsonResponse
    {
        // Scope to the route enquiry so a payment cannot be edited through another project's URL
        $payment = $enquiry->payments()->findOrFail($paymentId);

        $validated = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'payment_date' => 'nullable|date',
            'payment_method' => 'required|in:bank_transfer,mpesa,cash,cheque',
            'transaction_reference' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('enquiry_payments', 'transaction_reference')
                    ->where(fn ($query) => $query
                        ->where('payment_source_id', $payment->payment_source_id)
                        ->whereNull('reversed_at'))
                    ->ignore($payment->id),
            ],
            'reason' => 'required|string|min:5', // Mandatory reason for correction
        ]);

        try {
            $updatedPayment = $this->financeService->updatePayment($payment, $validated, $validated['reason']);
            $progress = $this->financeService->getPaymentProgress($enquiry->fresh());

            return response()->json([
                'message' => 'Receipt corrected and returned for independent verification.',
                'data' => $updatedPayment->load('recorder'),
                'progress' => $progress,
                'auto_released' => false,
            ]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to update payment: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Delete a payment
     */
    public function deletePayment(Request $request, ProjectEnquiry $enquiry, $paymentId): JsonResponse
    {
        // Scope to the route enquiry so a payment cannot be deleted through another project's URL
        $payment = $enquiry->payments()->findOrFail($paymentId);

        $validated = $request->validate([
            'reason' => 'required|string|min:5',
        ]);

        try {
            $this->financeService->deletePayment($payment, $validated['reason']);

            return response()->json([
                'message' => 'Receipt reversed successfully',
                'progress' => $this->financeService->getPaymentProgress($enquiry)
            ]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to reverse receipt: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get finance progress and payment history
     */
    public function getFinanceProgress(ProjectEnquiry $enquiry): JsonResponse
    {
        abort_unless($this->projectFinancialAccess->canReadAccount(request()->user(), $enquiry), 403);

        try {
            $progress = $this->financeService->getPaymentProgress($enquiry);
            $payments = $enquiry->payments()->with(['clientReceipt', 'paymentSource', 'recorder', 'verifier', 'reverser'])->orderBy('payment_date', 'desc')->get();

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
     * Record the controlled exception used when a project legitimately has no quote.
     */
    public function waiveQuoteRequirement(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        $validated = $request->validate([
            'billing_amount' => 'required|numeric|gt:0',
            'reason' => 'required|string|min:15|max:1000',
            'mobilization_threshold_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($this->financeService->getPaymentProgress($enquiry)['has_approved_quote']) {
            return response()->json(['message' => 'This project already has an approved quote.'], 422);
        }

        DB::transaction(function () use ($enquiry, $validated, $request) {
            $enquiry->update([
                'quote_requirement_waived' => true,
                'quote_waiver_billing_amount' => $validated['billing_amount'] ?? null,
                'quote_waiver_reason' => $validated['reason'],
                'mobilization_threshold_percentage' => $validated['mobilization_threshold_percentage'] ?? 70,
                'quote_waived_by' => Auth::id(),
                'quote_waived_at' => now(),
            ]);

            \App\Models\GovernanceAuditLog::create([
                'project_enquiry_id' => $enquiry->id,
                'user_id' => Auth::id(),
                'gate_type' => 'financial',
                'action_status' => 'authorized',
                'message' => 'Quote amount recorded directly in Project Billing',
                'context' => [
                    'billing_amount' => $validated['billing_amount'],
                    'reason' => $validated['reason'],
                    'mobilization_threshold_percentage' => $validated['mobilization_threshold_percentage'] ?? 70,
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json([
            'message' => 'Quote amount saved in Project Billing.',
            'data' => ['progress' => $this->financeService->getPaymentProgress($enquiry->fresh())],
        ]);
    }

    public function updateReceivablesTerms(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        $validated = $request->validate([
            'mobilization_threshold_percentage' => 'required|numeric|min:0|max:100',
            'reason' => 'required|string|min:10|max:1000',
        ]);

        DB::transaction(function () use ($request, $enquiry, $validated) {
            $oldThreshold = (float) ($enquiry->mobilization_threshold_percentage ?? 70);
            $enquiry->update(['mobilization_threshold_percentage' => $validated['mobilization_threshold_percentage']]);
            \App\Models\GovernanceAuditLog::create([
                'project_enquiry_id' => $enquiry->id,
                'user_id' => Auth::id(),
                'gate_type' => 'Receivables Terms',
                'action_status' => 'authorized',
                'message' => "Mobilization threshold changed from {$oldThreshold}% to {$validated['mobilization_threshold_percentage']}%",
                'context' => ['reason' => $validated['reason'], 'old_threshold' => $oldThreshold, 'new_threshold' => $validated['mobilization_threshold_percentage']],
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json([
            'message' => 'Receivables terms updated.',
            'data' => ['progress' => $this->financeService->getPaymentProgress($enquiry->fresh())],
        ]);
    }

    public function projectInvoices(ProjectEnquiry $enquiry): JsonResponse
    {
        $invoices = \App\Modules\Finance\Models\ProjectInvoice::query()->where('project_enquiry_id', $enquiry->id)
            ->withSum('payments as paid_amount', 'project_invoice_allocations.amount')->orderByDesc('invoice_date')->get()
            ->map(function ($invoice) {
                $paid = (float) ($invoice->paid_amount ?? 0); $balance = max(0, (float) $invoice->total_amount - $paid);
                return array_merge($invoice->toArray(), ['paid_amount'=>$paid,'balance'=>$balance,'days_overdue'=>$invoice->status==='issued' && $balance>0 && $invoice->due_date->isPast() ? $invoice->due_date->diffInDays(now()) : 0]);
            });
        return response()->json(['data'=>$invoices]);
    }

    public function createProjectInvoice(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        $data = $request->validate(['invoice_date'=>'required|date','due_date'=>'required|date|after_or_equal:invoice_date','subtotal'=>'required|numeric|gt:0','tax_amount'=>'nullable|numeric|min:0','notes'=>'nullable|string|max:1000']);
        $total = (float) $data['subtotal'] + (float) ($data['tax_amount'] ?? 0);
        $basis = $this->financeService->getPaymentProgress($enquiry)['total_quote'];
        $existing = (float) \App\Modules\Finance\Models\ProjectInvoice::where('project_enquiry_id',$enquiry->id)->whereNot('status','void')->sum('total_amount');
        if ($basis <= 0) return response()->json(['message'=>'This project has no agreed price yet, so it cannot be invoiced. Set the agreed price first.'],422);
        if ($existing + $total > $basis) return response()->json(['message'=>'That would bill the client more than the agreed price. Invoices so far come to '.number_format($existing,2).' and the agreed price is '.number_format($basis,2).', so this invoice cannot exceed '.number_format($basis-$existing,2).'.'],422);
        $invoice = DB::transaction(function () use ($enquiry,$data,$total) {
            $invoice = \App\Modules\Finance\Models\ProjectInvoice::create([...$data,'tax_amount'=>$data['tax_amount']??0,'total_amount'=>$total,'invoice_number'=>'TMP-'.\Illuminate\Support\Str::uuid(),'project_enquiry_id'=>$enquiry->id,'created_by'=>Auth::id()]);
            $invoice->update(['invoice_number'=>'INV-'.now()->format('Ym').'-'.str_pad((string)$invoice->id,6,'0',STR_PAD_LEFT)]); return $invoice;
        });
        return response()->json(['message'=>'Draft invoice created.','data'=>$invoice],201);
    }

    public function issueProjectInvoice(ProjectEnquiry $enquiry, \App\Modules\Finance\Models\ProjectInvoice $invoice): JsonResponse
    {
        abort_unless((int)$invoice->project_enquiry_id===(int)$enquiry->id,404); abort_unless($invoice->status==='draft',422,'Only draft invoices can be issued.');
        $invoice->update(['status'=>'issued','issued_by'=>Auth::id(),'issued_at'=>now()]);
        return response()->json(['message'=>'Invoice issued.','data'=>$invoice]);
    }

    public function allocatePaymentToInvoice(Request $request, ProjectEnquiry $enquiry, \App\Modules\Finance\Models\ProjectInvoice $invoice): JsonResponse
    {
        abort_unless((int)$invoice->project_enquiry_id===(int)$enquiry->id,404);
        $data=$request->validate(['payment_id'=>'required|integer|exists:enquiry_payments,id','amount'=>'required|numeric|gt:0']);
        $payment=$enquiry->payments()->whereKey($data['payment_id'])->where('status','verified')->whereNull('reversed_at')->firstOrFail();
        try {
            DB::transaction(function () use ($invoice,$payment,$data) {
                $invoice->newQuery()->lockForUpdate()->findOrFail($invoice->id); $payment->newQuery()->lockForUpdate()->findOrFail($payment->id);
                $paymentUsed=(float)DB::table('project_invoice_allocations')->where('enquiry_payment_id',$payment->id)->sum('amount');
                $invoicePaid=(float)DB::table('project_invoice_allocations')->where('project_invoice_id',$invoice->id)->sum('amount');
                if ((float)$data['amount']>(float)$payment->amount-$paymentUsed || (float)$data['amount']>(float)$invoice->total_amount-$invoicePaid) throw new \DomainException('Allocation exceeds the available payment or invoice balance.');
                DB::table('project_invoice_allocations')->insert(['project_invoice_id'=>$invoice->id,'enquiry_payment_id'=>$payment->id,'amount'=>$data['amount'],'allocated_by'=>Auth::id(),'created_at'=>now(),'updated_at'=>now()]);
                if ($invoicePaid+(float)$data['amount'] >= (float)$invoice->total_amount) $invoice->update(['status'=>'paid']);
            });
        } catch (\DomainException $e) {
            return response()->json(['message'=>$e->getMessage()],422);
        }
        return response()->json(['message'=>'Payment allocated to invoice.']);
    }

    /**
     * Return the canonical workflow snapshot for the project cockpit UI.
     */
    public function workflowState(ProjectEnquiry $enquiry, ProjectWorkflowStateService $workflowStateService): JsonResponse
    {
        return $this->safe(function () use ($enquiry, $workflowStateService) {
            return response()->json([
                'success' => true,
                'data' => $workflowStateService->forEnquiry($enquiry),
            ]);
        }, 'Project workflow state');
    }

    /**
     * Manually release a project for production
     */
    public function releaseProject(Request $request, ProjectEnquiry $enquiry, ReleaseFinanceGateAction $action): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $progress = $this->financeService->getPaymentProgress($enquiry);
        if (!$progress['is_threshold_met']) {
            abort_unless(
                $request->user()?->can(\App\Constants\Permissions::FINANCE_RECEIVABLES_OVERRIDE),
                403,
                'Early release requires the receivables override permission.'
            );
        }

        try {
            $action->execute($enquiry, Auth::id(), $validated['notes'] ?? null);

            return response()->json([
                'message' => 'Project released for production successfully',
                'data' => $enquiry->fresh(['client', 'projectOfficer'])
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Get governance audit logs for an enquiry
     */
    public function getGovernanceTrace(ProjectEnquiry $enquiry): JsonResponse
    {
        abort_unless($this->projectFinancialAccess->canReadAccount(request()->user(), $enquiry), 403);

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
