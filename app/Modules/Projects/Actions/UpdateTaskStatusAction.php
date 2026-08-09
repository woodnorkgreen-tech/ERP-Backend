<?php

namespace App\Modules\Projects\Actions;

use App\Events\EnquiryTaskCompleted;
use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\Projects\Services\EnquiryWorkflowService;
use App\Modules\Projects\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class UpdateTaskStatusAction
{
    protected $workflowService;
    protected $notificationService;

    public function __construct(
        EnquiryWorkflowService $workflowService,
        NotificationService $notificationService
    ) {
        $this->workflowService = $workflowService;
        $this->notificationService = $notificationService;
    }

    /**
     * Execute the action to update a task's status.
     *
     * @param int $taskId
     * @param string $status
     * @param string|null $notes
     * @return EnquiryTask
     * @throws \Exception
     */
    public function execute(int $taskId, string $status, ?string $notes = null): EnquiryTask
    {
        $user = Auth::user();
        Log::info("Executing UpdateTaskStatusAction for task {$taskId} with status: {$status}");

        $task = EnquiryTask::findOrFail($taskId);
        $oldStatus = $task->status;

        // Check if user is authorized to interact with this task (Pool check)
        if (!$task->isUserAuthorized($user)) {
            Log::warning("User {$user->id} attempted to interact with unauthorized task {$taskId}");
            throw new \Exception("Unauthorized: You can only interact with tasks in your pool.");
        }

        // 1. Delegate core transition logic to the Workflow Service
        // This ensures we maintain existing business rules and governance checks.
        $updatedTask = $this->workflowService->updateTaskStatus($taskId, $status, $user->id);

        // 2. Update optional notes
        if ($notes !== null) {
            $updatedTask->notes = $notes;
            $updatedTask->save();
        }

        // 3. Handle Notifications
        if ($status === 'completed' && $oldStatus !== 'completed') {
            $this->notificationService->sendEnquiryTaskCompleted($updatedTask, $user);

            // Announce the transition so downstream concerns can observe it
            // without this action having to know about them. The budget task
            // opening a project's cost account is the first such listener.
            EnquiryTaskCompleted::dispatch(
                $updatedTask->id,
                (string) $updatedTask->type,
                $updatedTask->project_enquiry_id,
                $user->id,
            );
        }


        return $updatedTask->load('enquiry', 'department', 'assignedUser');
    }
}
