<?php

namespace App\Modules\Design\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DesignDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'design_job_id' => $this->design_job_id,
            'design_item_id' => $this->design_item_id,
            'document_type' => $this->document_type,
            'name' => $this->name,
            'original_name' => $this->original_name,
            'source' => $this->source ?? 'file',
            'external_url' => $this->external_url,
            'file_path' => $this->file_path,
            'file_size' => (int) $this->file_size,
            'mime_type' => $this->mime_type,
            'version' => (int) $this->version,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'uploaded_by' => $this->uploaded_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
