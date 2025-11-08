<?php

namespace App\Repositories;

use App\Models\WorkflowTemplate;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use Illuminate\Database\Eloquent\Collection;

class WorkflowRepository
{
    // Template methods
    public function getActiveTemplates(string $type): Collection
    {
        return WorkflowTemplate::where('type', $type)->where('is_active', true)->get();
    }

    public function findTemplate(int $id): ?WorkflowTemplate
    {
        return WorkflowTemplate::find($id);
    }

    public function createTemplate(array $data): WorkflowTemplate
    {
        return WorkflowTemplate::create($data);
    }

    // Instance methods
    public function findInstance(int $id): ?WorkflowInstance
    {
        return WorkflowInstance::find($id);
    }

    public function createInstance(array $data): WorkflowInstance
    {
        return WorkflowInstance::create($data);
    }

    public function getInstancesByEntity(string $entityType, int $entityId): Collection
    {
        return WorkflowInstance::where('entity_type', $entityType)
                              ->where('entity_id', $entityId)
                              ->get();
    }

    // Task methods
    public function findTask(int $id): ?WorkflowTask
    {
        return WorkflowTask::find($id);
    }

    public function createTask(array $data): WorkflowTask
    {
        return WorkflowTask::create($data);
    }

    public function getTasksByInstance(int $instanceId): Collection
    {
        return WorkflowTask::where('workflow_instance_id', $instanceId)->get();
    }

    public function getOverdueTasks(): Collection
    {
        return WorkflowTask::where('status', '!=', 'completed')
                          ->where('due_date', '<', now())
                          ->get();
    }

    public function updateTaskStatus(WorkflowTask $task, string $status): bool
    {
        $data = ['status' => $status];

        if ($status === 'in_progress' && !$task->started_at) {
            $data['started_at'] = now();
        } elseif ($status === 'completed' && !$task->completed_at) {
            $data['completed_at'] = now();
        }

        return $task->update($data);
    }
}
