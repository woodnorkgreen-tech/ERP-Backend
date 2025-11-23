<?php

namespace App\Modules\Projects\Services;

use App\Models\ProjectEnquiry;
use App\Models\TaskAssignmentHistory;
use App\Models\User;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Support\Facades\Log;
use App\Constants\EnquiryConstants;

class EnquiryWorkflowService
{
    private NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Create workflow tasks for a newly created enquiry (unassigned)
     */
    public function createWorkflowTasksForEnquiry(ProjectEnquiry $enquiry): void
    {
        Log::info("Creating unassigned workflow tasks for enquiry ID: {$enquiry->id}");

        // Check if tasks already exist for this enquiry to prevent duplication
        $existingTasksCount = EnquiryTask::where('project_enquiry_id', $enquiry->id)->count();
        if ($existingTasksCount > 0) {
            Log::info("Tasks already exist for enquiry {$enquiry->id} ({$existingTasksCount} tasks found). Skipping task creation.");
            return;
        }

        try {
            $taskTemplates = config('enquiry_workflow.task_templates', []);

            foreach ($taskTemplates as $template) {
                EnquiryTask::create([
                    'project_enquiry_id' => $enquiry->id,
                    'title' => $template['title'],
                    'type' => $template['type'],
                    'status' => 'pending',
                    'priority' => EnquiryConstants::PRIORITY_MEDIUM,
                    'notes' => $template['notes'] ?? 'Complete this task',
                    'created_by' => $enquiry->created_by,
                ]);

                Log::info("Created unassigned {$template['type']} task for enquiry {$enquiry->id}");
            }

            Log::info("Successfully created unassigned workflow tasks for enquiry {$enquiry->id}");

        } catch (\Exception $e) {
            Log::error("Failed to create workflow tasks for enquiry {$enquiry->id}: " . $e->getMessage());
            throw $e;
        }
    }



    /**
     * Update task status and handle workflow progression
     */
    public function updateTaskStatus(int $taskId, string $status, ?int $userId = null): EnquiryTask
    {
        $task = EnquiryTask::findOrFail($taskId);

        $oldStatus = $task->status;
        $task->status = $status;

        $task->save();

        Log::info("Task {$taskId} status changed from {$oldStatus} to {$status}");

        // Handle enquiry status progression based on task completion
        if ($status === 'completed') {
            $this->handleEnquiryStatusProgression($task);
        } elseif ($oldStatus === 'completed' && $status !== 'completed') {
            // Handle status reversion when task is reopened
            $this->handleEnquiryStatusReversion($task);
        }

        return $task;
    }



    /**
     * Manually assign an enquiry task to a department and user(s)
     * Supports assigning multiple users to the same task
     */
    public function assignEnquiryTask(int $taskId, int $assignedUserId, int $assignedByUserId, array $assignmentData = []): EnquiryTask
    {
        $task = EnquiryTask::findOrFail($taskId);
        $assignedUser = User::findOrFail($assignedUserId);
        $assignedByUser = User::findOrFail($assignedByUserId);

        // Validate assignment rules
        $this->validateTaskAssignment($task, $assignedUser, $assignmentData);

        // Update task with assignment data (for backward compatibility and department tracking)
        $updateData = [
            'department_id' => $assignedUser->department_id,
            'assigned_by' => $assignedByUserId,
            'assigned_to' => $assignedUserId, // Keep for backward compatibility
            'assigned_at' => now(),
        ];

        if (isset($assignmentData['priority'])) {
            $updateData['priority'] = $assignmentData['priority'];
        }

        if (isset($assignmentData['due_date'])) {
            $updateData['due_date'] = $assignmentData['due_date'];
        }

        if (isset($assignmentData['notes'])) {
            $updateData['notes'] = $assignmentData['notes'];
        }

        $task->update($updateData);

        // Add user to the pivot table (supports multiple users)
        $task->assignedUsers()->syncWithoutDetaching([
            $assignedUserId => [
                'assigned_by' => $assignedByUserId,
                'assigned_at' => now(),
            ]
        ]);

        // Create assignment history
        TaskAssignmentHistory::create([
            'enquiry_task_id' => $task->id,
            'assigned_to' => $assignedUserId,
            'assigned_by' => $assignedByUserId,
            'assigned_at' => now(),
            'notes' => $assignmentData['notes'] ?? null,
        ]);

        Log::info("Task {$taskId} assigned to user {$assignedUserId} by user {$assignedByUserId}");

        return $task->fresh(); // Get fresh instance
    }

    /**
     * Validate task assignment rules
     */
    private function validateTaskAssignment(EnquiryTask $task, User $assignedUser, array $assignmentData = []): void
    {
        // Check if task is already assigned to a different department
        if ($task->department_id && $task->department_id !== $assignedUser->department_id) {
            // Allow reassignment but log it
            Log::warning("Reassigning task {$task->id} from department {$task->department_id} to {$assignedUser->department_id}");
        }

        // Check if user belongs to a department
        if (!$assignedUser->department_id) {
            throw new \Exception("Cannot assign task to user without department");
        }

        // Check for duplicate assignments in same department (optional - can be configured)
        $existingTasks = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
            ->where('department_id', $assignedUser->department_id)
            ->where('type', $task->type)
            ->where('id', '!=', $task->id)
            ->count();

        if ($existingTasks > 0) {
            Log::warning("Assigning duplicate task type '{$task->type}' to department {$assignedUser->department_id} for enquiry {$task->project_enquiry_id}");
        }

        // Validate due date if provided
        if (isset($assignmentData['due_date']) && $assignmentData['due_date']) {
            $dueDate = \Carbon\Carbon::parse($assignmentData['due_date']);
            if ($dueDate->isPast()) {
                throw new \Exception("Due date cannot be in the past");
            }
        }
    }

    /**
     * Reassign task to a different user
     */
    public function reassignEnquiryTask(int $taskId, int $newAssignedUserId, int $reassignedByUserId, string $reason = null): EnquiryTask
    {
        $task = EnquiryTask::findOrFail($taskId);
        $newAssignedUser = User::findOrFail($newAssignedUserId);
        $reassignedByUser = User::findOrFail($reassignedByUserId);

        // Validate reassignment
        if (!$task->assigned_by) {
            throw new \Exception("Cannot reassign unassigned task");
        }

        if ($task->assigned_to === $newAssignedUserId) {
            throw new \Exception("Cannot reassign to the same user");
        }

        // Create reassignment history entry
        TaskAssignmentHistory::create([
            'enquiry_task_id' => $task->id,
            'assigned_to' => $newAssignedUserId,
            'assigned_by' => $reassignedByUserId,
            'assigned_at' => now(),
            'notes' => "Reassigned from user {$task->assigned_to}. Reason: {$reason}",
        ]);

        // Update task assignment (backward compatibility)
        $task->update([
            'department_id' => $newAssignedUser->department_id,
            'assigned_by' => $reassignedByUserId,
            'assigned_to' => $newAssignedUserId,
            'assigned_at' => now(),
        ]);

        // Add new user to pivot table and keep existing assignees
        $task->assignedUsers()->syncWithoutDetaching([
            $newAssignedUserId => [
                'assigned_by' => $reassignedByUserId,
                'assigned_at' => now(),
            ]
        ]);

        Log::info("Task {$taskId} reassigned to include user {$newAssignedUserId} by user {$reassignedByUserId}");

        return $task;
    }

    /**
     * Create a manual enquiry task
     */
    public function createManualEnquiryTask(int $enquiryId, array $taskData, int $createdByUserId): EnquiryTask
    {
        $enquiry = ProjectEnquiry::findOrFail($enquiryId);

        $task = EnquiryTask::create([
            'project_enquiry_id' => $enquiryId,
            'title' => $taskData['title'],
            'type' => $taskData['type'] ?? 'manual',
            'status' => $taskData['status'] ?? 'pending',
            'priority' => $taskData['priority'] ?? 'medium',
            'due_date' => isset($taskData['due_date']) ? \Carbon\Carbon::parse($taskData['due_date']) : null,
            'notes' => $taskData['notes'] ?? null,
            'created_by' => $createdByUserId,
        ]);

        Log::info("Manual enquiry task created for enquiry {$enquiryId}: {$task->title}");

        return $task;
    }

    /**
     * Check and escalate overdue tasks
     */
    public function checkAndEscalateOverdueTasks(): void
    {
        $overdueTasks = EnquiryTask::where('due_date', '<', now())
            ->where('status', '!=', 'completed')
            ->whereNotNull('assigned_to')
            ->get();

        foreach ($overdueTasks as $task) {
            $this->escalateTaskPriority($task);
            $this->sendOverdueNotifications($task);
        }

        Log::info("Checked and escalated {$overdueTasks->count()} overdue tasks");
    }

    /**
     * Escalate task priority based on overdue duration
     */
    private function escalateTaskPriority(EnquiryTask $task): void
    {
        $daysOverdue = $task->due_date->diffInDays(now());
        $currentPriority = $task->priority;

        $escalationConfig = config('enquiry_workflow.escalation', []);
        $urgentThreshold = $escalationConfig['urgent_threshold_days'] ?? 7;
        $highThreshold = $escalationConfig['high_threshold_days'] ?? 3;

        $newPriority = match(true) {
            $daysOverdue >= $urgentThreshold => EnquiryConstants::PRIORITY_URGENT,
            $daysOverdue >= $highThreshold => EnquiryConstants::PRIORITY_HIGH,
            default => $currentPriority
        };

        if ($newPriority !== $currentPriority) {
            $task->update(['priority' => $newPriority]);
            Log::info("Escalated task {$task->id} priority from {$currentPriority} to {$newPriority} (overdue by {$daysOverdue} days)");
        }
    }

    /**
     * Send overdue notifications
     */
    private function sendOverdueNotifications(EnquiryTask $task): void
    {
        // Send notification to all assigned users (supports multiple)
        foreach ($task->assignedUsers as $assignedUser) {
            $this->notificationService->sendTaskOverdueNotification($task, $assignedUser);
        }

        // Fallback to assigned_to column if pivot table is empty (backward compatibility)
        if ($task->assignedUsers->isEmpty() && $task->assigned_to) {
            $assignedUser = User::find($task->assigned_to);
            if ($assignedUser) {
                $this->notificationService->sendTaskOverdueNotification($task, $assignedUser);
            }
        }

        // Send notification to project manager
        $projectManagerRole = \Spatie\Permission\Models\Role::where('name', 'Project Manager')->first();
        if ($projectManagerRole) {
            $projectManagers = $projectManagerRole->users;
            foreach ($projectManagers as $pm) {
                $this->notificationService->sendTaskOverdueNotification($task, $pm);
            }
        }
    }

    /**
     * Check and send due date reminders
     */
    public function checkAndSendDueDateReminders(): void
    {
        $reminderConfig = config('enquiry_workflow.reminders', []);
        $dueSoonDays = $reminderConfig['due_soon_days'] ?? 1;

        $tasksDueSoon = EnquiryTask::where('due_date', '>=', now())
            ->where('due_date', '<=', now()->addDays($dueSoonDays))
            ->where('status', '!=', 'completed')
            ->with('assignedUsers') // Eager load assigned users
            ->get();

        foreach ($tasksDueSoon as $task) {
            // Notify all assigned users
            foreach ($task->assignedUsers as $assignedUser) {
                $this->notificationService->sendTaskDueSoonNotification($task, $assignedUser);
            }

            // Fallback for backward compatibility
            if ($task->assignedUsers->isEmpty() && $task->assigned_to) {
                $assignedUser = User::find($task->assigned_to);
                if ($assignedUser) {
                    $this->notificationService->sendTaskDueSoonNotification($task, $assignedUser);
                }
            }
        }

        Log::info("Sent due date reminders for {$tasksDueSoon->count()} tasks");
    }

    /**
     * Get tasks requiring attention (overdue or due soon)
     */
    public function getTasksRequiringAttention(): \Illuminate\Database\Eloquent\Collection
    {
        $reminderConfig = config('enquiry_workflow.reminders', []);
        $requiringAttentionDays = $reminderConfig['requiring_attention_days'] ?? 2;

        return EnquiryTask::where(function ($query) use ($requiringAttentionDays) {
            $query->where('due_date', '<', now()) // Overdue
                  ->orWhere('due_date', '<=', now()->addDays($requiringAttentionDays)); // Due within configured days
        })
        ->where('status', '!=', 'completed')
        ->with('enquiry', 'department')
        ->orderBy('due_date')
        ->get();
    }

    /**
     * Handle enquiry status progression based on task completion
     */
    private function handleEnquiryStatusProgression(EnquiryTask $task): void
    {
        $enquiry = $task->enquiry;

        // Define task type to status mapping
        $statusMapping = [
            'site-survey' => EnquiryConstants::STATUS_SITE_SURVEY_COMPLETED,
            'design' => EnquiryConstants::STATUS_DESIGN_COMPLETED,
            'materials' => EnquiryConstants::STATUS_MATERIALS_SPECIFIED,
            'budget' => EnquiryConstants::STATUS_BUDGET_CREATED,
            'quote' => EnquiryConstants::STATUS_QUOTE_PREPARED,
            'quote_approval' => EnquiryConstants::STATUS_QUOTE_APPROVED,
        ];

        // Check if this task type maps to a status update
        if (!isset($statusMapping[$task->type])) {
            Log::info("Task type '{$task->type}' does not trigger status progression");
            return;
        }

        $newStatus = $statusMapping[$task->type];

        // Check if all prerequisite tasks are completed for certain statuses
        if (!$this->arePrerequisitesMet($task, $newStatus)) {
            Log::info("Prerequisites not met for status '{$newStatus}' - skipping progression");
            return;
        }

        // Update enquiry status
        $oldEnquiryStatus = $enquiry->status;
        $enquiry->status = $newStatus;
        $enquiry->save();

        Log::info("Enquiry {$enquiry->id} status progressed from '{$oldEnquiryStatus}' to '{$newStatus}' due to task '{$task->type}' completion");

        // Handle special cases
        $this->handleSpecialStatusCases($enquiry, $newStatus);
    }

    /**
     * Check if prerequisites are met for status progression
     */
    private function arePrerequisitesMet(EnquiryTask $completedTask, string $newStatus): bool
    {
        $enquiry = $completedTask->enquiry;

        // Define prerequisites for each status
        $prerequisites = [
            EnquiryConstants::STATUS_QUOTE_APPROVED => [
                'required_tasks' => ['site-survey', 'design', 'materials', 'budget', 'quote'],
                'all_required' => true, // All must be completed
            ],
            EnquiryConstants::STATUS_QUOTE_PREPARED => [
                'required_tasks' => ['site-survey', 'design', 'materials', 'budget'],
                'all_required' => true,
            ],
            EnquiryConstants::STATUS_BUDGET_CREATED => [
                'required_tasks' => ['site-survey', 'design', 'materials'],
                'all_required' => true,
            ],
            EnquiryConstants::STATUS_MATERIALS_SPECIFIED => [
                'required_tasks' => ['site-survey', 'design'],
                'all_required' => true,
            ],
            EnquiryConstants::STATUS_DESIGN_COMPLETED => [
                'required_tasks' => ['site-survey'],
                'all_required' => true,
            ],
        ];

        if (!isset($prerequisites[$newStatus])) {
            return true; // No prerequisites for this status
        }

        $requiredTasks = $prerequisites[$newStatus]['required_tasks'];
        $allRequired = $prerequisites[$newStatus]['all_required'];

        $completedTasks = EnquiryTask::where('project_enquiry_id', $enquiry->id)
            ->whereIn('type', $requiredTasks)
            ->where('status', 'completed')
            ->pluck('type')
            ->toArray();

        if ($allRequired) {
            // All required tasks must be completed
            return count(array_intersect($requiredTasks, $completedTasks)) === count($requiredTasks);
        } else {
            // At least one required task must be completed (for future use)
            return !empty(array_intersect($requiredTasks, $completedTasks));
        }
    }

    /**
     * Handle special cases for status progression
     */
    private function handleSpecialStatusCases(ProjectEnquiry $enquiry, string $newStatus): void
    {
        // No special cases needed when project conversion is removed
    }

    /**
     * Handle enquiry status reversion when a task is reopened
     */
    private function handleEnquiryStatusReversion(EnquiryTask $task): void
    {
        $enquiry = $task->enquiry;

        // Recalculate the appropriate status based on completed tasks
        $newStatus = $this->calculateEnquiryStatusFromTasks($enquiry);

        // Only update if the status has changed
        if ($newStatus !== $enquiry->status) {
            $oldEnquiryStatus = $enquiry->status;
            $enquiry->status = $newStatus;
            $enquiry->save();

            Log::info("Enquiry {$enquiry->id} status reverted from '{$oldEnquiryStatus}' to '{$newStatus}' due to task '{$task->type}' reopening");
        }
    }

    /**
     * Calculate the appropriate enquiry status based on completed tasks
     */
    private function calculateEnquiryStatusFromTasks(ProjectEnquiry $enquiry): string
    {
        // Get all completed tasks for this enquiry
        $completedTasks = EnquiryTask::where('project_enquiry_id', $enquiry->id)
            ->where('status', 'completed')
            ->pluck('type')
            ->toArray();

        // Define the progression order and required tasks for each status
        $statusProgression = [
            EnquiryConstants::STATUS_QUOTE_APPROVED => ['site-survey', 'design', 'materials', 'budget', 'quote', 'quote_approval'],
            EnquiryConstants::STATUS_QUOTE_PREPARED => ['site-survey', 'design', 'materials', 'budget', 'quote'],
            EnquiryConstants::STATUS_BUDGET_CREATED => ['site-survey', 'design', 'materials', 'budget'],
            EnquiryConstants::STATUS_MATERIALS_SPECIFIED => ['site-survey', 'design', 'materials'],
            EnquiryConstants::STATUS_DESIGN_COMPLETED => ['site-survey', 'design'],
            EnquiryConstants::STATUS_SITE_SURVEY_COMPLETED => ['site-survey'],
        ];

        // Find the highest status where all required tasks are completed
        foreach ($statusProgression as $status => $requiredTasks) {
            if (count(array_intersect($requiredTasks, $completedTasks)) === count($requiredTasks)) {
                return $status;
            }
        }

        // If no tasks are completed, return initial status
        return EnquiryConstants::STATUS_NEW;
    }
}
