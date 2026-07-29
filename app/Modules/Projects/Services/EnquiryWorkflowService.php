<?php

namespace App\Modules\Projects\Services;

use App\Models\ProjectEnquiry;
use App\Models\TaskAssignmentHistory;
use App\Models\User;
use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\HR\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Constants\EnquiryConstants;
use App\Services\Governance\ProjectGovernanceService;
use App\Modules\Projects\Actions\SyncEnquiryStatusAction;
use App\Exceptions\GovernanceException;
use App\Exceptions\WorkflowValidationException;

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

            // Auto-routing: resolve each task type to its owning department once,
            // so newly created tasks land in the right department pool instead of
            // requiring a coordinator to assign them manually. Single query, no N+1.
            $mappedDepartmentNames = collect(EnquiryTask::TASK_TYPE_DEPARTMENT_MAPPING)
                ->flatten()->unique()->all();
            $departmentIdByName = Department::whereIn('name', $mappedDepartmentNames)
                ->pluck('id', 'name');
            $departmentForType = static function (string $type) use ($departmentIdByName): ?int {
                $name = EnquiryTask::primaryDepartmentName($type);
                return $name ? ($departmentIdByName[$name] ?? null) : null;
            };

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

                // If task exists, keep its order correct and backfill the owning
                // department if it was never routed (do not override a manual assignment).
                if ($existingTasks->has($type)) {
                    $existing = $existingTasks[$type];
                    $updates = ['task_order' => $idealOrder];
                    if (empty($existing->department_id) && ($deptId = $departmentForType($type))) {
                        $updates['department_id'] = $deptId;
                    }
                    $existing->update($updates);
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
                    'department_id' => $departmentForType($type), // Auto-route to owning department
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
    public function updateTaskStatus(
        int $taskId,
        string $status,
        ?int $userId = null,
        ?string $reason = null
    ): EnquiryTask
    {
        $task = EnquiryTask::findOrFail($taskId);

        $oldStatus = $task->status;

        // Status updates are deliberately idempotent. Several legacy task
        // components can submit the same transition twice; repeating it must
        // not generate duplicate notifications or audit events.
        if ($oldStatus === $status) {
            return $task;
        }

        // Governance and validation run outside the transaction — they are read-only checks
        // and throwing here avoids opening a transaction we'd immediately roll back.
        if (in_array($status, ['in_progress', 'completed'])) {
            $gateResult = $this->governanceService->evaluateTask($task);
            if (!$gateResult->isAuthorized()) {
                throw new GovernanceException($gateResult->getMessage(), $gateResult->context);
            }
        }

        if ($status === 'completed') {
            $this->validateTaskCompletion($task);
        }

        return DB::transaction(function () use ($task, $taskId, $status, $oldStatus, $userId, $reason) {
            $task->status = $status;

            if ($status === 'in_progress' && !$task->started_at) {
                $task->started_at = now();
            }

            if ($status === 'completed') {
                $task->completed_at = now();
            } elseif ($oldStatus === 'completed') {
                // A reopened/cancelled task is no longer complete. Preserve the
                // previous value in ActionLog before clearing the live field.
                $task->completed_at = null;
            }

            $task->save();

            $task->recordCustomAction('status_transition', [
                'from' => $oldStatus,
                'to' => $status,
                'actor_type' => $userId ? 'user' : 'system',
                'actor_id' => $userId,
                'reason' => $reason,
            ]);

            Log::info("Task {$taskId} status changed from {$oldStatus} to {$status}");

            if ($status === 'completed') {
                if ($task->type === 'quote_approval') {
                    $this->handleEnquiryStatusProgression($task);
                }
                $this->handleTaskSpecificTransitions($task, $status);
            } elseif ($status === 'in_progress') {
                $this->handleTaskSpecificTransitions($task, $status);
            }

            return $task;
        });
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
            'assigned_user_id' => $assignedUserId,
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
                throw new WorkflowValidationException("Due date cannot be in the past");
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
            throw new WorkflowValidationException("Cannot reassign unassigned task");
        }

        if ($task->assigned_to === $newAssignedUserId) {
            throw new WorkflowValidationException("Cannot reassign to the same user");
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
            'assigned_user_id' => $newAssignedUserId,
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
            throw new WorkflowValidationException("Task is already unassigned");
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
            'assigned_user_id' => null,
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
        // Escalate every overdue, still-open task — including unassigned ones,
        // which are themselves a coordination failure worth surfacing. Recipient
        // resolution (pivot assignees, legacy column, or the project manager) is
        // handled per task in sendOverdueNotifications().
        $overdueTasks = EnquiryTask::where('due_date', '<', now())
            ->whereNotIn('status', ['completed', 'skipped', 'cancelled'])
            ->with('assignedUsers')
            ->get();

        // Fetch PM users once — avoids an N+1 query per task in sendOverdueNotifications()
        $projectManagers = \Spatie\Permission\Models\Role::where('name', 'Project Manager')
            ->first()
            ?->users()
            ->get() ?? collect();

        foreach ($overdueTasks as $task) {
            try {
                $this->escalateTaskPriority($task);
                $this->sendOverdueNotifications($task, $projectManagers);
            } catch (\Exception $e) {
                Log::error("Failed to escalate/notify for overdue task {$task->id}: " . $e->getMessage());
            }
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
    private function sendOverdueNotifications(EnquiryTask $task, \Illuminate\Support\Collection $projectManagers): void
    {
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

        foreach ($projectManagers as $pm) {
            $this->notificationService->sendTaskOverdueNotification($task, $pm);
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

        // Site Survey — a final survey save is the evidence of completion. Draft
        // records must never unlock Design.
        if ($task->type === 'site-survey' && !$isAdmin) {
            $survey = \App\Models\SiteSurvey::where('enquiry_task_id', $task->id)->first();
            if (!$survey || !in_array($survey->status, ['completed', 'approved'], true)) {
                throw new WorkflowValidationException('Cannot complete Site Survey. Submit the final survey first.');
            }
        }

        // Design — at least one approved deliverable is required. Rejected and
        // draft uploads may remain as revision history without blocking closure.
        if ($task->type === 'design' && !$isAdmin) {
            if (!$task->designAssets()->where('status', 'approved')->exists()) {
                throw new WorkflowValidationException('Cannot complete Design task. At least one design asset must be approved first.');
            }
        }

        // 1. Materials Validation (Approvals)
        // Uses ROLES_SYSTEM_ADMIN rather than the general $isAdmin: Project
        // Officer is in ROLES_ADMIN but is also one of the two required
        // approvers here, so it must not be able to self-bypass this gate.
        if ($task->type === 'materials') {
            $isSystemAdmin = $user && $user->hasRole(\App\Constants\EnquiryConstants::ROLES_SYSTEM_ADMIN);
            if (!$isSystemAdmin) {
                $materialsData = \App\Models\TaskMaterialsData::where('enquiry_task_id', $task->id)->first();
                if (!$materialsData) {
                    throw new WorkflowValidationException('Cannot complete Materials task. Materials data is missing.');
                }

                $status = $materialsData->project_info['approval_status'] ?? [];
                if (!($status['all_approved'] ?? false)) {
                    throw new WorkflowValidationException("Cannot complete Materials task. Both Project Officer and Production approval are required.");
                }
            }
        }

        // 2. Budget Validation — requires a PRICED budget. Since the internal
        // budget approval step was removed (2026-07), task completion is the
        // finalization signal consumed by procurement and finance summaries,
        // so an empty budget must not complete the task.
        if ($task->type === 'budget') {
            $budgetData = \App\Models\TaskBudgetData::where('enquiry_task_id', $task->id)->first();
            if (!$budgetData && !$isAdmin) {
                throw new WorkflowValidationException("Cannot complete Budget task. Budget data is missing. Please save the budget before completing.");
            }

            $summary = $budgetData?->budget_summary ?? [];
            $grandTotal = (float) ($summary['grandTotal'] ?? $summary['grand_total'] ?? 0);
            if ($budgetData && $grandTotal <= 0 && !$isAdmin) {
                throw new WorkflowValidationException("Cannot complete Budget task. The budget has no priced totals yet — add materials, labour, expenses, or logistics first.");
            }
        }

        // 3. Quote Validation — passes when:
        //    a) in-system quote data exists (built_in mode), OR
        //    b) an Excel file + amount has been uploaded (excel_upload mode),
        //    AND the quote has been APPROVED by Finance. The approval decision
        //    is recorded via saveApproval (not blocked by task ordering), and
        //    the TaskQuoteData observer then auto-completes this task.
        if ($task->type === 'quote') {
            $quoteData = \App\Models\TaskQuoteData::where('enquiry_task_id', $task->id)->first();
            $hasExcelQuote = $quoteData
                && $quoteData->quote_mode === 'excel_upload'
                && $quoteData->excel_quote_file
                && $quoteData->excel_quote_amount !== null;

            if (!$quoteData && !$isAdmin) {
                throw new WorkflowValidationException("Cannot complete Quote Preparation task. Quote data is missing. Either prepare the quote in-system or upload an Excel quote.");
            }

            if ($quoteData && !$hasExcelQuote && $quoteData->quote_mode === 'excel_upload' && !$isAdmin) {
                throw new WorkflowValidationException("Cannot complete Quote Preparation task. Excel quote upload is incomplete — both a file and a quote amount are required.");
            }

            $isApproved = $quoteData
                && ($quoteData->approval_status ?? $quoteData->status) === 'approved';

            if ($quoteData && !$isApproved && !$isAdmin) {
                $currentStatus = $quoteData->approval_status ?? $quoteData->status ?? 'draft';
                throw new WorkflowValidationException("Cannot complete Quote Preparation task. The quote must be approved first (current status: {$currentStatus}).");
            }
        }

        // 4. Quote Approval Validation
        if ($task->type === 'quote_approval') {
            $approval = \DB::table('quote_approvals')->where('task_id', $task->id)->first();
            if (!$approval || $approval->approval_status !== 'approved') {
                if (!$isAdmin) {
                    throw new WorkflowValidationException('Cannot complete Quote Approval task. The quote must be approved; rejected quotes require revision.');
                }
            }
        }

        // Procurement — mirrors the current screen's "Open procurement" count.
        // In-stock/no-purchase lines are already resolved; only positive purchase
        // quantities still awaiting receipt block completion.
        if ($task->type === 'procurement' && !$isAdmin) {
            $procurement = \App\Models\TaskProcurementData::where('enquiry_task_id', $task->id)->first();
            $items = $procurement?->procurement_items ?? [];
            if (empty($items)) {
                throw new WorkflowValidationException('Cannot complete Procurement task. No procurement items have been prepared.');
            }

            $openItems = collect($items)->filter(function (array $item): bool {
                return (float) ($item['purchaseQuantity'] ?? 0) > 0
                    && ($item['procurementStatus'] ?? null) !== 'received'
                    && ($item['availabilityStatus'] ?? null) !== 'received';
            })->count();

            if ($openItems > 0) {
                throw new WorkflowValidationException("Cannot complete Procurement task. {$openItems} purchased item(s) are still awaiting receipt.");
            }
        }

        // 5. Handover Validation — the client handover survey is the record that
        // the client actually received and acknowledged the delivery. Completing
        // this task (and, downstream, closing the project) must not be possible
        // without it, since it was previously enforced only in the Vue layer.
        if ($task->type === 'handover' && !$isAdmin) {
            $survey = $task->handoverSurvey;
            if (!$survey || !$survey->submitted) {
                throw new WorkflowValidationException("Cannot complete Client Handover task. The handover survey has not been submitted yet.");
            }
        }

        // 6. Report/Archival Validation — a project is only archived after
        // approval, sign-off, and all required closure records are confirmed.
        // Closure governance applies to every role, including administrators.
        // Administrative access must not silently bypass an approved archive.
        if ($task->type === 'report') {
            $report = \App\Modules\ArchivalTask\Models\ArchivalReport::where('enquiry_task_id', $task->id)->first();
            if (!$report || !$report->project_officer_signature || !$report->project_officer_sign_date) {
                throw new WorkflowValidationException("Cannot complete Archival & Reporting task. The report must be signed off by the Project Officer first.");
            }

            if (!$report->reviewed_by || !$report->reviewer_sign_date) {
                throw new WorkflowValidationException("Cannot complete Archival & Reporting task. Management review and date are required.");
            }

            if (!$report->archive_reference || !$report->archive_location) {
                throw new WorkflowValidationException("Cannot complete Archival & Reporting task. Archive reference and location are required.");
            }

            if ($report->status !== 'approved') {
                throw new WorkflowValidationException("Cannot complete Archival & Reporting task. Submit and approve the report first.");
            }

            $missingChecks = count(app(\App\Modules\ArchivalTask\Services\ArchivalReportService::class)
                ->getMissingRequiredChecks($task->id, $report));
            if ($missingChecks > 0) {
                throw new WorkflowValidationException("Cannot complete Archival & Reporting task. {$missingChecks} required archive checklist item(s) remain incomplete.");
            }
        }

        // 7. Setup Validation — mirrors SetupTask.vue's own gate: no unresolved
        // on-site issues.
        if ($task->type === 'setup' && !$isAdmin) {
            $setupTask = \App\Modules\setupTask\Models\SetupTask::where('task_id', $task->id)->first();
            if (!$setupTask) {
                throw new WorkflowValidationException("Cannot complete Event Setup & Execution task. Setup documentation has not been started yet.");
            }
            $openIssues = $setupTask->issues()->where('status', '!=', 'resolved')->count();
            if ($openIssues > 0) {
                throw new WorkflowValidationException("Cannot complete Event Setup & Execution task. {$openIssues} open issue(s) must be resolved first.");
            }
        }

        // 8. Setdown Validation — mirrors SetdownTask.vue's own gate.
        if ($task->type === 'setdown' && !$isAdmin) {
            $setdownTask = \App\Modules\setdownTask\Models\SetdownTask::where('task_id', $task->id)->first();
            if (!$setdownTask) {
                throw new WorkflowValidationException("Cannot complete Set Down & Return task. Setdown documentation has not been started yet.");
            }
            $openIssues = $setdownTask->setdownIssues()->where('status', '!=', 'resolved')->count();
            if ($openIssues > 0) {
                throw new WorkflowValidationException("Cannot complete Set Down & Return task. {$openIssues} open issue(s) must be resolved first.");
            }
        }

        // 9. Production Validation — mirrors ProductionTask.vue's
        // allElementsComplete gate: every work-order task must be completed.
        if ($task->type === 'production' && !$isAdmin) {
            $workOrder = $task->workOrder;
            $woTasks = $workOrder?->tasks;
            if (!$workOrder || !$woTasks || $woTasks->isEmpty()) {
                throw new WorkflowValidationException("Cannot complete Production task. No work-order tasks have been created yet.");
            }
            $incomplete = $woTasks->where('status', '!=', 'completed')->count();
            if ($incomplete > 0) {
                throw new WorkflowValidationException("Cannot complete Production task. {$incomplete} work-order task(s) are not yet completed.");
            }
        }

        // 10. Logistics validation: company transport needs a trip plan. Every
        // dispatch still needs an accurate loading sheet and verified load.
        if ($task->type === 'logistics' && !$isAdmin) {
            $logisticsTask = \App\Modules\logisticsTask\Models\LogisticsTask::where('task_id', $task->id)
                ->withCount('transportItems')
                ->first();
            if (!$logisticsTask) {
                throw new WorkflowValidationException('Complete the logistics transport details before dispatch.');
            }

            $planning = $logisticsTask->logistics_planning ?? [];
            $transportArrangement = data_get($planning, 'transport_arrangement', 'company');
            if (!in_array($transportArrangement, ['company', 'client'], true)) {
                throw new WorkflowValidationException('Select whether transport is arranged by the company or the client.');
            }
            if ($transportArrangement === 'company') {
                $requiredPlanning = [
                    'vehicle registration' => data_get($planning, 'vehicle_identification'),
                    'driver' => data_get($planning, 'driver_name'),
                    'destination' => data_get($planning, 'route.destination'),
                    'load time' => data_get($planning, 'timeline.loading_time'),
                    'departure time' => data_get($planning, 'timeline.departure_time'),
                    'delivery date' => data_get($planning, 'timeline.setup_start_time'),
                    'delivery time' => data_get($planning, 'timeline.setup_start_hour'),
                ];
                $missingPlanning = collect($requiredPlanning)->filter(fn ($value) => blank($value))->keys();
                if ($missingPlanning->isNotEmpty()) {
                    throw new WorkflowValidationException(
                        'Complete the company transport plan: '.$missingPlanning->join(', ', ' and ').'.'
                    );
                }
            }
            if ($logisticsTask->transport_items_count < 1) {
                throw new WorkflowValidationException('Add at least one item to the loading sheet before dispatch.');
            }

            $checklist = $logisticsTask
                ? \App\Modules\logisticsTask\Models\LogisticsChecklist::where('logistics_task_id', $logisticsTask->id)->first()
                : null;
            $items = $checklist?->checklist_data['items'] ?? [];
            if (empty($items)) {
                throw new WorkflowValidationException('Generate and verify the load checklist before dispatch.');
            }
            $notPresent = collect($items)->filter(fn($item) => ($item['status'] ?? 'missing') !== 'present')->count();
            if ($notPresent > 0) {
                throw new WorkflowValidationException("Verify the remaining {$notPresent} load item(s) before dispatch.");
            }
        }

        // 11. Teams Validation — a team task with a defined member requirement
        // must have that many active members assigned; one with no requirement
        // set must still have at least one, mirroring
        // TeamsTask::getCompletionPercentageAttribute()'s own definition of done.
        if ($task->type === 'teams' && !$isAdmin) {
            $teamsTasks = \App\Modules\Teams\Models\TeamsTask::where('task_id', $task->id)
                ->withCount('activeMembers')
                ->get();
            if ($teamsTasks->isEmpty()) {
                throw new WorkflowValidationException("Cannot complete Teams task. No team has been set up for this task yet.");
            }

            $understaffed = $teamsTasks->filter(
                fn ($team) => $team->active_members_count < max((int) $team->required_members, 1)
            );
            if ($understaffed->isNotEmpty()) {
                $missing = $understaffed->sum(
                    fn ($team) => max((int) $team->required_members, 1) - $team->active_members_count
                );
                throw new WorkflowValidationException(
                    "Cannot complete Teams task. {$understaffed->count()} team(s) still need {$missing} member assignment(s)."
                );
            }
        }
    }

    /**
     * When a task is completed, proactively notify the owners of any tasks that
     * this completion has just unblocked, so coordination happens automatically
     * rather than people polling the board. Recipients are the dependent task's
     * assigned users, or — if unassigned — its owning department pool.
     */
    private function notifyUnblockedDependents(EnquiryTask $completedTask): void
    {
        $dependencyMap = config('enquiry_workflow.task_dependencies', []);

        // Task types that list the just-completed type as a prerequisite.
        $dependentTypes = [];
        foreach ($dependencyMap as $type => $prerequisites) {
            if (in_array($completedTask->type, $prerequisites, true)) {
                $dependentTypes[] = $type;
            }
        }

        if (empty($dependentTypes)) {
            return;
        }

        $dependentTasks = EnquiryTask::where('project_enquiry_id', $completedTask->project_enquiry_id)
            ->whereIn('type', $dependentTypes)
            ->where('status', 'pending')
            ->with('assignedUsers')
            ->get();

        foreach ($dependentTasks as $dependent) {
            // Only announce tasks that are now fully unblocked.
            if (!empty($dependent->blockingPrerequisiteTitles())) {
                continue;
            }

            $recipients = $dependent->assignedUsers->isNotEmpty()
                ? $dependent->assignedUsers
                : ($dependent->department_id
                    ? User::where('department_id', $dependent->department_id)->get()
                    : collect());

            foreach ($recipients as $recipient) {
                $this->notificationService->sendTaskReadyNotification($dependent, $recipient);
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
        
        if (in_array($task->type, $triggerTypes) && ($status === 'completed' || ($task->type === 'handover' && $status === 'in_progress'))) {
            $this->initializeHandoverSurvey($task);
        }

        // 2. Proactively notify the owners of any tasks this completion unblocks.
        if ($status === 'completed') {
            $this->notifyUnblockedDependents($task);
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
