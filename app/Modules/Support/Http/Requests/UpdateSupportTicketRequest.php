<?php

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Models\SupportTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('ticket')) ?? false;
    }

    public function rules(): array
    {
        return [
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'status' => ['sometimes', Rule::in(SupportTicket::STATUSES)],
            'priority' => ['sometimes', Rule::in(SupportTicket::PRIORITIES)],
            'category' => ['sometimes', Rule::in(SupportTicket::CATEGORIES)],
            'resolution' => ['nullable', 'string', 'max:5000', 'required_if:status,resolved,closed'],
        ];
    }
}
