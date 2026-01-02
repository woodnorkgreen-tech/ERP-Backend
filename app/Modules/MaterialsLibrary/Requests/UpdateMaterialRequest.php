<?php

namespace App\Modules\MaterialsLibrary\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaterialRequest extends FormRequest
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
        $materialId = $this->route('material'); 

        return [
            'workstation_id' => 'sometimes|exists:workstations,id',
            'material_code' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('library_materials', 'material_code')->ignore($materialId),
            ],
            'material_name' => 'sometimes|string|max:255',
            'category' => 'nullable|string|max:100',
            'subcategory' => 'nullable|string|max:100',
            'unit_of_measure' => 'sometimes|string|max:50',
            'unit_cost' => 'nullable|numeric|min:0',
            'attributes' => 'nullable|array',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ];
    }
}
