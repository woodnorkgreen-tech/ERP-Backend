<?php

namespace App\Modules\Projects\Services;

use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationService as CentralNotificationService;
use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\UniversalTask\Models\Task as UniversalTask;
use Illuminate\Support\Facades\Log;

/**
 * Formats and dispatches Projects-module notifications through the
 * centralized Notifications module (App\Modules\Notifications), which owns
 * persistence, channel/preference resolution, and delivery (mail/push).
 *
 * Public method signatures are unchanged from the pre-migration version so
 * every existing caller (AssignTaskAction, ApproveQuoteAction, workflow
 * services, etc.) keeps working without modification.
 */
class NotificationService
{
    /**
     * Send notification for Enquiry Task assignment
     */
    public function sendEnquiryTaskAssignment(
        EnquiryTask $task,
        User $assignedTo,
        User $assignedBy,
        bool $isReassignment = false
    ): void {
        try {
            $task->loadMissing('enquiry.client'); // Ensure relationships are loaded

            $type = $isReassignment ? 'enquiry_task_reassigned' : 'enquiry_task_assigned';
            $title = $isReassignment ? 'Task Reassigned' : 'New Task Assigned';

            $enquiryTitle = $task->enquiry ? $task->enquiry->title : 'Unknown Project';
            $enquiryNumber = $task->enquiry ? $task->enquiry->enquiry_number : 'N/A';
            $clientName = $task->enquiry && $task->enquiry->client ? $task->enquiry->client->name : 'Unknown Client';

            CentralNotificationService::send(
                type: $type,
                title: $title,
                message: "You have been assigned: {$task->title} for {$enquiryTitle} (#{$enquiryNumber})",
                module: 'projects',
                data: [
                    'task_id' => $task->id,
                    'enquiry_id' => $task->project_enquiry_id,
                    'enquiry_title' => $enquiryTitle,
                    'enquiry_number' => $enquiryNumber,
                    'client_name' => $clientName,
                    'task_type' => $task->type,
                    'assigned_by' => $assignedBy->name,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date?->toISOString(),
                    'url' => $this->taskWorkspaceUrl($task),
                ],
                users: [$assignedTo],
            );

            // If reassignment, notify the old assignee
            if ($isReassignment && $task->getOriginal('assigned_user_id')) {
                $oldUserId = $task->getOriginal('assigned_user_id');
                if ($oldUserId && $oldUserId !== $assignedTo->id) {
                    $oldUser = User::find($oldUserId);
                    if ($oldUser) {
                        CentralNotificationService::send(
                            type: 'enquiry_task_unassigned',
                            title: 'Task Reassigned',
                            message: "Task '{$task->title}' for {$enquiryTitle} has been reassigned to {$assignedTo->name}",
                            module: 'projects',
                            data: [
                                'task_id' => $task->id,
                                'enquiry_id' => $task->project_enquiry_id,
                                'enquiry_title' => $enquiryTitle,
                                'reassigned_to' => $assignedTo->name,
                                'reassigned_by' => $assignedBy->name,
                            ],
                            users: [$oldUser],
                        );
                    }
                }
            }

            Log::info("Enquiry task notification sent", [
                'task_id' => $task->id,
                'user_id' => $assignedTo->id,
                'type' => $type
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send enquiry task notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification for Universal Task assignment
     */
    public function sendUniversalTaskAssignment(
        UniversalTask $task,
        User $assignedTo,
        User $assignedBy
    ): void {
        try {
            // Resolve context if available
            $contextTitle = null;
            $contextNumber = null;
            $clientName = null;

            if ($task->taskable_type && $task->taskable_id) {
                // Try to load the polymorphic relation
                $related = $task->taskable; // Assuming 'taskable' relationship exists in UniversalTask model
                if (!$related && class_exists($task->taskable_type)) {
                    $related = $task->taskable_type::find($task->taskable_id);
                }

                if ($related) {
                    if ($related instanceof \App\Models\ProjectEnquiry) {
                        $contextTitle = $related->title;
                        $contextNumber = $related->enquiry_number;
                        // Load client if possible
                        if ($related->client) {
                            $clientName = $related->client->name;
                        }
                    } elseif (property_exists($related, 'title')) {
                        $contextTitle = $related->title;
                    } elseif (property_exists($related, 'name')) {
                        $contextTitle = $related->name;
                    }
                }
            }

            $message = "You have been assigned: {$task->title}";
            if ($contextTitle) {
                $message .= " for {$contextTitle}";
                if ($contextNumber) $message .= " (#{$contextNumber})";
            }

            CentralNotificationService::send(
                type: 'universal_task_assigned',
                title: 'New Task Assigned',
                message: $message,
                module: 'universal-task',
                data: [
                    'task_id' => $task->id,
                    'task_type' => $task->task_type,
                    'assigned_by' => $assignedBy->name,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date?->toISOString(),
                    'taskable_type' => $task->taskable_type,
                    'taskable_id' => $task->taskable_id,
                    // Unified context fields for frontend
                    'enquiry_title' => $contextTitle,
                    'enquiry_number' => $contextNumber,
                    'client_name' => $clientName,
                    'url' => "/universal-tasks/{$task->id}",
                ],
                users: [$assignedTo],
            );

            Log::info("Universal task notification sent", [
                'task_id' => $task->id,
                'user_id' => $assignedTo->id
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send universal task notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when task is due soon
     */
    public function sendTaskDueSoonNotification($task, User $user, string $taskType = 'enquiry'): void
    {
        try {
            $isEnquiry = $taskType === 'enquiry';

            if ($isEnquiry) {
                $task->loadMissing('enquiry');
                $enquiryTitle = $task->enquiry ? $task->enquiry->title : 'Project';
                $message = "Upcoming Deadline: '{$task->title}' for {$enquiryTitle} is due soon";
            } else {
                $message = "Upcoming Deadline: '{$task->title}' is due soon";
            }

            $type = $isEnquiry ? 'enquiry_task_due_soon' : 'universal_task_due_soon';
            $daysRemaining = now()->diffInDays($task->due_date, false);

            CentralNotificationService::send(
                type: $type,
                title: 'Task Due Soon',
                message: $message,
                module: $isEnquiry ? 'projects' : 'universal-task',
                urgency: 'warning',
                data: [
                    'task_id' => $task->id,
                    'due_date' => $task->due_date?->toISOString(),
                    'days_remaining' => $daysRemaining,
                    'priority' => $task->priority,
                ] + ($isEnquiry && $task->enquiry ? ['enquiry_id' => $task->project_enquiry_id, 'enquiry_title' => $task->enquiry->title] : []),
                users: [$user],
            );

            Log::info("{$taskType} task due soon notification sent", [
                'task_id' => $task->id,
                'user_id' => $user->id
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send task due soon notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when task is overdue
     */
    public function sendTaskOverdueNotification($task, User $user, string $taskType = 'enquiry'): void
    {
        try {
            $isEnquiry = $taskType === 'enquiry';

            if ($isEnquiry) {
                $task->loadMissing('enquiry');
                $enquiryTitle = $task->enquiry ? $task->enquiry->title : 'Project';
                $message = "Task Overdue: '{$task->title}' for {$enquiryTitle} is overdue";
            } else {
                $message = "Task Overdue: '{$task->title}' is overdue";
            }

            $type = $isEnquiry ? 'enquiry_task_overdue' : 'universal_task_overdue';
            $daysOverdue = $task->due_date->diffInDays(now());

            CentralNotificationService::send(
                type: $type,
                title: 'Task Overdue',
                message: "Task '{$task->title}' is now {$daysOverdue} day(s) overdue",
                module: $isEnquiry ? 'projects' : 'universal-task',
                urgency: 'warning',
                data: [
                    'task_id' => $task->id,
                    'due_date' => $task->due_date->toISOString(),
                    'days_overdue' => $daysOverdue,
                    'priority' => $task->priority,
                ] + ($isEnquiry && $task->enquiry ? ['enquiry_id' => $task->project_enquiry_id, 'enquiry_title' => $task->enquiry->title] : []),
                users: [$user],
            );

            Log::info("{$taskType} task overdue notification sent", [
                'task_id' => $task->id,
                'user_id' => $user->id
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send task overdue notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when a new enquiry is created
     * Enhanced to include enquiry type, workflow preset, and PO information
     */
    public function sendEnquiryCreatedNotification(ProjectEnquiry $enquiry, User $recipient): void
    {
        try {
            // Load relationships if not already loaded
            $enquiry->loadMissing(['client', 'creator', 'projectOfficer']);

            $message = "New enquiry '{$enquiry->title}' (#{$enquiry->enquiry_number}) has been created";
            if ($enquiry->workflow_preset_type) {
                $message .= " - Type: {$enquiry->workflow_preset_type}";
            }

            CentralNotificationService::send(
                type: 'enquiry_created',
                title: 'New Project Enquiry',
                message: $message,
                module: 'projects',
                data: [
                    'enquiry_id' => $enquiry->id,
                    'enquiry_title' => $enquiry->title,
                    'enquiry_number' => $enquiry->enquiry_number,
                    'enquiry_type' => $enquiry->workflow_preset_type ?? 'Standard',
                    'workflow_preset' => $enquiry->workflow_preset_type,
                    'client_name' => $enquiry->client ? $enquiry->client->full_name : 'Unknown Client',
                    'created_by' => $enquiry->creator ? $enquiry->creator->name : 'Unknown User',
                    'project_officer' => $enquiry->projectOfficer ? $enquiry->projectOfficer->name : 'Unassigned',
                    'project_officer_id' => $enquiry->project_officer_id,
                    'priority' => $enquiry->priority,
                    'date_received' => $enquiry->date_received?->toISOString(),
                    'expected_delivery_date' => $enquiry->expected_delivery_date?->toISOString(),
                    'url' => "/projects/enquiries/{$enquiry->id}",
                ],
                users: [$recipient],
            );

            Log::info("Enquiry created notification sent", [
                'enquiry_id' => $enquiry->id,
                'recipient_id' => $recipient->id,
                'enquiry_type' => $enquiry->workflow_preset_type ?? 'Standard'
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send enquiry created notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when Project Officer is assigned to an enquiry
     */
    public function sendProjectOfficerAssigned(
        ProjectEnquiry $enquiry,
        User $projectOfficer,
        User $assignedBy
    ): void {
        try {
            $enquiry->loadMissing(['client', 'creator']);

            CentralNotificationService::send(
                type: 'project_officer_assigned',
                title: 'Project Officer Assignment',
                message: "You have been assigned as Project Officer for '{$enquiry->title}' (#{$enquiry->enquiry_number})",
                module: 'projects',
                data: [
                    'enquiry_id' => $enquiry->id,
                    'enquiry_title' => $enquiry->title,
                    'enquiry_number' => $enquiry->enquiry_number,
                    'enquiry_type' => $enquiry->workflow_preset_type ?? 'Standard',
                    'client_name' => $enquiry->client ? $enquiry->client->full_name : 'Unknown Client',
                    'assigned_by' => $assignedBy->name,
                    'priority' => $enquiry->priority,
                    'expected_delivery_date' => $enquiry->expected_delivery_date?->toISOString(),
                    'venue' => $enquiry->venue,
                    'url' => "/projects/enquiries/{$enquiry->id}",
                ],
                users: [$projectOfficer],
            );

            Log::info("Project Officer assignment notification sent", [
                'enquiry_id' => $enquiry->id,
                'project_officer_id' => $projectOfficer->id
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send PO assignment notification: " . $e->getMessage());
        }
    }

    /**
     * Broadcast to every user that an enquiry has converted into an active
     * project (job number assigned, officially live).
     */
    public function sendProjectActivated(ProjectEnquiry $enquiry, User $activatedBy): void
    {
        try {
            $enquiry->loadMissing(['client', 'projectOfficer']);

            CentralNotificationService::send(
                type: 'project_activated',
                title: 'New Project Active',
                message: "Project {$enquiry->title} (#{$enquiry->job_number}) is officially live!",
                module: 'projects',
                data: [
                    'id' => $enquiry->id,
                    'title' => $enquiry->title,
                    'job_number' => $enquiry->job_number,
                    'client_name' => $enquiry->client?->full_name ?? $enquiry->client?->name ?? 'Client TBC',
                    'venue' => $enquiry->venue ?? 'Venue TBC',
                    'deadline' => $enquiry->expected_delivery_date?->format('d M Y') ?? 'TBC',
                    'project_officer' => $enquiry->projectOfficer?->name ?? 'Unassigned',
                    'activated_by' => $activatedBy->name,
                ],
                all: true,
            );

            Log::info('Project activation notification broadcast', ['enquiry_id' => $enquiry->id]);
        } catch (\Exception $e) {
            Log::error('Failed to dispatch project activation notifications', [
                'enquiry_id' => $enquiry->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send notification when quote is approved
     * HIGH PRIORITY - Notifies before project conversion
     */
    public function sendQuoteApproved(
        ProjectEnquiry $enquiry,
        User $approvedBy,
        string $jobNumber
    ): void {
        try {
            $enquiry->loadMissing(['client', 'creator', 'projectOfficer']);

            // Get quote amount from quote task if available
            $quoteAmount = null;
            $quoteTask = $enquiry->enquiryTasks()->where('type', 'quote')->first();
            if ($quoteTask && $quoteTask->quoteData) {
                $quoteAmount = $quoteTask->quoteData->grand_total ?? null;
            }

            $recipients = collect([$enquiry->projectOfficer, $enquiry->creator])
                ->filter()
                ->unique('id');

            foreach ($recipients as $recipient) {
                $isOfficer = $enquiry->projectOfficer && $recipient->id === $enquiry->projectOfficer->id;

                CentralNotificationService::send(
                    type: 'quote_approved',
                    title: $isOfficer ? 'Quote Approved - Project Active!' : 'Quote Approved',
                    message: $isOfficer
                        ? "Quote for '{$enquiry->title}' has been approved. Job Number: {$jobNumber}"
                        : "Your enquiry '{$enquiry->title}' quote has been approved! Job Number: {$jobNumber}",
                    module: 'projects',
                    data: [
                        'enquiry_id' => $enquiry->id,
                        'enquiry_title' => $enquiry->title,
                        'enquiry_number' => $enquiry->enquiry_number,
                        'job_number' => $jobNumber,
                        'client_name' => $enquiry->client ? $enquiry->client->full_name : 'Unknown Client',
                        'quote_amount' => $quoteAmount,
                        'approved_by' => $approvedBy->name,
                        'priority' => $enquiry->priority,
                        'expected_delivery_date' => $enquiry->expected_delivery_date?->toISOString(),
                        'url' => "/projects/enquiries/{$enquiry->id}",
                    ],
                    users: [$recipient],
                );
            }

            Log::info("Quote approval notification sent", [
                'enquiry_id' => $enquiry->id,
                'job_number' => $jobNumber
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send quote approval notification: " . $e->getMessage());
        }
    }

    /**
     * Notify Finance and the Project Officer that a previously approved quote
     * was invalidated (e.g. a new Excel revision replaced the approved file).
     */
    public function sendQuoteApprovalInvalidated(ProjectEnquiry $enquiry, ?User $actor, string $reason): void
    {
        try {
            $enquiry->loadMissing(['projectOfficer']);

            // Resolve permission-holders explicitly rather than broadcasting by
            // permission: broadcast recipients are additionally filtered by
            // userCanSeeModule() for the given module, which for 'projects' only
            // recognizes project-specific roles — a Finance/Costing approver
            // would be silently dropped even though the permission check itself
            // is the real authorization here.
            $recipients = User::permission(\App\Constants\Permissions::FINANCE_QUOTE_APPROVE)->get();
            if ($enquiry->projectOfficer) {
                $recipients->push($enquiry->projectOfficer);
            }
            $recipients = $recipients->unique('id');

            CentralNotificationService::send(
                type: 'quote_approval_invalidated',
                title: 'Quote Approval Invalidated - Re-review Required',
                message: "The approved quote for '{$enquiry->title}' was invalidated: {$reason}. Finance must re-review before funds are released.",
                module: 'projects',
                data: [
                    'enquiry_id' => $enquiry->id,
                    'enquiry_title' => $enquiry->title,
                    'enquiry_number' => $enquiry->enquiry_number,
                    'reason' => $reason,
                    'actor' => $actor?->name,
                    'url' => "/projects/enquiries/{$enquiry->id}",
                ],
                users: $recipients->all(),
            );

            Log::info("Quote approval invalidation notification sent", [
                'enquiry_id' => $enquiry->id,
                'recipients_count' => $recipients->count(),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send quote approval invalidation notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when enquiry status changes to critical milestones
     */
    public function sendEnquiryStatusChanged(
        ProjectEnquiry $enquiry,
        string $oldStatus,
        string $newStatus,
        User $changedBy
    ): void {
        try {
            // Only notify on critical status changes
            $criticalStatuses = [
                'site_survey_completed' => 'Site Survey Completed',
                'design_approved' => 'Design Approved',
                'quote_prepared' => 'Quote Prepared',
                'materials_specified' => 'Materials Specified',
                'budget_created' => 'Budget Created',
            ];

            if (!array_key_exists($newStatus, $criticalStatuses)) {
                return; // Not a critical status, skip notification
            }

            $enquiry->loadMissing(['client', 'projectOfficer']);
            $statusLabel = $criticalStatuses[$newStatus];

            if (!$enquiry->projectOfficer) {
                return;
            }

            CentralNotificationService::send(
                type: 'enquiry_status_changed',
                title: 'Enquiry Status Update',
                message: "'{$enquiry->title}' status updated to: {$statusLabel}",
                module: 'projects',
                data: [
                    'enquiry_id' => $enquiry->id,
                    'enquiry_title' => $enquiry->title,
                    'enquiry_number' => $enquiry->enquiry_number,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'status_label' => $statusLabel,
                    'changed_by' => $changedBy->name,
                    'client_name' => $enquiry->client ? $enquiry->client->full_name : 'Unknown Client',
                    'url' => "/projects/enquiries/{$enquiry->id}",
                ],
                users: [$enquiry->projectOfficer],
            );

            Log::info("Enquiry status change notification sent", [
                'enquiry_id' => $enquiry->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send status change notification: " . $e->getMessage());
        }
    }

    /**
     * Alias for sendEnquiryTaskCompleted to match controller usage
     */
    public function sendTaskCompletedNotification(EnquiryTask $task, User $completedBy): void
    {
        $this->sendEnquiryTaskCompleted($task, $completedBy);
    }

    /**
     * Send notification when Enquiry Task is completed
     */
    public function sendEnquiryTaskCompleted(EnquiryTask $task, User $completedBy): void
    {
        try {
            // Notify task creator
            if ($task->created_by && $task->created_by !== $completedBy->id) {
                $creator = User::find($task->created_by);
                if ($creator) {
                    $this->createCompletionNotification($task, $completedBy, $creator, 'enquiry');
                }
            }

            // Notify user who assigned it
            if ($task->assigned_by && $task->assigned_by !== $completedBy->id) {
                $assigner = User::find($task->assigned_by);
                if ($assigner && $assigner->id !== $task->created_by) {
                    $this->createCompletionNotification($task, $completedBy, $assigner, 'enquiry');
                }
            }

            Log::info("Enquiry task completion notifications sent", [
                'task_id' => $task->id,
                'completed_by' => $completedBy->id
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send task completion notification: " . $e->getMessage());
        }
    }

    /**
     * Notify a user that a task is now ready to start because its prerequisite
     * tasks have all been completed. Drives proactive workflow coordination.
     */
    public function sendTaskReadyNotification(EnquiryTask $task, User $user): void
    {
        try {
            $task->loadMissing('enquiry.client');

            $enquiryTitle = $task->enquiry ? $task->enquiry->title : 'Unknown Project';
            $enquiryNumber = $task->enquiry ? $task->enquiry->enquiry_number : 'N/A';

            CentralNotificationService::send(
                type: 'enquiry_task_ready',
                title: 'Task Ready to Start',
                message: "\"{$task->title}\" is ready to start for {$enquiryTitle} (#{$enquiryNumber}) — its prerequisites are complete.",
                module: 'projects',
                data: [
                    'task_id' => $task->id,
                    'enquiry_id' => $task->project_enquiry_id,
                    'enquiry_title' => $enquiryTitle,
                    'enquiry_number' => $enquiryNumber,
                    'task_type' => $task->type,
                    'department_id' => $task->department_id,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date?->toISOString(),
                    'url' => $this->taskWorkspaceUrl($task),
                ],
                users: [$user],
            );
        } catch (\Exception $e) {
            Log::error("Failed to send task-ready notification: " . $e->getMessage());
        }
    }

    /**
     * Notify whoever can review this logistics task (assigned users, plus a
     * role broadcast to Project Manager/Project Officer/Client Service/
     * Logistics — the same set TASK_VISIBILITY_MAPPING now grants task
     * access to) that a QR/public manifest submission is waiting on them.
     */
    public function sendManifestSubmissionReceived(EnquiryTask $task, int $itemCount, string $submittedByName): void
    {
        try {
            $task->loadMissing(['enquiry', 'assignedUsers']);

            $enquiryTitle = $task->enquiry ? $task->enquiry->title : 'Unknown Project';
            $enquiryNumber = $task->enquiry ? $task->enquiry->enquiry_number : 'N/A';
            $itemLabel = $itemCount === 1 ? '1 item' : "{$itemCount} items";

            $explicitUsers = collect([$task->assigned_user_id, $task->assigned_to])
                ->filter()
                ->merge($task->assignedUsers->pluck('id'))
                ->unique()
                ->map(fn ($id) => User::find($id))
                ->filter();

            CentralNotificationService::send(
                type: 'logistics_manifest_submission_received',
                title: 'Loading Sheet Items Submitted for Review',
                message: "{$submittedByName} submitted {$itemLabel} for {$enquiryTitle} (#{$enquiryNumber}) — waiting on your review.",
                module: 'projects',
                data: [
                    'task_id' => $task->id,
                    'enquiry_id' => $task->project_enquiry_id,
                    'enquiry_title' => $enquiryTitle,
                    'enquiry_number' => $enquiryNumber,
                    'submitted_by' => $submittedByName,
                    'item_count' => $itemCount,
                    'url' => $this->taskWorkspaceUrl($task),
                ],
                users: $explicitUsers->all(),
                role: ['Project Manager', 'Project Officer', 'Client Service', 'Logistics'],
            );
        } catch (\Exception $e) {
            Log::error("Failed to send manifest submission notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when Universal Task is completed
     */
    public function sendUniversalTaskCompleted(UniversalTask $task, User $completedBy): void
    {
        try {
            // Notify task creator
            if ($task->created_by && $task->created_by !== $completedBy->id) {
                $creator = User::find($task->created_by);
                if ($creator) {
                    $this->createCompletionNotification($task, $completedBy, $creator, 'universal');
                }
            }

            Log::info("Universal task completion notification sent", [
                'task_id' => $task->id,
                'completed_by' => $completedBy->id
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send universal task completion notification: " . $e->getMessage());
        }
    }

    /**
     * Create completion notification (private helper)
     */
    private function createCompletionNotification(
        $task,
        User $completedBy,
        User $recipient,
        string $taskType = 'enquiry'
    ): void {
        $isEnquiry = $taskType === 'enquiry';

        CentralNotificationService::send(
            type: $isEnquiry ? 'enquiry_task_completed' : 'universal_task_completed',
            title: 'Task Completed',
            message: "Task '{$task->title}' has been completed by {$completedBy->name}",
            module: $isEnquiry ? 'projects' : 'universal-task',
            data: [
                'task_id' => $task->id,
                'completed_by' => $completedBy->name,
                'completed_at' => now()->toISOString(),
            ] + ($isEnquiry ? ['enquiry_id' => $task->project_enquiry_id] : []),
            users: [$recipient],
        );
    }

    /**
     * ===================================
     * PRIORITY 2: PETTY CASH NOTIFICATIONS
     * ===================================
     */

    /**
     * Send notification when petty cash requisition is submitted
     */
    public function sendRequisitionSubmitted($requisition, User $submittedBy, User $approver): void
    {
        try {
            CentralNotificationService::send(
                type: 'pettycash_requisition_submitted',
                title: 'New Requisition for Approval',
                message: "Requisition #{$requisition->requisition_number} submitted by {$submittedBy->name} - Amount: KES " . number_format($requisition->total_amount, 2),
                module: 'finance',
                data: [
                    'requisition_id' => $requisition->id,
                    'requisition_number' => $requisition->requisition_number,
                    'total_amount' => $requisition->total_amount,
                    'purpose' => $requisition->purpose,
                    'submitted_by' => $submittedBy->name,
                    'submitter_id' => $submittedBy->id,
                    'department' => $requisition->department->name ?? 'Unknown',
                ],
                users: [$approver],
            );

            Log::info("Requisition submitted notification sent", [
                'requisition_id' => $requisition->id,
                'approver_id' => $approver->id
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send requisition submitted notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when petty cash requisition is approved
     */
    public function sendRequisitionApproved($requisition, User $approvedBy): void
    {
        try {
            // Notify the requester
            $requester = $requisition->requester ?? $requisition->creator;
            if ($requester) {
                CentralNotificationService::send(
                    type: 'pettycash_requisition_approved',
                    title: 'Requisition Approved',
                    message: "Your requisition #{$requisition->requisition_number} has been approved by {$approvedBy->name}",
                    module: 'finance',
                    data: [
                        'requisition_id' => $requisition->id,
                        'requisition_number' => $requisition->requisition_number,
                        'total_amount' => $requisition->total_amount,
                        'approved_by' => $approvedBy->name,
                        'approved_at' => now()->toISOString(),
                    ],
                    users: [$requester],
                );
            }

            Log::info("Requisition approved notification sent", [
                'requisition_id' => $requisition->id,
                'requester_id' => $requester->id ?? null
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send requisition approved notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when petty cash requisition is rejected
     */
    public function sendRequisitionRejected($requisition, User $rejectedBy, ?string $reason = null): void
    {
        try {
            // Notify the requester
            $requester = $requisition->requester ?? $requisition->creator;
            if ($requester) {
                $message = "Your requisition #{$requisition->requisition_number} has been rejected by {$rejectedBy->name}";
                if ($reason) {
                    $message .= " - Reason: {$reason}";
                }

                CentralNotificationService::send(
                    type: 'pettycash_requisition_rejected',
                    title: 'Requisition Rejected',
                    message: $message,
                    module: 'finance',
                    data: [
                        'requisition_id' => $requisition->id,
                        'requisition_number' => $requisition->requisition_number,
                        'total_amount' => $requisition->total_amount,
                        'rejected_by' => $rejectedBy->name,
                        'rejection_reason' => $reason,
                        'rejected_at' => now()->toISOString(),
                    ],
                    users: [$requester],
                );
            }

            Log::info("Requisition rejected notification sent", [
                'requisition_id' => $requisition->id,
                'requester_id' => $requester->id ?? null
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send requisition rejected notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when petty cash is disbursed
     */
    public function sendRequisitionDisbursed($requisition, User $disbursedBy): void
    {
        try {
            // Notify the requester
            $requester = $requisition->requester ?? $requisition->creator;
            if ($requester) {
                CentralNotificationService::send(
                    type: 'pettycash_requisition_disbursed',
                    title: 'Cash Disbursed',
                    message: "KES " . number_format($requisition->total_amount, 2) . " has been disbursed for requisition #{$requisition->requisition_number}",
                    module: 'finance',
                    data: [
                        'requisition_id' => $requisition->id,
                        'requisition_number' => $requisition->requisition_number,
                        'total_amount' => $requisition->total_amount,
                        'disbursed_by' => $disbursedBy->name,
                        'disbursed_at' => now()->toISOString(),
                    ],
                    users: [$requester],
                );
            }

            Log::info("Requisition disbursed notification sent", [
                'requisition_id' => $requisition->id,
                'requester_id' => $requester->id ?? null
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send requisition disbursed notification: " . $e->getMessage());
        }
    }

    /**
     * Send a notification to the acting user when they attempt to complete a project
     * but the required conditions are not met.
     *
     * Creates a persistent, actionable entry in the notification center so the user
     * can revisit what needs to happen even after dismissing the immediate API error.
     */
    public function sendProjectCompletionBlocked(
        ProjectEnquiry $enquiry,
        User $attemptedBy,
        array $readiness
    ): void {
        try {
            $enquiry->loadMissing(['client']);

            $inProgressTasks  = $readiness['in_progress_tasks'] ?? [];
            $pendingTasks     = $readiness['pending_tasks'] ?? [];
            $summary          = $readiness['task_summary'] ?? [];

            // Build a concise but specific message for the notification body
            $messageParts = [];
            if (!empty($inProgressTasks)) {
                $names = implode(', ', array_column($inProgressTasks, 'title'));
                $messageParts[] = "Tasks still in progress: {$names}";
            }
            if (!empty($pendingTasks)) {
                $names = implode(', ', array_column($pendingTasks, 'title'));
                $messageParts[] = "Tasks not started: {$names}";
            }

            $message = empty($messageParts)
                ? "All delivery-completion conditions are met. Use the Complete Project action."
                : implode(' | ', $messageParts);

            // Build per-task action steps for the front-end to render as a checklist
            $actionItems = [];
            foreach ($inProgressTasks as $task) {
                $actionItems[] = [
                    'task_id' => $task['id'],
                    'type'    => $task['type'],
                    'title'   => $task['title'],
                    'status'  => $task['status'],
                    'action'  => "Finish \"{$task['title']}\" before marking delivery complete.",
                    'severity' => 'warning',
                ];
            }
            foreach ($pendingTasks as $task) {
                $actionItems[] = [
                    'task_id' => $task['id'],
                    'type'    => $task['type'],
                    'title'   => $task['title'],
                    'status'  => $task['status'],
                    'action'  => "Start and finish \"{$task['title']}\" before marking delivery complete.",
                    'severity' => 'warning',
                ];
            }

            CentralNotificationService::send(
                type: 'project_completion_blocked',
                title: 'Project Cannot Be Completed Yet',
                message: "'{$enquiry->title}' — {$message}",
                module: 'projects',
                urgency: 'warning',
                data: [
                    'enquiry_id'     => $enquiry->id,
                    'enquiry_title'  => $enquiry->title,
                    'enquiry_number' => $enquiry->enquiry_number,
                    'job_number'     => $enquiry->job_number,
                    'client_name'    => $enquiry->client?->name ?? $enquiry->client?->full_name ?? 'Unknown Client',
                    'can_complete'   => false,
                    'task_summary'   => $summary,
                    'action_items'   => $actionItems,
                    'attempted_at'   => now()->toISOString(),
                    'url' => "/projects/enquiries/{$enquiry->id}",
                ],
                users: [$attemptedBy],
            );

            Log::info("Project completion blocked notification sent", [
                'enquiry_id'    => $enquiry->id,
                'user_id'       => $attemptedBy->id,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send project completion blocked notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when project deliverables are updated after a quote exists
     */
    public function sendDeliverablesUpdated(
        ProjectEnquiry $enquiry,
        User $updatedBy,
        array $newDeliverables
    ): void {
        try {
            // Resolve explicitly (rather than broadcasting by role) so the person
            // who made the change is never notified about their own edit.
            $financeUsers = User::whereHas('roles', function ($query) {
                $query->whereIn('name', ['Super Admin', 'Accountant', 'Finance', 'Costing', 'Finance Officer']);
            })->where('id', '!=', $updatedBy->id)->get();

            if ($financeUsers->isEmpty()) {
                return;
            }

            CentralNotificationService::send(
                type: 'deliverables_updated',
                title: 'Project Scope Updated',
                message: "The deliverables for '{$enquiry->title}' (#{$enquiry->enquiry_number}) have been updated by {$updatedBy->name}. A quote revision may be required.",
                module: 'projects',
                urgency: 'warning',
                data: [
                    'enquiry_id' => $enquiry->id,
                    'enquiry_title' => $enquiry->title,
                    'enquiry_number' => $enquiry->enquiry_number,
                    'job_number' => $enquiry->job_number,
                    'updated_by' => $updatedBy->name,
                    'deliverables_count' => count($newDeliverables),
                    'timestamp' => now()->toISOString(),
                    'url' => "/projects/enquiries/{$enquiry->id}",
                ],
                users: $financeUsers->all(),
            );

            Log::info("Deliverables update notification sent to Finance", [
                'enquiry_id' => $enquiry->id,
                'updated_by' => $updatedBy->id,
                'recipients_count' => $financeUsers->count(),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send deliverables update notification: " . $e->getMessage());
        }
    }

    private function taskWorkspaceUrl(EnquiryTask $task): string
    {
        return "/projects/tasks?enquiry_id={$task->project_enquiry_id}&highlight_task={$task->id}";
    }
}
