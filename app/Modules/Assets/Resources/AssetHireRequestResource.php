<?php

namespace App\Modules\Assets\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetHireRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_type' => $this->request_type,
            'status' => $this->status,

            'asset' => $this->whenLoaded('asset', function () {
                return [
                    'id' => $this->asset->id,
                    'asset_code' => $this->asset->asset_code,
                    'name' => $this->asset->name,
                    'image_url' => $this->asset->image_url,
                    'category' => $this->asset->assetCategory?->name ?? $this->asset->category,
                ];
            }),

            'project' => $this->whenLoaded('project', function () {
                if (!$this->project) return null;
                return [
                    'id' => $this->project->id,
                    'title' => $this->project->enquiry?->title ?? "Project #{$this->project->project_id}",
                    'job_number' => $this->project->enquiry?->job_number,
                ];
            }),

            'requested_by' => $this->whenLoaded('requestedBy', fn () => [
                'id' => $this->requestedBy?->id,
                'name' => $this->requestedBy?->name,
            ]),
            'for_user' => $this->whenLoaded('forUser', fn () => [
                'id' => $this->forUser?->id,
                'name' => $this->forUser?->name,
            ]),

            'out_date' => $this->out_date?->format('Y-m-d'),
            'expected_return_date' => $this->expected_return_date?->format('Y-m-d'),
            'actual_return_date' => $this->actual_return_date?->format('Y-m-d'),
            'return_condition' => $this->return_condition,
            'purpose' => $this->purpose,

            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy ? [
                'id' => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
            ] : null),
            'approved_at' => $this->approved_at,

            'rejected_by' => $this->whenLoaded('rejectedBy', fn () => $this->rejectedBy ? [
                'id' => $this->rejectedBy->id,
                'name' => $this->rejectedBy->name,
            ] : null),
            'rejected_at' => $this->rejected_at,
            'rejection_reason' => $this->rejection_reason,

            'returned_by' => $this->whenLoaded('returnedBy', fn () => $this->returnedBy ? [
                'id' => $this->returnedBy->id,
                'name' => $this->returnedBy->name,
            ] : null),

            'can_approve' => $this->when(isset($this->can_approve), $this->can_approve),

            'created_at' => $this->created_at,
        ];
    }
}
