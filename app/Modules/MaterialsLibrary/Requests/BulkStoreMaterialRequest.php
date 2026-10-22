<?php

namespace App\Modules\MaterialsLibrary\Requests;

use App\Constants\Permissions;
use App\Modules\MaterialsLibrary\Requests\Concerns\ValidatesMaterialControls;
use Illuminate\Foundation\Http\FormRequest;

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
        // Bulk mode is the same material form with repeated variant names.
        // Reuse the complete single-item contract so visible settings are never
        // silently dropped (units, conversions, controls, review status, etc.).
        $shared = (new StoreMaterialRequest())->rules();
        unset($shared['material_code']); // Each variant gets its own generated code.

        return array_merge($shared, [
            'material_name' => 'required|string|max:200',
            'variants' => 'required|array|min:1|max:100',
            'variants.*.label' => 'required|string|max:100|distinct:strict',
            // A variant may need its own price (a 3" nail costs more than a 1")
            // without needing its own category, unit or disposition.
            'variants.*.default_unit_cost' => 'nullable|numeric|min:0',
        ]);
    }

    public function messages(): array
    {
        return [
            'variants.*.label.distinct' => 'Two variants have the same label — each must be unique within this batch.',
        ];
    }
}
