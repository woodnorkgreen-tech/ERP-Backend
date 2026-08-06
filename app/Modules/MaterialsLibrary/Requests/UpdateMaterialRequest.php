<?php

namespace App\Modules\MaterialsLibrary\Requests;

use App\Modules\MaterialsLibrary\Requests\Concerns\ValidatesMaterialControls;
use App\Modules\MaterialsLibrary\Support\MaterialControl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaterialRequest extends FormRequest
{
    use ValidatesMaterialControls;
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
            'material_name' => 'sometimes|required|string|max:255',
            'category' => 'nullable|string|max:100',
            'subcategory' => 'nullable|string|max:100',
            'material_type'       => 'nullable|in:consumable,reusable',
            'material_category_id'=> 'sometimes|required|integer|exists:material_categories,id',
            'item_status' => ['sometimes', Rule::in(MaterialControl::STATUSES)],
            'issue_disposition' => ['sometimes', Rule::in(MaterialControl::DISPOSITIONS)],
            'tracking_mode' => ['sometimes', Rule::in(MaterialControl::TRACKING_MODES)],
            'is_hazardous' => 'sometimes|boolean',
            'is_serialized' => 'sometimes|boolean',
            'is_batch_controlled' => 'sometimes|boolean',
            'is_expiry_controlled' => 'sometimes|boolean',
            'is_project_chargeable' => 'sometimes|boolean',
            'minimum_reusable_length_mm' => 'nullable|numeric|min:0',
            'minimum_reusable_width_mm' => 'nullable|numeric|min:0',
            'minimum_reusable_area_m2' => 'nullable|numeric|min:0',
            'unit_of_measure' => 'sometimes|string|max:50',
            'unit_cost' => 'nullable|numeric|min:0',
            'attributes' => 'nullable|array',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ];
    }
}
