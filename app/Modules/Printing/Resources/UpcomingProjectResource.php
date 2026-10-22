<?php

namespace App\Modules\Printing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An upcoming project as design sees it, with each of its deliverables.
 * A deliverable is "assigned" when Graphic Design has required work for it.
 * Read-only for printing: planning only, no actions.
 */
class UpcomingProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $enquiry = $this->enquiry;
        // A deliverable can have multiple Graphic tasks, so list each required one.
        $tasks = $this->items
            ->where('stream', 'graphic')
            ->whereNotIn('status', ['cancelled', 'not_required'])
            ->sortBy('id')
            ->groupBy('project_deliverable_id');
        $notRequired = $this->items->where('stream', 'graphic')->where('status', 'not_required')->groupBy('project_deliverable_id');

        return [
            'design_job_id' => $this->id,
            'project_enquiry_id' => $this->project_enquiry_id,
            'project_id' => $this->project_id,
            'project_setup_date' => $this->project_setup_date,
            'printing_date' => $this->printingSchedule?->printing_date?->format('Y-m-d'),
            'machine_asset_id' => $this->printingSchedule?->machine_asset_id,
            'machine_name' => $this->printingSchedule?->machine?->name,
            'operator_id' => $this->printingSchedule?->operator_id,
            'operator_name' => $this->printingSchedule?->operator?->name,
            'job_number' => $this->job_number,
            'project_name' => $enquiry?->title ?? $this->title,
            'client_name' => $enquiry?->client?->full_name ?? $this->client?->full_name,
            'design_status' => $this->status,
            'deliverables' => $enquiry
                ? $enquiry->deliverables->map(function ($deliverable) use ($tasks, $notRequired) {
                    $deliverableTasks = $tasks->get($deliverable->id) ?? collect();
                    $planningStatus = $deliverableTasks->isNotEmpty() ? 'assigned'
                        : (($notRequired->get($deliverable->id) ?? collect())->isNotEmpty() ? 'not_required' : 'awaiting_design');

                    return [
                        'deliverable_id' => $deliverable->id,
                        'name' => $deliverable->name,
                        'classification' => $deliverable->element_type,
                        'planning_status' => $planningStatus,
                        'design_items' => $deliverableTasks->map(fn ($task) => [
                            'id' => $task->id,
                            'title' => $task->title,
                            'stream' => $task->stream,
                            'status' => $task->status,
                            'designer_name' => $task->assignedUser?->name,
                        ])->values(),
                    ];
                })->values()
                : [],
        ];
    }
}
