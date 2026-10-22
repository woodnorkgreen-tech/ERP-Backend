<?php

namespace App\Modules\Design\Resources;

use App\Support\ProjectSetupSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DesignJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_enquiry_id' => $this->project_enquiry_id,
            'project_id' => $this->project_id,
            'client_id' => $this->client_id,
            'job_number' => $this->job_number,
            'title' => $this->title,
            'source_type' => $this->source_type,
            'sync_origin' => $this->sync_origin,
            'auto_synced_at' => $this->auto_synced_at,
            'status' => $this->status,
            'priority' => $this->priority,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'graphic_main_designer_id' => $this->graphic_main_designer_id,
            'structural_main_designer_id' => $this->structural_main_designer_id,
            'graphic_main_designer' => $this->whenLoaded('graphicMainDesigner', fn () => $this->graphicMainDesigner?->only(['id', 'name'])),
            'structural_main_designer' => $this->whenLoaded('structuralMainDesigner', fn () => $this->structuralMainDesigner?->only(['id', 'name'])),
            'project_setup_date' => ProjectSetupSchedule::dateFor($this->resource),
            'designers' => $this->whenLoaded('designers', fn () => $this->designers
                ->unique('id')->sortBy('name')->values()->map(fn ($designer) => [
                    'id' => $designer->id,
                    'name' => $designer->name,
                ])),
            'enquiry' => $this->whenLoaded('enquiry', fn () => new DesignEnquirySummaryResource($this->enquiry)),
            'project' => $this->whenLoaded('project'),
            'items' => DesignItemResource::collection($this->whenLoaded('items')),
            'active_work_seconds' => $this->whenLoaded('items', fn () => (int) $this->items->sum(fn ($item) =>
                $item->relationLoaded('workSessions') ? $item->workSessions->sum(fn ($session) =>
                    max(0, $session->started_at->diffInSeconds($session->ended_at ?? now(), false))) : 0)),
            'documents' => DesignDocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
