<?php

namespace App\Modules\MaterialsLibrary\Requests;

use App\Modules\MaterialsLibrary\Requests\Concerns\ValidatesMaterialControls;
use App\Modules\MaterialsLibrary\Support\MaterialControl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialRequest extends FormRequest
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
        return [
            'workstation_id' => 'required|exists:workstations,id',
            'item_type_id' => 'required|integer|exists:material_item_types,id',
            'material_code' => 'required|string|max:100|unique:library_materials,material_code',
            'material_name' => 'required|string|max:255',
            'brand_manufacturer' => 'nullable|string|max:150',
            'manufacturer_part_number' => 'nullable|string|max:150',
            'alternative_item_name' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:100',
            'subcategory' => 'nullable|string|max:100',
            'material_type'       => 'nullable|in:consumable,reusable',
            'material_category_id'=> 'required|integer|exists:material_categories,id',
            'item_status' => ['sometimes', Rule::in(MaterialControl::STATUSES)],
            'issue_disposition' => ['required', Rule::in(MaterialControl::DISPOSITIONS)],
            'tracking_mode' => ['required', Rule::in(MaterialControl::TRACKING_MODES)],
            'is_hazardous' => 'sometimes|boolean',
            'is_serialized' => 'sometimes|boolean',
            'is_batch_controlled' => 'sometimes|boolean',
            'is_expiry_controlled' => 'sometimes|boolean',
            'is_project_chargeable' => 'sometimes|boolean',
            'minimum_reusable_length_mm' => 'nullable|numeric|min:0',
            'minimum_reusable_width_mm' => 'nullable|numeric|min:0',
            'minimum_reusable_area_m2' => 'nullable|numeric|min:0',
            'unit_of_measure' => 'required|string|max:50',
            'base_uom_id' => 'required|integer|exists:units_of_measure,id',
            'purchase_uom_id' => 'nullable|integer|exists:units_of_measure,id',
            'issue_uom_id' => 'required|integer|exists:units_of_measure,id',
            'valuation_method' => 'sometimes|in:FIFO,Landed Cost,Weighted Average',
            'revision_version' => 'sometimes|string|max:20',
            'effective_date' => 'nullable|date',
            'attributes' => 'nullable|array',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ];
    }
}
