<?php

namespace App\Modules\MaterialsLibrary\Requests;

use App\Constants\Permissions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportMaterialRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::MATERIALS_LIBRARY_IMPORT) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'workstation_id' => 'nullable|integer|exists:workstations,id',
            'file' => 'required|file|mimes:xlsx,xls|max:5120', // Max 5MB
        ];
    }
}
