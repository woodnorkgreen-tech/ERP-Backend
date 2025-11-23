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

/**
 * @OA\Schema(
 *     schema="ProjectEnquiry",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="enquiry_number", type="string", example="WNG-11-2025-001"),
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

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Generate a unique enquiry number
     */
    private function generateEnquiryNumber(): string
    {
        $month = date('m');
        $year = date('Y');
        $prefix = EnquiryConstants::ENQUIRY_PREFIX . '-' . $month . '-' . $year . '-';

        // Find the highest existing number for this month/year
        $lastEnquiry = ProjectEnquiry::where('enquiry_number', 'like', $prefix . '%')
            ->orderByRaw('CAST(SUBSTRING(enquiry_number, LENGTH(?) + 1) AS UNSIGNED) DESC', [$prefix])
            ->first();

        $nextNumber = 1;
        if ($lastEnquiry) {
            // Extract the number part after the prefix
            $numberPart = substr($lastEnquiry->enquiry_number, strlen($prefix));
            $nextNumber = intval($numberPart) + 1;
        }

        return $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
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

        $query = ProjectEnquiry::with('client', 'department', 'projectOfficer', 'enquiryTasks');

        // Apply filters
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhereHas('client', function ($clientQuery) use ($search) {
                      $clientQuery->where('full_name', 'like', "%{$search}%");
                  })
                  ->orWhere('contact_person', 'like', "%{$search}%");
            });
        }

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        if ($request->has('client_id') && $request->client_id) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->has('department_id') && $request->department_id) {
            $query->where('department_id', $request->department_id);
        }

        $enquiries = $query->orderBy('created_at', 'desc')->paginate(EnquiryConstants::PAGINATION_PER_PAGE);

        return response()->json([
            'data' => $enquiries,
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
    public function store(Request $request): JsonResponse
    {

        // Handle field name alias for enquiry_title
        if ($request->has('enquiry_title') && !$request->has('title')) {
            $request->merge(['title' => $request->enquiry_title]);
        }

        $validator = Validator::make($request->all(), [
            'date_received' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:date_received',
            'client_id' => 'required|integer|exists:clients,id',
            'title' => 'required|string|max:255',
            'enquiry_title' => 'nullable|string|max:255', // Allow enquiry_title as alias
            'description' => 'nullable|string',
            'project_scope' => 'nullable|string',
            'priority' => 'nullable|string|in:' . implode(',', EnquiryConstants::getAllPriorities()),
            'contact_person' => 'required|string|max:255',
            'project_officer_id' => 'nullable|integer|exists:users,id',
            'status' => 'required|string|in:' . implode(',', EnquiryConstants::getAllStatuses()),
            'department_id' => 'nullable|integer|exists:departments,id',
            'assigned_department' => 'nullable|string|max:255',
            'project_deliverables' => 'nullable|string',
            'assigned_po' => 'nullable|integer|exists:users,id',
            'follow_up_notes' => 'nullable|string',
            'venue' => 'nullable|string|max:255',
            'site_survey_skipped' => 'nullable|boolean',
            'site_survey_skip_reason' => 'nullable|string|required_if:site_survey_skipped,true',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Validate project officer role if provided
        if ($request->project_officer_id) {
            $user = \App\Models\User::find($request->project_officer_id);
            if (!$user || !$user->hasRole(['Project Officer', 'Project Manager'])) {
                return response()->json([
                    'message' => 'Invalid project officer assignment',
                    'errors' => ['project_officer_id' => ['Selected user is not a valid project officer']]
                ], 422);
            }
        }

        // Generate enquiry number
        $enquiryNumber = $this->generateEnquiryNumber();

        $enquiry = ProjectEnquiry::create([
            'date_received' => $request->date_received,
            'expected_delivery_date' => $request->expected_delivery_date,
            'client_id' => $request->client_id,
            'title' => $request->title,
            'description' => $request->description,
            'project_scope' => $request->project_scope,
            'priority' => $request->priority ?? EnquiryConstants::PRIORITY_MEDIUM,
            'contact_person' => $request->contact_person,
            'project_officer_id' => $request->project_officer_id,
            'status' => $request->status,
            'department_id' => $request->department_id,
            'assigned_department' => $request->assigned_department,
            'project_deliverables' => $request->project_deliverables,
            'assigned_po' => $request->assigned_po,
            'follow_up_notes' => $request->follow_up_notes,
            'enquiry_number' => $enquiryNumber,
            'venue' => $request->venue,
            'site_survey_skipped' => $request->site_survey_skipped ?? false,
            'site_survey_skip_reason' => $request->site_survey_skip_reason,
            'created_by' => Auth::id(),
        ]);

        // Create workflow tasks for the enquiry
        $workflowService = new EnquiryWorkflowService($this->notificationService);
        $workflowService->createWorkflowTasksForEnquiry($enquiry);

        return response()->json([
            'message' => 'Enquiry created successfully',
            'data' => $enquiry->load('client', 'department', 'projectOfficer', 'enquiryTasks'),
        ], 201);
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
                $query->with(['assignedUser', 'taskData']);
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
            'expected_delivery_date' => 'nullable|date|after_or_equal:date_received',
            'client_id' => 'sometimes|required|integer|exists:clients,id',
            'title' => 'sometimes|required|string|max:255',
            'enquiry_title' => 'nullable|string|max:255', // Allow enquiry_title as alias
            'description' => 'sometimes|nullable|string',
            'project_scope' => 'nullable|string',
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
        ]));

        return response()->json([
            'message' => 'Enquiry updated successfully',
            'data' => $enquiry->load('client', 'department', 'projectOfficer')
        ]);
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
    public function approveQuote(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        try {
            // Call the model's approveQuote method which handles job number generation and project creation
            $result = $enquiry->approveQuote(Auth::id());

            if ($result) {
                return response()->json([
                    'message' => 'Quote approved successfully. Job number generated and project created.',
                    'data' => $enquiry->fresh(['client', 'department'])
                ]);
            } else {
                return response()->json([
                    'message' => 'Failed to approve quote'
                ], 500);
            }
        } catch (\Exception $e) {
            \Log::error('Error approving quote', [
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


}
