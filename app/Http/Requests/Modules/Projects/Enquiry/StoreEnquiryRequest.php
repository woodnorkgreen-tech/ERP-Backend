<?php

namespace App\Http\Requests\Modules\Projects\Enquiry;

use Illuminate\Foundation\Http\FormRequest;

class StoreEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // We'll handle this in Policy
    }

    public function rules(): array
    {
        return [
            'date_received'           => 'required|date',
            'expected_delivery_date'  => 'required|date|after_or_equal:date_received',
            'client_id'               => 'required|exists:clients,id',
            'title'                   => 'required|string|max:255',
            'description'             => 'nullable|string',
            'project_scope'           => 'nullable',
            'priority'                => 'required|in:low,medium,high,urgent',
            'contact_person'          => 'required|string|max:255',
            'contact_email'           => 'nullable|email|max:255',
            'contact_phone'           => 'nullable|string|max:255',
            'status'                  => 'required|string',
            'department_id'           => 'nullable|exists:departments,id',
            'estimated_budget'        => 'nullable|numeric|min:0',
            'project_officer_id'      => 'nullable|exists:users,id',
            'venue'                   => 'nullable|string|max:255',
            'assigned_po'             => 'nullable|exists:users,id',
            'follow_up_notes'         => 'nullable|string',
            // Workflow fields — these determine internal vs external pipeline
            'selected_workflow_tasks' => 'nullable|array',
            'workflow_preset_type'    => 'nullable|string',
        ];
    }
}
