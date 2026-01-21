<?php

namespace App\Modules\Production\Observers;

use App\Models\Project;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Support\Facades\Log;

class ProjectObserver
{
    /**
     * Handle the Project "created" event.
     */
    public function created(Project $project): void
    {
        // Work orders are now only created when enquiries are created, not when projects are created
        // This prevents duplicate work orders when enquiries are converted to projects
        Log::info('ProjectObserver: Project created, but work order creation skipped (handled by ProjectEnquiryObserver)');
    }

    /**
     * Handle the Project "updated" event.
     */
    public function updated(Project $project): void
    {
        // Auto-sync work order data when project is updated
        $workOrder = WorkOrder::where('project_id', $project->id)->first();

        if ($workOrder) {
            // Determine work order status based on project status
            $newStatus = $this->determineWorkOrderStatus($project);
            
            $workOrder->update([
                'title' => $project->enquiry->title ?? $project->project_id ?? 'Project Work Order',
                'specifications' => $project->enquiry->description ?? null,
                'priority' => $this->mapPriority($project->enquiry->priority ?? 'medium'),
                'due_date' => $project->end_date ?? $project->enquiry->expected_delivery_date,
                'status' => $newStatus,
            ]);
            
            Log::info("ProjectObserver: Updated work order {$workOrder->id} status to {$newStatus} for project {$project->id}");
        }
    }

    /**
     * Determine work order status based on project status
     */
    private function determineWorkOrderStatus(Project $project): string
    {
        // If project exists, work order is active/approved
        if ($project) {
            // If project is completed, work order is completed
            if (in_array($project->status, ['completed', 'finished'])) {
                return 'completed';
            }
            // If project is active, work order is in progress/approved
            return 'in_progress';
        }
        
        // If no project, work order is pending
        return 'pending';
    }

    /**
     * Handle the Project "deleted" event.
     */
    public function deleted(Project $project): void
    {
        // Delete associated work order when project is deleted
        WorkOrder::where('project_id', $project->id)->delete();
    }

    /**
     * Map enquiry priority to work order priority
     */
    private function mapPriority(string $enquiryPriority): string
    {
        $map = [
            'low' => 'low',
            'medium' => 'medium',
            'high' => 'high',
            'urgent' => 'urgent',
        ];

        return $map[strtolower($enquiryPriority)] ?? 'medium';
    }
}
