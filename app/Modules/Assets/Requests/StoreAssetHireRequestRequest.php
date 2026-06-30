<?php

namespace App\Modules\Assets\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssetHireRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // type-specific authorization (assign = leads only) is checked in the controller
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'request_type' => ['required', 'in:hire,assign'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'for_user_id' => ['required', 'integer', 'exists:users,id'],
            'out_date' => ['required', 'date'],
            'expected_return_date' => ['nullable', 'date', 'after_or_equal:out_date', 'required_if:request_type,hire'],
            'purpose' => ['nullable', 'string', 'max:500'],
        ];
    }
}
