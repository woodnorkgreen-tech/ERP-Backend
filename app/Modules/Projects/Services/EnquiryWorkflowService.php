<?php

namespace App\Modules\Projects\Services;

use App\Models\ProjectEnquiry;
use App\Models\TaskAssignmentHistory;
use App\Models\User;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Support\Facades\Log;
use App\Constants\EnquiryConstants;
use App\Services\Governance\ProjectGovernanceService;
use App\Modules\Projects\Actions\SyncEnquiryStatusAction;

class EnquiryWorkflowService
{
    private NotificationService $notificationService;
    private ProjectGovernanceService $governanceService;

    public function __construct(
        NotificationService $notificationService,
        ProjectGovernanceService $governanceService
    ) {
        $this->notificationService = $notificationService;
        $this->governanceService = $governanceService;
    }

    /**
     * Create workflow tasks for a newly created enquiry (unassigned)
     */

    public function initializeWorkflow(ProjectEnquiry $enquiry): void
    {
        Log::info("Syncing workflow tasks for enquiry ID: {$enquiry->id}");

        try {
            $taskTemplates = config('enquiry_workflow.task_templates', []);
            
            // Get selected tasks or use all if none specified (backward compatibility)
            $selectedTaskTypes = $enquiry->selected_workflow_tasks ?? 
                                array_column($taskTemplates, 'type');
            
            // Ensure it's an array
            if (!is_array($selectedTaskTypes)) {
                $selectedTaskTypes = json_decode($selectedTaskTypes, true) ?? [];
            }

            Log::info("Selected tasks for enquiry {$enquiry->id}: " . implode(', ', $selectedTaskTypes));

            // Get existing tasks to prevent duplication and allow syncing
            $existingTasks = EnquiryTask::where('project_enquiry_id', $enquiry->id)->get()->keyBy('type');
            
            $presets = config('enquiry_workflow.task_presets', []);
            $selectedPreset = $enquiry->workflow_preset_type;
            $titleOverrides = ($selectedPreset && isset($presets[$selectedPreset]['title_overrides'])) 
                ? $presets[$selectedPreset]['title_overrides'] 
                : [];

            foreach ($taskTemplates as $index => $template) {
                $type = $template['type'];
                $idealOrder = $index + 1; // Use canonical order from template config base-1

                // If task exists, update its order to ensure sequence is correct (e.g. if we insert a task)
                if ($existingTasks->has($type)) {
                    $existingTasks[$type]->update(['task_order' => $idealOrder]);
                    continue; 
                }

                // If not selected, skip creation
                if (!in_array($type, $selectedTaskTypes)) {
                    continue;
                }
                
                $status = 'pending';
                $notes = $template['notes'] ?? 'Complete this task';

                // Handle skipped site survey
                if ($type === 'site-survey' && $enquiry->site_survey_skipped) {
                    $status = 'completed';
                    $reason = $enquiry->site_survey_skip_reason ?? 'No reason provided';
                    $notes = "Site Survey skipped. Reason: {$reason}";
                    Log::info("Auto-completing site survey task for enquiry {$enquiry->id} (Skipped by user)");
                }

                $title = $titleOverrides[$type] ?? $template['title'];

                EnquiryTask::create([
                    'project_enquiry_id' => $enquiry->id,
                    'title' => $title,
                    'type' => $type,
                    'status' => $status,
                    'priority' => EnquiryConstants::PRIORITY_MEDIUM,
                    'notes' => $notes, // Fixed syntax error here previously
                    'created_by' => $enquiry->created_by,
                    'task_order' => $idealOrder, 
                ]);

                Log::info("Created new {$type} task for enquiry {$enquiry->id}");
            }

            // Cleanup: Remove tasks that are no longer selected (if they are safely removable)
            foreach ($existingTasks as $type => $task) {
                if (!in_array($type, $selectedTaskTypes)) {
                    // Only delete if status is pending (safe to remove)
                    if ($task->status === 'pending') {
                         $task->delete();
                         Log::info("Deleted removed task '{$type}' for enquiry {$enquiry->id}");
                    }
                }
            }

            Log::info("Workflow task sync completed for enquiry {$enquiry->id}");

        } catch (\Exception $e) {
            Log::error("Failed to sync workflow tasks for enquiry {$enquiry->id}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Attempt to automatically complete a task if it meets the criteria.
     * This is called by model hooks (e.g., when a budget is saved).
     */
    public function tryAutoCompleteTask(EnquiryTask $task): bool
    {
        Log::info("Attempting auto-completion for task {$task->id} (Type: {$task->type})");

        if ($task->status === 'completed') {
            return true;
        }

        try {
            // Validate if task is ready for completion
            $this->validateTaskCompletion($task);

            // If we reach here, validation passed
            $this->updateTaskStatus($task->id, 'completed');
            
            Log::info("Task {$task->id} auto-completed successfully.");
            return true;

        } catch (\Exception $e) {
            // If validation fails, we just don't auto-complete.
            // This is "soft" failure, so we just log it and return false.
            Log::info("Auto-completion skipped for task {$task->id}: " . $e->getMessage());
            return false;
        }
    }





    /**
     * Update task status and handle workflow progression
     */
    public function updateTaskStatus(int $taskId, string $status, ?int $userId = null): EnquiryTask
    {
        $task = EnquiryTask::findOrFail($taskId);

        $oldStatus = $task->status;

        // 1. Universal Governance Check (Expenditure/Financial/Technical Gates)
        if (in_array($status, ['in_progress', 'completed'])) {
            $gateResult = $this->governanceService->evaluateTask($task);
            if (!$gateResult->isAuthorized()) {
                throw new \Exception($gateResult->getMessage());
            }
        }

        // 2. Specific Hard Gate Validation for Completion (Legacy Checks)
        if ($status === 'completed') {
            $this->validateTaskCompletion($task);
        }

        // If task is skipped, we mark it as completed but bypass validation
        if ($status === 'skipped') {
            $status = 'completed';
        }

        $task->status = $status;
        $task->save();

        Log::info("Task {$taskId} status changed from {$oldStatus} to {$status}");

        // Handle enquiry status progression based on task completion
        if ($status === 'completed') {
            // Only quote approval triggers automatic status progression
            if ($task->type === 'quote_approval') {
                $this->handleEnquiryStatusProgression($task);
            }
            $this->handleTaskSpecificTransitions($task, $status);
        } elseif (in_array($oldStatus, ['completed', 'skipped']) && $status !== 'completed') {
            // Handle status reversion when task is reopened
            // Manual reversion preferred for all tasks
            // $this->handleEnquiryStatusReversion($task);
        } elseif ($status === 'in_progress') {
            $this->handleTaskSpecificTransitions($task, $status);
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
        // if (!$assignedUser->department_id) {
        //     throw new \Exception("Cannot assign task to user without department");
        // }

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
            // Allow today and future dates, block only dates before today
            if ($dueDate->isBefore(now()->startOfDay())) {
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
     * Release task back to the pool (Unassign)
     */
    public function releaseTask(int $taskId, int $releasedByUserId, ?string $reason = null): EnquiryTask
    {
        $task = EnquiryTask::findOrFail($taskId);
        
        if (!$task->assigned_to) {
            throw new \Exception("Task is already unassigned");
        }

        $previousAssigneeId = $task->assigned_to;
        
        // Create history entry
        TaskAssignmentHistory::create([
            'enquiry_task_id' => $task->id,
            'assigned_to' => null,
            'assigned_by' => $releasedByUserId,
            'assigned_at' => now(),
            'notes' => "Task released to pool. Reason: " . ($reason ?? 'No reason provided'),
        ]);

        // Clear assignment
        $task->update([
            'assigned_to' => null,
            // We keep department_id so it stays in the correct pool
        ]);
        
        // Remove from pivot table
        $task->assignedUsers()->detach($previousAssigneeId);

        Log::info("Task {$taskId} released (handover) by user {$releasedByUserId}");

        return $task->fresh();
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
        $statusMapping = EnquiryConstants::ENQUIRY_STATUS_REQUISITES;

        // The progression is now handled via the SyncEnquiryStatusAction 
        // which is triggered by the EnquiryTaskObserver. 
        // This method is kept for any legacy hooks but now delegates to the action.
        app(SyncEnquiryStatusAction::class)->execute($enquiry->id);
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

        if (!$enquiry) {
            Log::warning("Cannot revert enquiry status for task {$task->id}: Enquiry not found.");
            return;
        }

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
        return app(SyncEnquiryStatusAction::class)->execute($enquiry->id)->status;
    }

    /**
     * Validate if a task is ready to be marked as completed
     */
    public function validateTaskCompletion(EnquiryTask $task): void
    {
        // 0. Status Check
        if ($task->status === 'completed') {
            return;
        }

        $user = auth()->user();
        $isAdmin = $user && $user->hasRole(\App\Constants\EnquiryConstants::ROLES_ADMIN);

        // 1. Materials Validation (Approvals)
        if ($task->type === 'materials') {
            $materialsData = \App\Models\TaskMaterialsData::where('enquiry_task_id', $task->id)->first();
            if ($materialsData && !$isAdmin) {
                $status = $materialsData->project_info['approval_status'] ?? [];
                if (!($status['all_approved'] ?? false)) {
                    throw new \Exception("Cannot complete Materials task. Project Approval is required.");
                }
            }
        }

        // 2. Budget Validation
        if ($task->type === 'budget') {
            $budgetData = \App\Models\TaskBudgetData::where('enquiry_task_id', $task->id)->first();
            if (!$budgetData && !$isAdmin) {
                throw new \Exception("Cannot complete Budget task. Budget data is missing. Please save the budget before completing.");
            }
        }

        // 3. Quote Validation
        if ($task->type === 'quote') {
            $quoteData = \App\Models\TaskQuoteData::where('enquiry_task_id', $task->id)->first();
            if (!$quoteData && !$isAdmin) {
                throw new \Exception("Cannot complete Quote Preparation task. Quote data is missing. Please prepare the quote before completing.");
            }
        }

        // 4. Quote Approval Validation
        if ($task->type === 'quote_approval') {
            $approval = \DB::table('quote_approvals')->where('task_id', $task->id)->first();
            if (!$approval || $approval->approval_status === 'pending') {
                if (!$isAdmin) {
                    throw new \Exception("Cannot complete Quote Approval task. A final decision (Approved/Rejected) is required.");
                }
            }
        }
    }

    /**
     * Handle transitions for specific task types (e.g. Handover, Production)
     */
    private function handleTaskSpecificTransitions(EnquiryTask $task, string $status): void
    {
        // 1. Client Handover Initialization
        // When Production is done OR Setup is done OR Handover task is started
        $triggerTypes = ['production', 'setup', 'handover'];
        
        if (in_array($task->type, $triggerTypes) && (in_array($status, ['completed', 'skipped']) || ($task->type === 'handover' && $status === 'in_progress'))) {
            $this->initializeHandoverSurvey($task);
        }
    }

    /**
     * Initialize Handover Survey and Access Token
     */
    private function initializeHandoverSurvey(EnquiryTask $task): void
    {
        // Find the actual handover task for this enquiry
        $handoverTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
            ->where('type', 'handover')
            ->first();

        if (!$handoverTask) return;

        // Check if survey already exists
        $exists = \App\Models\HandoverSurvey::where('task_id', $handoverTask->id)->exists();
        if ($exists) return;

        // Create the survey and token
        \App\Models\HandoverSurvey::create([
            'task_id' => $handoverTask->id,
            'access_token' => \Illuminate\Support\Str::random(32),
            'submitted' => false,
            'question_config_snapshot' => config('survey_questions'),
        ]);

        Log::info("Auto-initialized Handover Survey for Enquiry {$task->project_enquiry_id} via task {$task->type}");
    }


}
