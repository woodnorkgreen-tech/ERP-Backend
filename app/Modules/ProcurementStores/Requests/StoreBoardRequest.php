<?php

namespace App\Modules\ProcurementStores\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;

class StoreBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'library_material_id' => [
                'required',
                'integer',
                Rule::exists('library_materials', 'id')->where('is_active', true),
                function ($attribute, $value, $fail) {
                    $this->validateBoardMaterial($value, $fail);
                },
            ],
            'quantity'     => 'required|integer|min:1|max:500',
            'batch_number' => 'nullable|string|max:100',
            'length'       => 'nullable|numeric|min:1',
            'width'        => 'nullable|numeric|min:1',
            'thickness'    => 'nullable|numeric|min:1',
        ];
    }

    /**
     * Validate that the linked material belongs to a board-eligible category
     * and is of type 'reusable'. Boards are physical reusable sheet assets,
     * not consumables like inks or adhesives.
     */
    private function validateBoardMaterial(int $materialId, callable $fail): void
    {
        $material = LibraryMaterial::with('materialCategory.parent')->find($materialId);

        if (!$material) {
            return; // 'exists' rule already handles this
        }

        // Enforce material_type = reusable
        if ($material->material_type !== 'reusable') {
            $fail("Only materials of type 'Reusable' can be tracked as physical boards. '{$material->material_name}' is marked as '{$material->material_type}'.");
            return;
        }

        // Enforce board-eligible parent categories
        $boardParentNames = config('boards.tracking_categories', ['Boards', 'Sheet Materials', 'Veneer']);

        // Prefer FK-based parent; fall back to legacy category string
        $parentCategoryName = $material->materialCategory?->parent?->name
            ?? $material->materialCategory?->name
            ?? $material->category
            ?? '';

        if (!in_array($parentCategoryName, $boardParentNames, true)) {
            $fail(
                "Material '{$material->material_name}' belongs to category '{$parentCategoryName}', which is not eligible for board tracking. " .
                "Only materials under: " . implode(', ', $boardParentNames) . " can be registered as boards."
            );
        }
    }

    public function messages(): array
    {
        return [
            'library_material_id.required' => 'A material must be selected to register boards.',
            'library_material_id.exists'   => 'The selected material does not exist or is inactive.',
            'quantity.required'            => 'Enter the number of boards received.',
            'quantity.max'                 => 'You can receive up to 500 boards in one batch.',
        ];
    }
}
