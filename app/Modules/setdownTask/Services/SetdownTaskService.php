<?php

namespace App\Modules\setdownTask\Services;

use App\Modules\setdownTask\Models\SetdownTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class SetdownTaskService
{
    /**
     * Get setdown data for a specific task
     */
    public function getSetdownForTask(int $taskId): ?array
    {
        $setdownTask = SetdownTask::where('task_id', $taskId)->first();

        if (!$setdownTask) {
            return null;
        }

        $documentation = $setdownTask->documentation ?? [];
        $issues = $setdownTask->issues ?? [];

        return [
            'id' => $setdownTask->id,
            'task_id' => $setdownTask->task_id,
            'documentation' => [
                'setdown_notes' => $documentation['setdown_notes'] ?? null,
                'completion_notes' => $documentation['completion_notes'] ?? null,
                'photos' => $documentation['photos'] ?? [],
            ],
            'issues' => $issues,
        ];
    }

    /**
     * Save documentation notes
     */
    public function saveDocumentation(int $taskId, array $data): SetdownTask
    {
        return DB::transaction(function () use ($taskId, $data) {
            $setdownTask = SetdownTask::firstOrCreate(
                ['task_id' => $taskId],
                [
                    'project_id' => $this->getProjectIdFromTask($taskId),
                    'documentation' => [],
                    'issues' => [],
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                ]
            );

            $documentation = $setdownTask->documentation ?? [];
            $documentation['setdown_notes'] = $data['setdown_notes'] ?? ($documentation['setdown_notes'] ?? null);
            $documentation['completion_notes'] = $data['completion_notes'] ?? ($documentation['completion_notes'] ?? null);
            
            $setdownTask->documentation = $documentation;
            $setdownTask->save();

            return $setdownTask->fresh();
        });
    }

    /**
     * Upload a photo
     */
    public function uploadPhoto(int $taskId, UploadedFile $file, ?string $description = null): array
    {
        return DB::transaction(function () use ($taskId, $file, $description) {
            \Log::info('Service: uploadPhoto started', [
                'taskId' => $taskId,
                'fileName' => $file->getClientOriginalName(),
                'fileSize' => $file->getSize(),
                'description' => $description
            ]);

            // Get project ID
            $projectId = $this->getProjectIdFromTask($taskId);
            \Log::info('Service: Got project ID', ['projectId' => $projectId]);

            // Ensure setdown task exists
            \Log::info('Service: Creating/finding setdown task...');
            $setdownTask = SetdownTask::firstOrCreate(
                ['task_id' => $taskId],
                [
                    'project_id' => $projectId,
                    'documentation' => [],
                    'issues' => [],
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                ]
            );
            \Log::info('Service: Setdown task ready', ['setdown_task_id' => $setdownTask->id]);

            // Store the file
            \Log::info('Service: Storing file...');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('setdown_photos', $filename, 'public');
            \Log::info('Service: File stored', ['path' => $path]);

            // Create photo record
            $photo = [
                'id' => time(), // Use timestamp as ID for JSON storage
                'filename' => $filename,
                'original_filename' => $file->getClientOriginalName(),
                'path' => $path,
                'url' => '/system/storage/' . $path,
                'description' => $description,
                'uploaded_by' => auth()->user()->name ?? 'Unknown',
                'uploaded_at' => now()->toISOString(),
            ];

            \Log::info('Service: Adding photo to documentation...');
            // Add photo to documentation
            $documentation = $setdownTask->documentation ?? [];
            $photos = $documentation['photos'] ?? [];
            $photos[] = $photo;
            $documentation['photos'] = $photos;
            
            $setdownTask->documentation = $documentation;
            $setdownTask->save();

            \Log::info('Service: Photo upload complete!', ['photo_id' => $photo['id']]);
            return $photo;
        });
    }

    /**
     * Delete a photo
     */
    public function deletePhoto(int $taskId, int $photoId): bool
    {
        $setdownTask = SetdownTask::where('task_id', $taskId)->firstOrFail();
        
        $documentation = $setdownTask->documentation ?? [];
        $photos = $documentation['photos'] ?? [];
        
        // Find and remove the photo
        $photoIndex = array_search($photoId, array_column($photos, 'id'));
        
        if ($photoIndex !== false) {
            // Delete file from storage
            if (isset($photos[$photoIndex]['path'])) {
                Storage::disk('public')->delete($photos[$photoIndex]['path']);
            }
            
            // Remove from array
            array_splice($photos, $photoIndex, 1);
            $documentation['photos'] = array_values($photos); // Re-index
            
            $setdownTask->documentation = $documentation;
            $setdownTask->save();
            
            return true;
        }
        
        return false;
    }

    /**
     * Add an issue
     */
    public function addIssue(int $taskId, array $data): array
    {
        return DB::transaction(function () use ($taskId, $data) {
            // Ensure setdown task exists
            $setdownTask = SetdownTask::firstOrCreate(
                ['task_id' => $taskId],
                [
                    'project_id' => $this->getProjectIdFromTask($taskId),
                    'documentation' => [],
                    'issues' => [],
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                ]
            );

            $issue = [
                'id' => time(), // Use timestamp as ID
                'title' => $data['title'],
                'description' => $data['description'],
                'category' => $data['category'] ?? 'other',
                'priority' => $data['priority'] ?? 'medium',
                'status' => 'open',
                'reported_by' => auth()->user()->name ?? 'Unknown',
                'reported_at' => now()->toISOString(),
                'assigned_to' => $data['assigned_to'] ?? null,
                'resolved_at' => null,
                'resolution' => null,
            ];

            $issues = $setdownTask->issues ?? [];
            $issues[] = $issue;
            
            $setdownTask->issues = $issues;
            $setdownTask->save();

            return $issue;
        });
    }

    /**
     * Update an issue
     */
    public function updateIssue(int $taskId, int $issueId, array $data): array
    {
        $setdownTask = SetdownTask::where('task_id', $taskId)->firstOrFail();
        
        $issues = $setdownTask->issues ?? [];
        $issueIndex = array_search($issueId, array_column($issues, 'id'));
        
        if ($issueIndex === false) {
            throw new \Exception('Issue not found');
        }

        $issue = &$issues[$issueIndex];

        if (isset($data['status'])) {
            $issue['status'] = $data['status'];
            
            // If marking as resolved, set resolved_at
            if ($data['status'] === 'resolved' && !isset($issue['resolved_at'])) {
                $issue['resolved_at'] = now()->toISOString();
            } elseif ($data['status'] !== 'resolved' && isset($issue['resolved_at'])) {
                $issue['resolved_at'] = null;
            }
        }

        if (isset($data['resolution'])) {
            $issue['resolution'] = $data['resolution'];
        }

        if (isset($data['assigned_to'])) {
            $issue['assigned_to'] = $data['assigned_to'];
        }

        $setdownTask->issues = $issues;
        $setdownTask->save();

        return $issue;
    }

    /**
     * Delete an issue
     */
    public function deleteIssue(int $taskId, int $issueId): bool
    {
        $setdownTask = SetdownTask::where('task_id', $taskId)->firstOrFail();
        
        $issues = $setdownTask->issues ?? [];
        $issueIndex = array_search($issueId, array_column($issues, 'id'));
        
        if ($issueIndex !== false) {
            array_splice($issues, $issueIndex, 1);
            $setdownTask->issues = array_values($issues); // Re-index
            $setdownTask->save();
            return true;
        }
        
        return false;
    }

    /**
     * Helper: Get project ID from task
     */
    private function getProjectIdFromTask(int $taskId): ?int
    {
        $task = \App\Modules\Projects\Models\EnquiryTask::find($taskId);
        return $task?->project_enquiry_id;
    }

    /**
     * Get or create checklist for a setdown task
     */
    public function getOrCreateChecklist(int $taskId): array
    {
        $setdownTask = SetdownTask::firstOrCreate(
            ['task_id' => $taskId],
            [
                'project_id' => $this->getProjectIdFromTask($taskId),
                'documentation' => [],
                'issues' => [],
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]
        );

        $checklist = \App\Modules\setdownTask\Models\SetdownChecklist::firstOrCreate(
            ['setdown_task_id' => $setdownTask->id],
            [
                'checklist_data' => \App\Modules\setdownTask\Models\SetdownChecklist::getDefaultChecklistData(),
                'completed_count' => 0,
                'total_count' => 14,
                'completion_percentage' => 0,
                'created_by' => auth()->id()
            ]
        );

        return [
            'id' => $checklist->id,
            'setdown_task_id' => $checklist->setdown_task_id,
            'checklist_data' => $checklist->checklist_data,
            'completed_count' => $checklist->completed_count,
            'total_count' => $checklist->total_count,
            'completion_percentage' => $checklist->completion_percentage,
            'completed_at' => $checklist->completed_at
        ];
    }

    /**
     * Update a checklist item's completion status
     */
    public function updateChecklistItem(int $taskId, int $itemId, bool $completed): array
    {
        $setdownTask = SetdownTask::where('task_id', $taskId)->firstOrFail();
        $checklist = \App\Modules\setdownTask\Models\SetdownChecklist::where('setdown_task_id', $setdownTask->id)->firstOrFail();

        $checklist->updateItem($itemId, $completed);
        $checklist->updated_by = auth()->id();
        $checklist->save();

        return [
            'id' => $checklist->id,
            'checklist_data' => $checklist->checklist_data,
            'completed_count' => $checklist->completed_count,
            'total_count' => $checklist->total_count,
            'completion_percentage' => $checklist->completion_percentage,
            'completed_at' => $checklist->completed_at
        ];
    }
}
