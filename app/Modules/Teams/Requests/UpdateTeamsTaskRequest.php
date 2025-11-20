<?php

namespace App\Modules\Teams\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamsTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Add authorization logic as needed
    }

    public function rules(): array
    {
        return [
            'category_id' => 'sometimes|exists:team_categories,id',
            'team_type_id' => 'sometimes|exists:team_types,id',
            'status' => 'sometimes|in:pending,assigned,in_progress,completed,cancelled',
            'required_members' => 'sometimes|integer|min:1|max:50',
            'assigned_members_count' => 'sometimes|integer|min:0',
            'max_members' => 'nullable|integer|min:1|max:50',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
            'estimated_hours' => 'nullable|numeric|min:0.1',
            'actual_hours' => 'nullable|numeric|min:0.1',
            'notes' => 'nullable|string|max:1000',
            'special_requirements' => 'nullable|string|max:1000',
            'priority' => 'sometimes|in:low,medium,high,urgent',
            'completed_at' => 'nullable|date'
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'The selected team category is invalid',
            'team_type_id.exists' => 'The selected team type is invalid',
            'status.in' => 'Invalid team status',
            'required_members.min' => 'At least 1 team member is required',
            'end_date.after' => 'End date must be after start date',
            'priority.in' => 'Invalid priority level'
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Custom validation: max_members must be >= required_members
            if ($this->max_members && $this->required_members && $this->max_members < $this->required_members) {
                $validator->errors()->add('max_members', 'Maximum members must be greater than or equal to required members');
            }

            // Custom validation: if status is completed, ensure we have assigned members
            if ($this->status === 'completed' && $this->assigned_members_count < $this->required_members) {
                $validator->errors()->add('status', 'Cannot mark as completed until all required members are assigned');
            }
        });
    }
}