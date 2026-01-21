<?php

namespace App\Modules\Production\Observers;

use App\Models\ProjectEnquiry;
use App\Modules\Production\Models\WorkOrder;
use App\Models\Project;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ProjectEnquiryObserver
{
    /**
     * Handle the ProjectEnquiry "created" event.
     */
    public function created(ProjectEnquiry $enquiry): void
    {
        // Auto-create a work order when an enquiry is created
        try {
            Log::info('ProjectEnquiryObserver: Creating work order for enquiry ' . $enquiry->id);
            
            $workOrderNumber = $this->generateWorkOrderNumber($enquiry);

            WorkOrder::create([
                'work_order_number' => $workOrderNumber,
                'project_enquiry_id' => $enquiry->id,
                'title' => $enquiry->title,
                'specifications' => $enquiry->description,
                'quantity' => 1, // Default quantity
                'status' => 'pending',
                'priority' => $this->mapPriority($enquiry->priority ?? 'medium'),
                'due_date' => $enquiry->expected_delivery_date,
                'assigned_to' => $enquiry->project_officer_id,
                'created_by' => $enquiry->created_by,
            ]);

            Log::info('ProjectEnquiryObserver: Work order created successfully - ' . $workOrderNumber);
        } catch (\Exception $e) {
            Log::error('ProjectEnquiryObserver: Failed to create work order for enquiry ' . $enquiry->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Handle the ProjectEnquiry "updated" event.
     */
    public function updated(ProjectEnquiry $enquiry): void
    {
        // Auto-sync work order status when enquiry is updated
        $workOrder = WorkOrder::where('project_enquiry_id', $enquiry->id)->first();

        if ($workOrder) {
            // Determine work order status based on enquiry status
            $newStatus = $this->determineWorkOrderStatusFromEnquiry($enquiry);
            
            $workOrder->update([
                'title' => $enquiry->title,
                'specifications' => $enquiry->description,
                'priority' => $this->mapPriority($enquiry->priority ?? 'medium'),
                'due_date' => $enquiry->expected_delivery_date,
                'status' => $newStatus,
                'assigned_to' => $enquiry->project_officer_id,
            ]);
            
            Log::info("ProjectEnquiryObserver: Updated work order {$workOrder->id} status to {$newStatus} for enquiry {$enquiry->id}");
        }

        // Check if a project exists for this enquiry and update/create work order if needed
        $project = Project::where('enquiry_id', $enquiry->id)->first();
        if ($project) {
            $projectWorkOrder = WorkOrder::where('project_id', $project->id)->first();
            if (!$projectWorkOrder) {
                // No work order exists for project, create one
                $this->createWorkOrderForProject($project);
            } else {
                // Work order exists for project, update it to active status
                $projectWorkOrder->update([
                    'status' => 'in_progress', // Active/Approved
                    'project_id' => $project->id,
                ]);
                Log::info("ProjectEnquiryObserver: Updated existing work order {$projectWorkOrder->id} to in_progress for project {$project->id}");
            }
        } else {
            // No project exists, ensure work order status matches enquiry status
            $workOrder = WorkOrder::where('project_enquiry_id', $enquiry->id)->first();
            if ($workOrder) {
                $newStatus = $this->determineWorkOrderStatusFromEnquiry($enquiry);
                $workOrder->update(['status' => $newStatus]);
                Log::info("ProjectEnquiryObserver: Updated work order {$workOrder->id} status to {$newStatus} for enquiry {$enquiry->id}");
            }
        }
    }

    /**
     * Handle the ProjectEnquiry "deleted" event.
     */
    public function deleted(ProjectEnquiry $enquiry): void
    {
        // Delete associated work order when enquiry is deleted
        WorkOrder::where('project_enquiry_id', $enquiry->id)->delete();
    }

    /**
     * Create a work order for a project
     */
    private function createWorkOrderForProject(Project $project): void
    {
        $workOrderNumber = $this->generateWorkOrderNumber();

        WorkOrder::create([
            'work_order_number' => $workOrderNumber,
            'project_id' => $project->id,
            'project_enquiry_id' => $project->enquiry_id,
            'title' => $project->enquiry->title ?? $project->project_id ?? 'Project Work Order',
            'specifications' => $project->enquiry->description ?? null,
            'quantity' => 1,
            'status' => 'pending',
            'priority' => $this->mapPriority($project->enquiry->priority ?? 'medium'),
            'due_date' => $project->end_date ?? $project->enquiry->expected_delivery_date,
            'created_by' => $project->enquiry->created_by ?? 1,
        ]);
    }

    /**
     * Generate a unique work order number
     */
    private function generateWorkOrderNumber(ProjectEnquiry $enquiry = null): string
    {
        $year = date('Y');
        $month = date('m');
        $sequence = WorkOrder::whereYear('created_at', $year)->whereMonth('created_at', $month)->count() + 1;
        
        return sprintf('WO-%s%s-%03d', $year, $month, $sequence);
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
