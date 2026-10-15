<?php

namespace App\Modules\Printing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An upcoming project as design sees it, with each of its deliverables.
 * A deliverable is "assigned" when design has a task for it, otherwise "awaiting design".
 * Read-only for printing: planning only, no actions.
 */
class UpcomingProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $enquiry = $this->enquiry;
        $tasks = $this->items
            ->where('status', '!=', 'cancelled')
            ->groupBy('project_deliverable_id');

        return [
            'design_job_id' => $this->id,
            'project_enquiry_id' => $this->project_enquiry_id,
            'project_id' => $this->project_id,
            'project_setup_date' => $this->project_setup_date,
            'job_number' => $this->job_number,
            'project_name' => $enquiry?->title ?? $this->title,
            'client_name' => $enquiry?->client?->full_name ?? $this->client?->full_name,
            'design_status' => $this->status,
            'deliverables' => $enquiry
                ? $enquiry->deliverables->map(function ($deliverable) use ($tasks) {
                    $task = $tasks->get($deliverable->id)?->first();

                    return [
                        'deliverable_id' => $deliverable->id,
                        'name' => $deliverable->name,
                        'classification' => $deliverable->element_type,
                        'planning_status' => $task ? 'assigned' : 'awaiting_design',
                        'design_item' => $task ? [
                            'id' => $task->id,
                            'title' => $task->title,
                            'stream' => $task->stream,
                            'status' => $task->status,
                            'designer_name' => $task->assignedUser?->name,
                        ] : null,
                    ];
                })->values()
                : [],
        ];
    }
}
