<?php

namespace App\Modules\ClientService\Http\Requests;

use App\Modules\ClientService\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rules for creating and updating a client.
 *
 * Both operations share one class because they differ in exactly one respect —
 * whether the email uniqueness check ignores the row being edited. Held apart
 * as two copies, the sixteen rules had already begun to drift.
 */
class ClientRequest extends FormRequest
{
    /** Route middleware already gates these endpoints on client.create / client.update. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A company is identified by its trading name and an individual by
            // their own, so each is required only for its own client type. The
            // controller then copies the company name into full_name, keeping
            // that rule in one place instead of in both the browser and here.
            'full_name' => ['required_if:customer_type,individual', 'nullable', 'string', 'max:255'],
            'company_name' => ['required_unless:customer_type,individual', 'nullable', 'string', 'max:255'],

            'contact_person' => ['nullable', 'string', 'max:255'],
            // `email:filter` keeps the permissive intent of the hand-rolled
            // regex this replaced, without accepting addresses PHP itself
            // rejects.
            'email' => [
                'required',
                'email:filter',
                'max:255',
                Rule::unique('clients', 'email')->ignore($this->route('client')),
            ],
            'phone' => ['required', 'string', 'max:20'],
            'alt_contact' => ['nullable', 'string', 'max:20'],
            'address' => ['required', 'string'],
            'city' => ['required', 'string', 'max:255'],
            'county' => ['required', 'string', 'max:255'],
            'postal_address' => ['nullable', 'string', 'max:255'],
            'customer_type' => ['required', Rule::in(Client::CUSTOMER_TYPES)],
            'lead_source' => ['required', 'string', 'max:255'],
            'preferred_contact' => ['required', Rule::in(Client::CONTACT_CHANNELS)],
            'industry' => ['nullable', 'string', 'max:255'],
            'registration_date' => ['required', 'date'],

            // `status` is deliberately absent. Active state is owned by the
            // toggle-status endpoint, which is the only path that keeps the
            // status and is_active columns in step; accepting it here opened a
            // second write path that set one without the other.
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required_if' => 'Enter the client\'s name.',
            'company_name.required_unless' => 'Enter the company or organisation name.',
        ];
    }
}
