<?php

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Models\SupportTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupportTicketRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('create', SupportTicket::class) ?? false; }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'min:5', 'max:160'],
            'description' => ['required', 'string', 'min:20', 'max:10000'],
            'type' => ['required', Rule::in(SupportTicket::TYPES)],
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt,csv'],
        ];
    }
}
