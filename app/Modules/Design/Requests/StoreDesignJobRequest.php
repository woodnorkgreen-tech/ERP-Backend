<?php

namespace App\Modules\Design\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDesignJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_enquiry_id' => 'nullable|integer|exists:project_enquiries,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'job_number' => 'nullable|string|max:100',
            'title' => 'required|string|max:255',
            'source_type' => 'nullable|in:project_scope,manual,revision,internal_concept',
            'status' => 'nullable|in:pending,in_design,done,cancelled',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'due_date' => 'nullable|date',
        ];
    }
}
