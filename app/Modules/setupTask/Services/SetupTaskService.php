<?php

namespace App\Modules\setupTask\Services;

use App\Modules\setupTask\Models\SetupTask;
use App\Modules\setupTask\Models\SetupTaskPhoto;
use App\Modules\setupTask\Models\SetupTaskIssue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class SetupTaskService
{
    /**
     * Get setup data for a specific task
     */
    public function getSetupForTask(int $taskId): ?array
    {
        $setupTask = SetupTask::where('task_id', $taskId)
            ->with(['photos.uploader', 'issues.reporter', 'issues.assignee'])
            ->first();

        if (!$setupTask) {
            return null;
        }

        return [
            'id' => $setupTask->id,
            'task_id' => $setupTask->task_id,
            'documentation' => [
                'setup_notes' => $setupTask->setup_notes,
                'completion_notes' => $setupTask->completion_notes,
                'photos' => $setupTask->photos->map(function ($photo) {
                    return [
                        'id' => $photo->id,
                        'filename' => $photo->filename,
                        'original_filename' => $photo->original_filename,
                        'url' => $photo->url,
                        'description' => $photo->description,
                        'uploaded_by' => $photo->uploader->name ?? null,
                        'uploaded_at' => $photo->created_at->toISOString(),
                    ];
                }),
            ],
            'issues' => $setupTask->issues->map(function ($issue) {
                return [
                    'id' => $issue->id,
                    'title' => $issue->title,
                    'description' => $issue->description,
                    'category' => $issue->category,
                    'priority' => $issue->priority,
                    'status' => $issue->status,
                    'reported_by' => $issue->reporter->name ?? null,
                    'reported_at' => $issue->created_at->toISOString(),
                    'assigned_to' => $issue->assignee->name ?? null,
                    'resolved_at' => $issue->resolved_at?->toISOString(),
                    'resolution' => $issue->resolution,
                ];
            }),
        ];
    }

    /**
     * Save documentation notes
     */
    public function saveDocumentation(int $taskId, array $data): SetupTask
    {
        return DB::transaction(function () use ($taskId, $data) {
            $setupTask = SetupTask::firstOrCreate(
                ['task_id' => $taskId],
                [
                    'project_id' => $this->getProjectIdFromTask($taskId),
                    'created_by' => auth()->id(),
                ]
            );

            $setupTask->update([
                'setup_notes' => $data['setup_notes'] ?? $setupTask->setup_notes,
                'completion_notes' => $data['completion_notes'] ?? $setupTask->completion_notes,
                'updated_by' => auth()->id(),
            ]);

            return $setupTask->fresh(['photos', 'issues']);
        });
    }

    /**
     * Upload a photo
     */
    public function uploadPhoto(int $taskId, UploadedFile $file, ?string $description = null): SetupTaskPhoto
    {
        return DB::transaction(function () use ($taskId, $file, $description) {
            // Ensure setup task exists
            $setupTask = SetupTask::firstOrCreate(
                ['task_id' => $taskId],
                [
                    'project_id' => $this->getProjectIdFromTask($taskId),
                    'created_by' => auth()->id(),
                ]
            );

            // Store the file
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('setup_photos', $filename, 'public');

            // Create photo record
            $photo = SetupTaskPhoto::create([
                'setup_task_id' => $setupTask->id,
                'filename' => $filename,
                'original_filename' => $file->getClientOriginalName(),
                'path' => $path,
                'description' => $description,
                'uploaded_by' => auth()->id(),
            ]);

            return $photo->fresh(['uploader']);
        });
    }

    /**
     * Delete a photo
     */
    public function deletePhoto(int $taskId, int $photoId): bool
    {
        $photo = SetupTaskPhoto::whereHas('setupTask', function ($query) use ($taskId) {
            $query->where('task_id', $taskId);
        })->findOrFail($photoId);

        // Delete file from storage
        Storage::disk('public')->delete($photo->path);

        // Delete record
        return $photo->delete();
    }

    /**
     * Add an issue
     */
    public function addIssue(int $taskId, array $data): SetupTaskIssue
    {
        return DB::transaction(function () use ($taskId, $data) {
            // Ensure setup task exists
            $setupTask = SetupTask::firstOrCreate(
                ['task_id' => $taskId],
                [
                    'project_id' => $this->getProjectIdFromTask($taskId),
                    'created_by' => auth()->id(),
                ]
            );

            $issue = SetupTaskIssue::create([
                'setup_task_id' => $setupTask->id,
                'title' => $data['title'],
                'description' => $data['description'],
                'category' => $data['category'] ?? 'other',
                'priority' => $data['priority'] ?? 'medium',
                'status' => 'open',
                'reported_by' => auth()->id(),
                'assigned_to' => $data['assigned_to'] ?? null,
            ]);

            return $issue->fresh(['reporter', 'assignee']);
        });
    }

    /**
     * Update an issue
     */
    public function updateIssue(int $taskId, int $issueId, array $data): SetupTaskIssue
    {
        $issue = SetupTaskIssue::whereHas('setupTask', function ($query) use ($taskId) {
            $query->where('task_id', $taskId);
        })->findOrFail($issueId);

        $updateData = [];

        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
            
            // If marking as resolved, set resolved_at
            if ($data['status'] === 'resolved' && !$issue->resolved_at) {
                $updateData['resolved_at'] = now();
            } elseif ($data['status'] !== 'resolved' && $issue->resolved_at) {
                $updateData['resolved_at'] = null;
            }
        }

        if (isset($data['resolution'])) {
            $updateData['resolution'] = $data['resolution'];
        }

        if (isset($data['assigned_to'])) {
            $updateData['assigned_to'] = $data['assigned_to'];
        }

        $issue->update($updateData);

        return $issue->fresh(['reporter', 'assignee']);
    }

    /**
     * Delete an issue
     */
    public function deleteIssue(int $taskId, int $issueId): bool
    {
        $issue = SetupTaskIssue::whereHas('setupTask', function ($query) use ($taskId) {
            $query->where('task_id', $taskId);
        })->findOrFail($issueId);

        return $issue->delete();
    }

    /**
     * Helper: Get project ID from task
     */
    private function getProjectIdFromTask(int $taskId): ?int
    {
        $task = \App\Modules\Projects\Models\EnquiryTask::find($taskId);
        return $task?->project_enquiry_id;
    }
}
