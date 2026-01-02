<?php

namespace App\Modules\MaterialsLibrary\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaterialRequest extends FormRequest
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
            'material_code' => 'required|string|max:100|unique:library_materials,material_code',
            'material_name' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'subcategory' => 'nullable|string|max:100',
            'unit_of_measure' => 'required|string|max:50',
            'unit_cost' => 'nullable|numeric|min:0',
            'attributes' => 'nullable|array',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ];
    }
}
