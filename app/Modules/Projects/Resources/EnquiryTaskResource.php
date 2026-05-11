<?php

namespace App\Modules\Projects\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EnquiryTaskResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'status' => $this->status,
            'priority' => $this->priority,
            'due_date' => $this->due_date,
            'notes' => $this->notes,
            'order' => $this->task_order,
            'phase' => $this->resolvePhase(),
            'is_gated' => (bool) $this->is_gated,
            'is_authorized' => (bool) $this->is_authorized,
            
            // Parent Relation
            'project_enquiry_id' => $this->project_enquiry_id,
            'enquiry' => [
                'id' => $this->enquiry?->id,
                'title' => $this->enquiry?->title,
                'enquiry_number' => $this->enquiry?->enquiry_number,
                'job_number' => $this->enquiry?->job_number,
            ],
            
            // Assignments
            'assigned_user' => [
                'id' => $this->assignedUser?->id,
                'name' => $this->assignedUser?->name,
                'department' => $this->assignedUser?->department?->name,
            ],
            'assigned_users' => $this->assignedUsers->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
            ]),
            
            'department' => [
                'id' => $this->department?->id,
                'name' => $this->department?->name,
            ],
            
            // Metadata
            'assignment_history_count' => $this->assignmentHistory->count(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function resolvePhase(): string
    {
        $templates = config('enquiry_workflow.task_templates', []);
        foreach ($templates as $template) {
            if ($template['type'] === $this->type) {
                return $template['phase'] ?? 'Other';
            }
        }
        return 'Other';
    }
}
