<?php

namespace App\Modules\MaterialsLibrary\Requests\Concerns;

use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Support\MaterialControl;
use Illuminate\Validation\Validator;

trait ValidatesMaterialControls
{
    public function after(): array
    {
        return [function (Validator $validator): void {
            $disposition = $this->input('issue_disposition');
            $tracking = $this->input('tracking_mode');
            if ($disposition && $tracking && !MaterialControl::compatible($disposition, $tracking)) {
                $validator->errors()->add('tracking_mode', "Tracking mode [{$tracking}] is not compatible with [{$disposition}].");
            }

            if ($this->boolean('is_serialized') && $tracking !== 'serialized_item') {
                $validator->errors()->add('is_serialized', 'Serialized items must use serialized_item tracking.');
            }
            if ($this->boolean('is_expiry_controlled') && !$this->boolean('is_batch_controlled')) {
                $validator->errors()->add('is_expiry_controlled', 'Expiry-controlled items must also be batch controlled.');
            }
            if ($disposition === 'recoverable_remainder' && $tracking !== 'dimension_piece') {
                $validator->errors()->add('tracking_mode', 'Recoverable remainder items must use dimension_piece tracking.');
            }

            $categoryId = $this->input('material_category_id');
            if ($categoryId) {
                $category = MaterialCategory::find($categoryId);
                if ($category && (!$category->is_selectable || $category->children()->active()->exists())) {
                    $validator->errors()->add('material_category_id', 'Select a leaf category, not a category group.');
                }
                if ($category?->allowed_uoms && !in_array($this->input('unit_of_measure'), $category->allowed_uoms, true)) {
                    $validator->errors()->add('unit_of_measure', 'The unit of measure is not allowed for this category.');
                }
                if ($category?->item_type_id && $this->input('item_type_id')
                    && (int) $category->item_type_id !== (int) $this->input('item_type_id')) {
                    $validator->errors()->add('item_type_id', 'The selected item type does not own this category.');
                }
            }
        }];
    }
}
