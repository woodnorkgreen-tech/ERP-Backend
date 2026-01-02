<?php

namespace App\Modules\MaterialsLibrary\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportMaterialRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'workstation_id' => 'required|exists:workstations,id',
            'file' => 'required|file|mimes:xlsx,xls|max:5120', // Max 5MB
        ];
    }
}
