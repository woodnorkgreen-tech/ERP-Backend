<?php

namespace App\Modules\MaterialsLibrary\Requests;

use App\Constants\Permissions;
use App\Modules\MaterialsLibrary\Requests\Concerns\ValidatesMaterialControls;
use App\Modules\MaterialsLibrary\Support\MaterialControl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One shared template (category, item type, unit, disposition...) applied to
 * many variant rows in a single save — nails at 1", 1.5", 2" are the same
 * catalogue decision made once, not the same form filled out four times.
 *
 * Every shared field here is a subset of StoreMaterialRequest's own rules, so
 * ValidatesMaterialControls (category/disposition/tracking-mode consistency)
 * applies unchanged to the shared template each variant will be built from.
 */
class BulkStoreMaterialRequest extends FormRequest
{
    use ValidatesMaterialControls;

    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::MATERIALS_LIBRARY_MANAGE) ?? false;
    }

    public function rules(): array
    {
        return [
            // The shared template. Each variant's full name is "{material_name} -
            // {variants.*.label}", so this is a base name, not a complete one.
            'material_name' => 'required|string|max:200',
            'material_category_id' => 'nullable|integer|exists:material_categories,id',
            'workstation_id' => 'nullable|exists:workstations,id',
            'item_type_id' => 'nullable|integer|exists:material_item_types,id',
            'brand_manufacturer' => 'nullable|string|max:150',
            'issue_disposition' => ['nullable', Rule::in(MaterialControl::DISPOSITIONS)],
            'tracking_mode' => ['nullable', Rule::in(MaterialControl::TRACKING_MODES)],
            'base_uom_id' => 'nullable|integer|exists:units_of_measure,id',
            'default_unit_cost' => 'nullable|numeric|min:0',
            'attributes' => 'nullable|array',
            'notes' => 'nullable|string',

            'variants' => 'required|array|min:1|max:100',
            'variants.*.label' => 'required|string|max:100|distinct:strict',
            // A variant may need its own price (a 3" nail costs more than a 1")
            // without needing its own category, unit or disposition.
            'variants.*.default_unit_cost' => 'nullable|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'variants.*.label.distinct' => 'Two variants have the same label — each must be unique within this batch.',
        ];
    }
}
