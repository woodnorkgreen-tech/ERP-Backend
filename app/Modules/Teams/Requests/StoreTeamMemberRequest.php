<?php

namespace App\Modules\Teams\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Add authorization logic as needed
    }

    public function rules(): array
    {
        return [
            'member_name' => 'required|string|min:2|max:100|regex:/^[a-zA-Z\s\-.\']+$/',
            'member_email' => 'nullable|email|max:255',
            'member_phone' => 'nullable|string|max:20|regex:/^[\+]?[0-9\s\-()]+$/',
            'member_role' => 'nullable|string|max:100',
            'hourly_rate' => 'nullable|numeric|min:0',
            'is_lead' => 'boolean'
        ];
    }

    public function messages(): array
    {
        return [
            'member_name.required' => 'Team member name is required',
            'member_name.min' => 'Team member name must be at least 2 characters',
            'member_name.max' => 'Team member name cannot exceed 100 characters',
            'member_name.regex' => 'Team member name contains invalid characters',
            'member_email.email' => 'Please provide a valid email address',
            'member_email.max' => 'Email address cannot exceed 255 characters',
            'member_phone.regex' => 'Please provide a valid phone number',
            'member_phone.max' => 'Phone number cannot exceed 20 characters',
            'member_role.max' => 'Member role cannot exceed 100 characters',
            'hourly_rate.min' => 'Hourly rate cannot be negative',
            'is_lead.boolean' => 'Lead status must be true or false'
        ];
    }
}