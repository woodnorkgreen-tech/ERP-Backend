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

    public function __construct(EnquiryWorkflowService $workflowService, NotificationService $notificationService)
    {
        $this->workflowService = $workflowService;
        $this->notificationService = $notificationService;
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
        \Log::info("[DEBUG] getAllEnquiryTasks called, user: " . Auth::id());

        try {
            $query = EnquiryTask::with('enquiry', 'creator', 'assignedTo', 'assignedBy', 'assignmentHistory.assignedTo', 'assignmentHistory.assignedBy', 'assignedUsers');

            $user = Auth::user();
            // Check if user has privileged role
            if (!$user->hasRole(['Super Admin', 'HR', 'Project Manager', 'Project Officer'])) {
                \Log::info('[TASK FILTER] Non-privileged user detected', [
                    'user_id' => $user->id,
                    'user_roles' => $user->roles->pluck('name'),
                    'filtering_by_assigned_users' => $user->id
                ]);
                // Use the pivot table relationship to filter tasks
                $query->assignedToUser($user->id);
            } else {
                \Log::info('[TASK FILTER] Privileged user - no filtering', [
                    'user_id' => $user->id,
                    'user_roles' => $user->roles->pluck('name')
                ]);
            }

            // Apply filters if provided
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            if ($request->has('priority') && $request->priority) {
                $query->where('priority', $request->priority);
            }

            if ($request->has('assigned_user_id') && $request->assigned_user_id) {
                $query->where('assigned_to', $request->assigned_user_id);
            }

            if ($request->has('enquiry_id') && $request->enquiry_id) {
                $query->where('project_enquiry_id', $request->enquiry_id);
            }

            // Search functionality
            if ($request->has('search') && $request->search) {
                $searchTerm = $request->search;
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('title', 'like', "%{$searchTerm}%")
                      ->orWhereHas('enquiry', function ($enquiryQuery) use ($searchTerm) {
                          $enquiryQuery->where('title', 'like', "%{$searchTerm}%");
                      });
                });
            }

            $tasks = $query->orderBy('id')->get(); // Order by ID for consistent ordering

            // Enrich material tasks with approval status
            $tasks->each(function ($task) {
                if ($task->type === 'materials') {
                    $materialsData = \App\Models\TaskMaterialsData::where('enquiry_task_id', $task->id)->first();
                    
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
                        
                        $task->material_approval = [
                            'needs_approval' => !($approvalStatus['all_approved'] ?? false),
                            'approved_count' => $totalApprovals,
                            'total_count' => 3,
                            'all_approved' => $approvalStatus['all_approved'] ?? false,
                            'departments' => [
                                'design' => $approvalStatus['design']['approved'] ?? false,
                                'production' => $approvalStatus['production']['approved'] ?? false,
                                'finance' => $approvalStatus['finance']['approved'] ?? false,
                            ]
                        ];
                    }
                }
            });

            \Log::info("[DEBUG] getAllEnquiryTasks retrieved " . $tasks->count() . " tasks");

            return response()->json([
                'data' => $tasks,
                'message' => 'All enquiry tasks retrieved successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error("[DEBUG] getAllEnquiryTasks failed: " . $e->getMessage());
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
        \Log::info("[DEBUG] getEnquiryTasks called for enquiryId: {$enquiryId}, user: " . Auth::id());

        try {
            $query = EnquiryTask::where('project_enquiry_id', $enquiryId)
                ->with('enquiry', 'creator', 'assignedTo', 'assignedBy', 'assignmentHistory.assignedTo', 'assignmentHistory.assignedBy', 'assignedUsers');

            $user = Auth::user();
            // Check if user has privileged role
            if (!$user->hasRole(['Super Admin', 'HR', 'Project Manager', 'Project Officer'])) {
                $query->assignedToUser($user->id);
            }

            $tasks = $query->orderBy('id')->get(); // Order by ID for consistent ordering

            // Enrich material tasks with approval status
            $tasks->each(function ($task) {
                if ($task->type === 'materials') {
                    $materialsData = \App\Models\TaskMaterialsData::where('enquiry_task_id', $task->id)->first();
                    
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
                        
                        $task->material_approval = [
                            'needs_approval' => !($approvalStatus['all_approved'] ?? false),
                            'approved_count' => $totalApprovals,
                            'total_count' => 3,
                            'all_approved' => $approvalStatus['all_approved'] ?? false,
                            'departments' => [
                                'design' => $approvalStatus['design']['approved'] ?? false,
                                'production' => $approvalStatus['production']['approved'] ?? false,
                                'finance' => $approvalStatus['finance']['approved'] ?? false,
                            ]
                        ];
                    }
                }
            });

            \Log::info("[DEBUG] getEnquiryTasks retrieved " . $tasks->count() . " tasks for enquiry {$enquiryId}");
            foreach ($tasks as $task) {
                \Log::info("[DEBUG] Task {$task->id}: title='{$task->title}', status='{$task->status}', assigned_by=" . ($task->assigned_by ?? 'null') . ", history_count=" . ($task->assignmentHistory ? $task->assignmentHistory->count() : 0));
            }

            return response()->json([
                'data' => $tasks,
                'message' => 'Enquiry tasks retrieved successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error("[DEBUG] getEnquiryTasks failed for enquiry {$enquiryId}: " . $e->getMessage());
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
        // Permissions temporarily removed - will be implemented soon

        try {
            $query = EnquiryTask::with('enquiry', 'department', 'assignedUser', 'creator');

            // Filter by enquiry if provided
            if ($request->has('enquiry_id')) {
                $query->where('project_enquiry_id', $request->enquiry_id);
            }

            // Filter by department if provided
            if ($request->has('department_id')) {
                $query->where('department_id', $request->department_id);
            }

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by assigned user if provided
            if ($request->has('assigned_user_id')) {
                $query->where('assigned_user_id', $request->assigned_user_id);
            }

            // Filter tasks by user's department
            $user = Auth::user();
            $query->where('department_id', $user->department_id);

            // Check if user has privileged role
            if (!$user->hasRole(['Super Admin', 'HR', 'Project Manager', 'Project Officer'])) {
                $query->assignedToUser($user->id);
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
        // Permissions temporarily removed - will be implemented soon

        \Log::info("[DEBUG] updateTaskStatus called for task {$taskId} with status: {$request->status}");

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:pending,in_progress,completed,cancelled',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            \Log::error("[DEBUG] updateTaskStatus validation failed for task {$taskId}: " . json_encode($validator->errors()));
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $task = EnquiryTask::findOrFail($taskId);
            \Log::info("[DEBUG] updateTaskStatus found task {$taskId}, current status: {$task->status}, title: {$task->title}, type: {$task->type}");

            $user = Auth::user();

            \Log::info("[DEBUG] updateTaskStatus calling workflowService->updateTaskStatus for task {$taskId} with status {$request->status}");
            $updatedTask = $this->workflowService->updateTaskStatus($taskId, $request->status, $user->id);
            \Log::info("[DEBUG] updateTaskStatus workflow service returned task with status: {$updatedTask->status}");

            // Update notes if provided
            if ($request->has('notes')) {
                $task->notes = $request->notes;
                $task->save();
            }

            \Log::info("[DEBUG] updateTaskStatus completed successfully for task {$taskId}, final status: {$updatedTask->status}");
            return response()->json([
                'data' => $updatedTask->load('enquiry', 'department', 'assignedUser'),
                'message' => 'Task status updated successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error("[DEBUG] updateTaskStatus failed for task {$taskId}: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update task status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign task to user
     */
    public function assignTask(Request $request, int $taskId): JsonResponse
    {
        // Permissions temporarily removed - will be implemented soon

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

            // Check if task belongs to user's department
            $user = Auth::user();
            if ($task->department_id !== $user->department_id) {
                return response()->json([
                    'message' => 'Unauthorized to assign tasks in this department'
                ], 403);
            }

            $task->update([
                'assigned_user_id' => $request->assigned_user_id,
                'assigned_at' => now(),
                'assigned_by' => $user->id,
            ]);

            return response()->json([
                'data' => $task->load('enquiry', 'department', 'assignedUser'),
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
        // Permissions temporarily removed - will be implemented soon

        try {
            $task = EnquiryTask::with('enquiry', 'department', 'assignedUser', 'creator')
                ->findOrFail($taskId);

            // Check if task belongs to user's department
            $user = Auth::user();
            if ($task->department_id !== $user->department_id) {
                return response()->json([
                    'message' => 'Unauthorized to view tasks in this department'
                ], 403);
            }

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
        // Permissions temporarily removed - will be implemented soon

        $validator = Validator::make($request->all(), [
            'task_description' => 'nullable|string',
            'priority' => 'nullable|string|in:low,medium,high,urgent',
            'estimated_hours' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date|after:today',
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

            // Department check temporarily removed - will be implemented soon
            $user = Auth::user();

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
                'message' => 'Failed to update task',
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

        // Permissions temporarily removed - will be implemented soon
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'assigned_user_id' => 'required|integer|exists:users,id',
            'priority' => 'nullable|string|in:low,medium,high,urgent',
            'due_date' => 'nullable|date|after:yesterday', // Allow today
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $assignedUser = \App\Models\User::findOrFail($request->assigned_user_id);
            \Log::info("[DEBUG] assignEnquiryTask found assigned user: {$assignedUser->id} ({$assignedUser->name}), department: " . ($assignedUser->department_id ?? 'null'));

            // Additional validation: assigned user must have a department
            if (!$assignedUser->department_id) {
                \Log::warning("[DEBUG] assignEnquiryTask failed: assigned user {$assignedUser->id} has no department");
                return response()->json([
                    'message' => 'Cannot assign task to user without department'
                ], 422);
            }

            $assignmentData = array_filter([
                'priority' => $request->priority,
                'due_date' => $request->due_date ? \Carbon\Carbon::parse($request->due_date) : null,
                'notes' => $request->notes,
            ]);

            \Log::info("[DEBUG] assignEnquiryTask calling workflowService->assignEnquiryTask with data: " . json_encode($assignmentData));

            $task = $this->workflowService->assignEnquiryTask($taskId, $assignedUser->id, $user->id, $assignmentData);

            \Log::info("[DEBUG] assignEnquiryTask workflow service returned task: {$task->id}, status: {$task->status}, assigned_by: " . ($task->assigned_by ?? 'null'));

            // Send notification
            $this->notificationService->sendTaskAssignmentNotification($task, $assignedUser, $user);

            $loadedTask = $task->load('department', 'assignedBy', 'assignedTo', 'assignmentHistory');
            \Log::info("[DEBUG] assignEnquiryTask loaded task with relationships, history count: " . ($loadedTask->assignmentHistory ? $loadedTask->assignmentHistory->count() : 0));

            return response()->json([
                'data' => $loadedTask,
                'message' => 'Task assigned successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error("[DEBUG] assignEnquiryTask failed: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            return response()->json([
                'message' => 'Failed to assign task',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get task assignment history
     */
    public function getTaskAssignmentHistory(int $taskId): JsonResponse
    {
        // Permissions temporarily removed - will be implemented soon

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
        // Permissions temporarily removed - will be implemented soon
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

            // Additional validation: new assigned user must have a department
            if (!$newAssignedUser->department_id) {
                return response()->json([
                    'message' => 'Cannot reassign task to user without department'
                ], 422);
            }

            $task = $this->workflowService->reassignEnquiryTask(
                $taskId,
                $newAssignedUser->id,
                $user->id,
                $request->reason
            );

            // Send notification to new assignee
            $this->notificationService->sendTaskAssignmentNotification($task, $newAssignedUser, $user);

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
        // Permissions temporarily removed - will be implemented soon
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'priority' => 'nullable|string|in:low,medium,high,urgent',
            'due_date' => 'nullable|date|after:yesterday', // Allow today
            'notes' => 'nullable|string|max:1000',
            'status' => 'nullable|string|in:pending,in_progress,completed',
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

            // Use workflow service for status updates to trigger automatic progression/reversion
            if ($request->has('status') && $request->status !== $oldStatus) {
                \Log::info("[DEBUG] updateEnquiryTask updating status from {$oldStatus} to {$request->status} for task {$taskId}");
                $task = $this->workflowService->updateTaskStatus($taskId, $request->status, $user->id);

                // Send notification if task was marked as completed
                if ($oldStatus !== 'completed' && $request->status === 'completed') {
                    $this->notificationService->sendTaskCompletedNotification($task, $user);
                }
            }

            \Log::info("[DEBUG] updateEnquiryTask completed successfully for task {$taskId}, final status: {$task->status}");

            return response()->json([
                'data' => $task->load('assignedBy', 'assignmentHistory'),
                'message' => 'Task updated successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error("[DEBUG] updateEnquiryTask failed for task {$taskId}: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update task',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
