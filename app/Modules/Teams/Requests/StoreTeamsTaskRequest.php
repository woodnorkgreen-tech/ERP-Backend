<?php

namespace App\Modules\Teams\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTeamsTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Add authorization logic as needed
    }

    public function rules(): array
    {
        return [
            'category_id' => 'required|exists:team_categories,id',
            'team_type_id' => 'required|exists:team_types,id',
            'required_members' => 'required|integer|min:1|max:50',
            'start_date' => 'nullable|date|after_or_equal:today',
            'end_date' => 'nullable|date|after:start_date',
            'estimated_hours' => 'nullable|numeric|min:0.1',
            'notes' => 'nullable|string|max:1000',
            'special_requirements' => 'nullable|string|max:1000',
            'priority' => 'required|in:low,medium,high,urgent'
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Team category is required',
            'team_type_id.required' => 'Team type is required',
            'required_members.required' => 'Please specify the number of required members',
            'required_members.integer' => 'Required members must be a number',
            'required_members.min' => 'At least 1 member is required',
            'required_members.max' => 'Maximum 50 members allowed',
            'end_date.after' => 'End date must be after start date',
            'start_date.after_or_equal' => 'Start date cannot be in the past'
        ];
    }

    // Remove prepareForValidation since we no longer need to convert members string
}