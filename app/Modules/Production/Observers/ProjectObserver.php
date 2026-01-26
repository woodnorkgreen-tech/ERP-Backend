<?php

namespace App\Modules\Production\Observers;

use App\Models\Project;
use Illuminate\Support\Facades\Log;

class ProjectObserver
{
    /**
     * Handle the Project "created" event.
     */
    public function created(Project $project): void
    {
        Log::info('Project created', [
            'project_id' => $project->id,
            'project_id_number' => $project->project_id,
            'enquiry_id' => $project->enquiry_id,
            'status' => $project->status,
            'created_by' => auth()->id()
        ]);
    }

    /**
     * Handle the Project "updated" event.
     */
    public function updated(Project $project): void
    {
        $changes = $project->getDirty();
        
        foreach ($changes as $field => $newValue) {
            // Skip timestamps
            if (in_array($field, ['updated_at', 'created_at'])) {
                continue;
            }

            $oldValue = $project->getOriginal($field);
            
            // Log important changes
            if (in_array($field, ['status', 'current_phase', 'budget', 'start_date', 'end_date', 'assigned_users'])) {
                Log::info('Project updated', [
                    'project_id' => $project->id,
                    'field' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'updated_by' => auth()->id()
                ]);
            }
        }
    }

    /**
     * Handle the Project "deleted" event.
     */
    public function deleted(Project $project): void
    {
        Log::info('Project deleted', [
            'project_id' => $project->id,
            'project_id_number' => $project->project_id,
            'deleted_by' => auth()->id()
        ]);
    }

    /**
     * Handle the Project "restored" event.
     */
    public function restored(Project $project): void
    {
        Log::info('Project restored', [
            'project_id' => $project->id,
            'restored_by' => auth()->id()
        ]);
    }
}
