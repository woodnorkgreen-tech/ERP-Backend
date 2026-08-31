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
            if ($disposition === 'recoverable_remainder'
                && !$this->filled('minimum_reusable_area_m2')
                && (!$this->filled('minimum_reusable_length_mm') || !$this->filled('minimum_reusable_width_mm'))) {
                $validator->errors()->add('minimum_reusable_area_m2', 'Define a minimum recoverable area or both minimum length and width.');
            }

            $categoryId = $this->input('material_category_id');
            if (! $categoryId && $this->route('material')) {
                $categoryId = \App\Modules\MaterialsLibrary\Models\LibraryMaterial::whereKey($this->route('material'))
                    ->value('material_category_id');
            }
            if ($categoryId) {
                $category = MaterialCategory::with('parent')->find($categoryId);
                if ($category && (!$category->is_selectable || $category->children()->active()->exists())) {
                    $validator->errors()->add('material_category_id', 'Select a leaf category, not a category group.');
                }
                // Only judge a unit that was actually supplied. A draft may leave
                // it blank for the category default to settle later.
                if ($category?->allowed_uoms && filled($this->input('unit_of_measure'))
                    && !in_array($this->input('unit_of_measure'), $category->allowed_uoms, true)) {
                    $validator->errors()->add('unit_of_measure', 'The unit of measure is not allowed for this category.');
                }
                if ($category?->item_type_id && $this->input('item_type_id')
                    && (int) $category->item_type_id !== (int) $this->input('item_type_id')) {
                    $validator->errors()->add('item_type_id', 'The selected item type does not own this category.');
                }

                // Attribute definitions are an extensible schema, not an escape
                // from validation. Drafts may omit required values, but values
                // that are supplied must always match their declared type and
                // allowed choices so future categories remain dependable.
                $attributes = $this->input('attributes', []);
                $attributes = is_array($attributes['attributes'] ?? null)
                    ? $attributes['attributes']
                    : (is_array($attributes) ? $attributes : []);

                foreach ($category?->resolvedAttributeSchema() ?? [] as $field) {
                    $key = $field['key'];
                    $value = $attributes[$key] ?? null;
                    if (blank($value)) {
                        if ($this->input('item_status') === 'Active' && ($field['required'] ?? false)) {
                            $validator->errors()->add("attributes.{$key}", "{$field['label']} is required before this item can be active.");
                        }
                        continue;
                    }

                    if (($field['type'] ?? 'text') === 'number' && ! is_numeric($value)) {
                        $validator->errors()->add("attributes.{$key}", "{$field['label']} must be a number.");
                    }
                    if (($field['type'] ?? 'text') === 'select'
                        && is_array($field['options'] ?? null)
                        && ! in_array($value, $field['options'], true)) {
                        $validator->errors()->add("attributes.{$key}", "Select a valid {$field['label']} option.");
                    }
                }
            }
        }];
    }
}
