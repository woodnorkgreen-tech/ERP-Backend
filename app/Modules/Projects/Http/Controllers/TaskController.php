<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Modules\Projects\Services\EnquiryWorkflowService;
use App\Modules\Projects\Services\NotificationService;
use App\Models\TaskAssignmentHistory;
use App\Constants\Permissions;
use App\Modules\UniversalTask\Services\TaskService as UniversalTaskService;
use App\Modules\UniversalTask\Models\Task as UniversalTask;
use App\Http\Controllers\MaterialsController;
use App\Models\DesignAsset;

/**
 * @OA\Schema(
 *     schema="EnquiryTask",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="title", type="string", example="Site Survey Task"),
 *     @OA\Property(property="type", type="string", example="site-survey"),
 *     @OA\Property(property="status", type="string", enum={"pending","in_progress","completed","cancelled"}, example="in_progress"),
 *     @OA\Property(property="priority", type="string", enum={"low","medium","high","urgent"}, example="high"),
 *     @OA\Property(property="project_enquiry_id", type="integer"),
 *     @OA\Property(property="department_id", type="integer"),
 *     @OA\Property(property="assigned_user_id", type="integer", nullable=true),
 *     @OA\Property(property="assigned_by", type="integer", nullable=true),
 *     @OA\Property(property="due_date", type="string", format="date", nullable=true),
 *     @OA\Property(property="estimated_hours", type="number", format="float", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class TaskController extends Controller
{
    protected EnquiryWorkflowService $workflowService;
    protected NotificationService $notificationService;
    protected UniversalTaskService $universalTaskService;

    public function __construct(
        EnquiryWorkflowService $workflowService,
        NotificationService $notificationService,
        UniversalTaskService $universalTaskService
    ) {
        $this->workflowService = $workflowService;
        $this->notificationService = $notificationService;
        $this->universalTaskService = $universalTaskService;
    }

    /**
     * @OA\Get(
     *     path="/api/projects/tasks",
     *     summary="Get all enquiry tasks with filtering and search",
     *     tags={"Tasks"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by task status",
     *         @OA\Schema(type="string", enum={"pending","in_progress","completed","cancelled"})
     *     ),
     *     @OA\Parameter(
     *         name="priority",
     *         in="query",
     *         description="Filter by task priority",
     *         @OA\Schema(type="string", enum={"low","medium","high","urgent"})
     *     ),
     *     @OA\Parameter(
     *         name="assigned_user_id",
     *         in="query",
     *         description="Filter by assigned user ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="enquiry_id",
     *         in="query",
     *         description="Filter by enquiry ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by task title or enquiry title",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Tasks retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/EnquiryTask")),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function getAllEnquiryTasks(Request $request): JsonResponse
    {
        try {
            $query = EnquiryTask::authorized()->withTaskData()->with([
                'enquiry',
                'department',
                'assignedUser',
                'assignmentHistory'
            ]);

            $query = app(\Illuminate\Pipeline\Pipeline::class)
                ->send($query)
                ->through([
                    \App\Modules\Projects\Filters\Task\SearchFilter::class,
                    \App\Modules\Projects\Filters\Task\StatusFilter::class,
                    \App\Modules\Projects\Filters\Task\DepartmentFilter::class,
                    \App\Modules\Projects\Filters\Task\EnquiryFilter::class,
                    \App\Modules\Projects\Filters\Task\PriorityFilter::class,
                    \App\Modules\Projects\Filters\Task\AssignedUserFilter::class,
                    \App\Modules\Projects\Filters\Task\VisibilityFilter::class,
                ])
                ->thenReturn();

            $tasks = $query->orderBy('task_order', 'asc')->paginate($request->get('per_page', 15));

            return response()->json([
                'data' => \App\Modules\Projects\Resources\EnquiryTaskResource::collection($tasks)->response()->getData(true),
                'message' => 'All enquiry tasks retrieved successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error("getAllEnquiryTasks failed: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to retrieve all enquiry tasks',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/projects/enquiries/{enquiryId}/tasks",
     *     summary="Get tasks for a specific enquiry",
     *     tags={"Tasks"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="enquiryId",
     *         in="path",
     *         required=true,
     *         description="Enquiry ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Enquiry tasks retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/EnquiryTask")),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=404, description="Enquiry not found")
     * )
     */
    public function getEnquiryTasks(int $enquiryId): JsonResponse
    {

        try {
            $query = EnquiryTask::authorized()
                ->where('project_enquiry_id', $enquiryId)
                ->withTaskData()
                ->with('enquiry', 'creator', 'assignedTo', 'assignedBy', 'assignmentHistory.assignedTo', 'assignmentHistory.assignedBy');

            $user = Auth::user();
            
            // Access Control: All users can see tasks in the project (Transparency)
            // Interaction is gated via is_authorized flag on the task model

            $tasks = $query->orderBy('task_order', 'asc')->get(); // Order by sequence for clear workflow

            // Enrich material tasks with approval status
            $tasks->each(function ($task) {
                if ($task->type === 'materials') {
                    $materialsData = $task->materialsData;
                    
                    if ($materialsData) {
                        $approvalStatus = $materialsData->project_info['approval_status'] ?? [];
                        
                        // Count approvals
                        $totalApprovals = 0;
                        $departments = ['design', 'production', 'finance'];
                        foreach ($departments as $dept) {
                            if (isset($approvalStatus[$dept]['approved']) && $approvalStatus[$dept]['approved']) {
                                $totalApprovals++;
                            }
                        }
                        
                        // Get counts
                        $elementCount = $materialsData->elements()->count();
                        $materialCount = \App\Models\ElementMaterial::whereIn(
                            'project_element_id', 
                            $materialsData->elements()->pluck('id')
                        )->count();

                        // Check Design Gate
                        $isGated = false;
                        $gateMessage = '';
                        
                        $designTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
                            ->where('type', 'design')
                            ->first();

                        if ($designTask) {
                            $hasApprovedAssets = DesignAsset::where('enquiry_task_id', $designTask->id)
                                ->where('status', 'approved')
                                ->exists();
                            
                            if (!$hasApprovedAssets) {
                                $isGated = true;
                                $gateMessage = 'Materials approval is locked until the Design Task has approved assets.';
                            }
                        }
                        
                        $task->material_approval = [
                            'needs_approval' => !($approvalStatus['all_approved'] ?? false),
                            'approved_count' => $totalApprovals,
                            'total_count' => 3,
                            'all_approved' => $approvalStatus['all_approved'] ?? false,
                            'element_count' => $elementCount,
                            'material_count' => $materialCount,
                            'is_gated' => $isGated,
                            'gate_message' => $gateMessage,
                            'departments' => [
                                'design' => $approvalStatus['design']['approved'] ?? false,
                                'production' => $approvalStatus['production']['approved'] ?? false,
                                'finance' => $approvalStatus['finance']['approved'] ?? false,
                            ]
                        ];
                    }
                }
            });


            return response()->json([
                'data' => $tasks,
                'message' => 'Enquiry tasks retrieved successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error("getEnquiryTasks failed for enquiry {$enquiryId}: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to retrieve enquiry tasks',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get departmental tasks
     */
    public function getDepartmentalTasks(Request $request): JsonResponse
    {
        try {
            // Visibility: all authenticated users can see tasks for project transparency.
            // Interaction rights are enforced per-task via the is_authorized flag.
            $query = EnquiryTask::authorized()->withTaskData()->with('enquiry', 'department', 'assignedUser', 'creator');

            if ($request->filled('enquiry_id')) {
                $query->where('project_enquiry_id', $request->enquiry_id);
            }
            if ($request->filled('department_id')) {
                // Department board: include tasks this department owns AND those
                // its task types make it a collaborator on.
                $query->forDepartmentPool($request->department_id);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('assigned_user_id')) {
                $query->where('assigned_user_id', $request->assigned_user_id);
            }

            $tasks = $query->orderBy('created_at', 'desc')->paginate(15);

            return response()->json([
                'data' => $tasks,
                'message' => 'Departmental tasks retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve departmental tasks',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/projects/tasks/{taskId}/status",
     *     summary="Update task status",
     *     tags={"Tasks"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="taskId",
     *         in="path",
     *         required=true,
     *         description="Task ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"status"},
     *             @OA\Property(property="status", type="string", enum={"pending","in_progress","completed","cancelled"}, example="completed"),
     *             @OA\Property(property="notes", type="string", example="Task completed successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task status updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/EnquiryTask"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Task not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function updateTaskStatus(Request $request, int $taskId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:pending,in_progress,completed,cancelled,skipped',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $action = app(\App\Modules\Projects\Actions\UpdateTaskStatusAction::class);
            $updatedTask = $action->execute($taskId, $request->status, $request->notes);

            return response()->json([
                'data' => $updatedTask->load('enquiry', 'department', 'assignedUser'),
                'message' => 'Task status updated successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error("updateTaskStatus failed for task {$taskId}: " . $e->getMessage());
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign task to user
     */
    public function assignTask(Request $request, int $taskId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'assigned_user_id' => 'required|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $task = EnquiryTask::findOrFail($taskId);

            // Security check: Must belong to department OR have the specialized role for this task type
            $user = Auth::user();
            $isAdmin = $user->hasRole(['Super Admin', 'Project Manager', 'Project Officer']);
            
            $canClaimByRole = false;
            if ($user->hasRole('Designer') && in_array($task->type, ['design', 'materials'])) {
                $canClaimByRole = true;
            } elseif ($user->hasRole(['Costing', 'Accounts']) && in_array($task->type, ['materials', 'budget', 'quote', 'quote_approval'])) {
                $canClaimByRole = true;
            } elseif ($user->hasRole(['Stores', 'Procurement']) && in_array($task->type, ['budget', 'procurement'])) {
                $canClaimByRole = true;
            } elseif ($user->hasRole('Production') && in_array($task->type, ['materials', 'teams', 'production', 'budget'])) {
                $canClaimByRole = true;
            }

            if (!$isAdmin && !$canClaimByRole && $task->department_id && $task->department_id !== $user->department_id) {
                return response()->json([
                    'message' => 'Unauthorized to assign tasks in this department'
                ], 403);
            }

            $task->update([
                'assigned_user_id' => $request->assigned_user_id,
                'assigned_to' => $request->assigned_user_id, // Backward compatibility
                'assigned_at' => now(),
                'assigned_by' => $user->id,
            ]);

            $task = $task->fresh(['enquiry', 'department', 'assignedUser', 'assignedTo']);

            return response()->json([
                'data' => $task,
                'message' => 'Task assigned successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to assign task',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/projects/tasks/{taskId}",
     *     summary="Get task details",
     *     tags={"Tasks"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="taskId",
     *         in="path",
     *         required=true,
     *         description="Task ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task details retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/EnquiryTask"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Task not found")
     * )
     */
    public function show(int $taskId): JsonResponse
    {
        try {
            $task = EnquiryTask::with([
                'enquiry.enquiryTasks.materialsData.elements',
                'enquiry.enquiryTasks.designAssets',
                'department',
                'assignedUser',
                'creator',
            ])->findOrFail($taskId);

            $task->is_locked_for_user = false;
            $task->locked_by_user = null;

            return response()->json([
                'data' => $task,
                'message' => 'Task details retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve task details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update task details
     */
    public function update(Request $request, int $taskId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'task_description' => 'nullable|string',
            'priority' => 'nullable|string|in:low,medium,high,urgent',
            'estimated_hours' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date|after:yesterday',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $task = EnquiryTask::findOrFail($taskId);
            $user = Auth::user();

            // Check if user is authorized to interact with this task (Pool check)
            if (!$task->isUserAuthorized($user)) {
                return response()->json([
                    'message' => 'Unauthorized: You can only interact with tasks in your pool.'
                ], 403);
            }

            $task->update($request->only([
                'task_description',
                'priority',
                'estimated_hours',
                'due_date',
                'notes',
            ]));

            return response()->json([
                'data' => $task->load('enquiry', 'department', 'assignedUser'),
                'message' => 'Task updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/projects/tasks/{taskId}/assign",
     *     summary="Assign task to user",
     *     tags={"Tasks"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="taskId",
     *         in="path",
     *         required=true,
     *         description="Task ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"assigned_user_id"},
     *             @OA\Property(property="assigned_user_id", type="integer", example=2),
     *             @OA\Property(property="priority", type="string", enum={"low","medium","high","urgent"}, example="high"),
     *             @OA\Property(property="due_date", type="string", format="date", example="2024-02-15"),
     *             @OA\Property(property="notes", type="string", example="Please complete by end of week")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task assigned successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/EnquiryTask"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Unauthorized - Only Project Managers can assign tasks"),
     *     @OA\Response(response=404, description="Task not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function assignEnquiryTask(Request $request, int $taskId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'assigned_user_id' => 'required|integer|exists:users,id',
            'priority' => 'nullable|string|in:low,medium,high,urgent',
            'due_date' => 'nullable|date|after:yesterday',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $assignmentData = array_filter($request->only(['priority', 'due_date', 'notes']));
            
            $action = app(\App\Modules\Projects\Actions\AssignTaskAction::class);
            $task = $action->execute($taskId, $request->assigned_user_id, $assignmentData);

            return response()->json([
                'data' => new \App\Modules\Projects\Resources\EnquiryTaskResource($task),
                'message' => 'Task assigned successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error("assignEnquiryTask failed for task {$taskId}: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to assign task',
            ], 500);
        }
    }

    /**
     * Get task assignment history
     */
    public function getTaskAssignmentHistory(int $taskId): JsonResponse
    {
        try {
            $history = TaskAssignmentHistory::where('enquiry_task_id', $taskId)
                ->with('assignedTo', 'assignedBy')
                ->orderBy('assigned_at', 'desc')
                ->get();

            return response()->json([
                'data' => $history,
                'message' => 'Task assignment history retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve task assignment history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/projects/tasks/{taskId}/reassign",
     *     summary="Reassign task to a different user",
     *     tags={"Tasks"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="taskId",
     *         in="path",
     *         required=true,
     *         description="Task ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"new_assigned_user_id"},
     *             @OA\Property(property="new_assigned_user_id", type="integer", example=3),
     *             @OA\Property(property="reason", type="string", example="Previous assignee is on leave")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task reassigned successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/EnquiryTask"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Unauthorized - Only Project Managers can reassign tasks"),
     *     @OA\Response(response=404, description="Task not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function reassignEnquiryTask(Request $request, int $taskId): JsonResponse
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'new_assigned_user_id' => 'required|integer|exists:users,id',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $newAssignedUser = \App\Models\User::findOrFail($request->new_assigned_user_id);

            $task = $this->workflowService->reassignEnquiryTask(
                $taskId,
                $newAssignedUser->id,
                $user->id,
                $request->reason
            );

            // Send notification to new assignee (reassignment)
        $this->notificationService->sendEnquiryTaskAssignment($task, $newAssignedUser, $user, true);

            return response()->json([
                'data' => $task->load('department', 'assignedBy', 'assignmentHistory'),
                'message' => 'Task reassigned successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to reassign task',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/projects/enquiry-tasks/{taskId}/release",
     *     summary="Release task back to pool (Handover)",
     *     tags={"Tasks"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Task released successfully"),
     *     @OA\Response(response=403, description="Unauthorized")
     * )
     */
    public function releaseEnquiryTask(Request $request, int $taskId): JsonResponse
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $task = EnquiryTask::findOrFail($taskId);
            
            // Check permissions: Must be assignee OR Admin/Manager
            $isAssignee = $task->assigned_to == $user->id;
            $isAdmin = $user->hasRole(['Super Admin', 'Project Manager']);

            if (!$isAssignee && !$isAdmin) {
                return response()->json(['message' => 'Unauthorized to release this task'], 403);
            }

            $task = $this->workflowService->releaseTask($taskId, $user->id, $request->reason);

            return response()->json([
                'data' => $task->load('department', 'assignedBy'),
                'message' => 'Task released to pool successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error("Failed to release task: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to release task',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/projects/tasks/{taskId}",
     *     summary="Update task details",
     *     tags={"Tasks"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="taskId",
     *         in="path",
     *         required=true,
     *         description="Task ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string", example="Updated Task Title"),
     *             @OA\Property(property="priority", type="string", enum={"low","medium","high","urgent"}, example="urgent"),
     *             @OA\Property(property="due_date", type="string", format="date", example="2024-02-20"),
     *             @OA\Property(property="notes", type="string", example="Updated task notes"),
     *             @OA\Property(property="status", type="string", enum={"pending","in_progress","completed"}, example="in_progress")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/EnquiryTask"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Task not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function updateEnquiryTask(Request $request, int $taskId): JsonResponse
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'priority' => 'nullable|string|in:low,medium,high,urgent',
            'due_date' => 'nullable|date|after:yesterday',
            'notes' => 'nullable|string|max:1000',
            'status' => 'nullable|string|in:pending,in_progress,completed,cancelled,skipped',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $task = EnquiryTask::findOrFail($taskId);
            $user = Auth::user();

            $oldStatus = $task->status;

            // Update non-status fields directly
            $task->update($request->only([
                'title',
                'priority',
                'due_date',
                'notes',
            ]));

            if ($request->has('status') && $request->status !== $oldStatus) {
                $task = $this->workflowService->updateTaskStatus($taskId, $request->status, $user->id);

                if ($oldStatus !== 'completed' && $request->status === 'completed') {
                    $this->notificationService->sendTaskCompletedNotification($task, $user);
                }
            }

            return response()->json([
                'data' => $task->load('assignedBy', 'assignmentHistory'),
                'message' => 'Task updated successfully'
            ]);
        } catch (\Throwable $e) {
            \Log::error("updateEnquiryTask failed for task {$taskId}: " . $e->getMessage());
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Universal Tasks associated with a project
     */
    public function getProjectUniversalTasks(int $projectId): JsonResponse
    {
        try {
            $user = Auth::user();

            // Get Universal Tasks associated with this project
            $universalTasks = UniversalTask::where('taskable_type', 'App\Models\Project')
                ->where('taskable_id', $projectId)
                ->with(['department', 'assignedUser', 'creator', 'subtasks'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'data' => $universalTasks,
                'message' => 'Project Universal Tasks retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve project Universal Tasks',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a Universal Task from project context
     */
    public function createProjectUniversalTask(Request $request, int $projectId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'task_type' => 'nullable|string|max:50',
            'priority' => 'nullable|string|in:low,medium,high,urgent',
            'department_id' => 'nullable|exists:departments,id',
            'assigned_user_id' => 'nullable|exists:users,id',
            'estimated_hours' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date|after:yesterday', // Allow today
            'tags' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = Auth::user();

            // Prepare task data with project context
            $taskData = array_merge($request->all(), [
                'taskable_type' => 'App\Models\Project',
                'taskable_id' => $projectId,
                'created_by' => $user->id,
            ]);

            $universalTask = $this->universalTaskService->createTask($taskData, $user->id);

            return response()->json([
                'data' => $universalTask->load(['department', 'assignedUser', 'creator']),
                'message' => 'Universal Task created successfully for project'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create Universal Task',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Universal Tasks for current user's department
     */
    public function getDepartmentUniversalTasks(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            $query = UniversalTask::with(['department', 'assignedUser', 'creator', 'taskable'])
                ->where('department_id', $user->department_id);

            // Apply filters
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('priority')) {
                $query->where('priority', $request->priority);
            }

            if ($request->has('assigned_user_id')) {
                $query->where('assigned_user_id', $request->assigned_user_id);
            }

            $universalTasks = $query->orderBy('created_at', 'desc')->paginate(15);

            return response()->json([
                'data' => $universalTasks,
                'message' => 'Department Universal Tasks retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve department Universal Tasks',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
