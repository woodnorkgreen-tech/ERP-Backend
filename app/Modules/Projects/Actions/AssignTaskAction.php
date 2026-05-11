<?php

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\Projects\Services\EnquiryWorkflowService;
use App\Modules\Projects\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AssignTaskAction
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
     * Execute the action to assign a task.
     *
     * @param int $taskId
     * @param int $assignedUserId
     * @param array $assignmentData
     * @return EnquiryTask
     * @throws \Exception
     */
    public function execute(int $taskId, int $assignedUserId, array $assignmentData = []): EnquiryTask
    {
        $user = Auth::user();
        Log::info("Executing AssignTaskAction for task {$taskId} to user {$assignedUserId}");

        // 1. Delegate to workflow service for core assignment logic (legacy sync, etc.)
        $task = $this->workflowService->assignEnquiryTask($taskId, $assignedUserId, $user->id, $assignmentData);

        // 2. Handle Notifications
        $assignedUser = \App\Models\User::findOrFail($assignedUserId);
        $this->notificationService->sendEnquiryTaskAssignment($task, $assignedUser, $user, false);

        return $task->load('department', 'assignedBy', 'assignedTo', 'assignmentHistory', 'assignedUser', 'assignedUsers');
    }
}
