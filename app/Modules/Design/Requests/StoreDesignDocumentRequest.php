<?php

namespace App\Modules\Design\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDesignDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'design_job_id' => 'nullable|integer|exists:design_jobs,id|required_without:design_item_id',
            'design_item_id' => 'nullable|integer|exists:design_items,id|required_without:design_job_id',
            'document_type' => 'nullable|string|max:80',
            'name' => 'nullable|string|max:255',
            'file' => 'required|file|max:51200',
        ];
    }
}
