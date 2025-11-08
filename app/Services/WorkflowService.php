<?php

namespace App\Services;

use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Repositories\WorkflowRepository;
use Illuminate\Support\Facades\Notification;

class WorkflowService
{
    protected $workflowRepository;

    public function __construct(WorkflowRepository $workflowRepository)
    {
        $this->workflowRepository = $workflowRepository;
    }

    public function startWorkflow(string $entityType, int $entityId, int $templateId): WorkflowInstance
    {
        $instance = $this->workflowRepository->createInstance([
            'workflow_template_id' => $templateId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'started_at' => now(),
        ]);

        $template = $this->workflowRepository->findTemplate($templateId);

        foreach ($template->templateTasks as $templateTask) {
            $this->workflowRepository->createTask([
                'workflow_instance_id' => $instance->id,
                'workflow_template_task_id' => $templateTask->id,
                'due_date' => $templateTask->estimated_duration_days
                    ? now()->addDays($templateTask->estimated_duration_days)
                    : null,
            ]);
        }

        return $instance;
    }

    public function updateTaskStatus(WorkflowTask $task, string $status, ?string $notes = null): bool
    {
        $data = ['status' => $status];

        if ($notes) {
            $data['notes'] = $notes;
        }

        $updated = $this->workflowRepository->updateTaskStatus($task, $status);

        if ($updated) {
            // Check if all tasks are completed
            $this->checkWorkflowCompletion($task->workflowInstance);
        }

        return $updated;
    }

    public function assignTask(WorkflowTask $task, int $userId): bool
    {
        return $task->update(['assigned_user_id' => $userId]);
    }

    public function getOverdueTasks()
    {
        return $this->workflowRepository->getOverdueTasks();
    }

    public function getWorkflowInstances(string $entityType, int $entityId)
    {
        return $this->workflowRepository->getInstancesByEntity($entityType, $entityId);
    }

    private function checkWorkflowCompletion(WorkflowInstance $instance): void
    {
        $tasks = $this->workflowRepository->getTasksByInstance($instance->id);

        $allCompleted = $tasks->every(function ($task) {
            return $task->status === 'completed';
        });

        if ($allCompleted && $instance->status !== 'completed') {
            $instance->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }
    }
}
