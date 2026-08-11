<?php

namespace App\Modules\Design\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDesignItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'design_type_id' => 'nullable|integer|exists:design_types,id',
            'project_deliverable_id' => 'nullable|integer|exists:project_deliverables,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:pending,in_design,submitted,approved,not_approved,cancelled,print_ready,production_ready,handed_off',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'quantity' => 'nullable|numeric|min:0',
            'dimension_unit' => 'nullable|in:m,cm,mm',
            'length_value' => 'nullable|numeric|min:0',
            'width_value' => 'nullable|numeric|min:0',
            'height_value' => 'nullable|numeric|min:0',
            'print_material_id' => 'nullable|integer|exists:library_materials,id',
            'print_notes' => 'nullable|string',
            'concept_notes' => 'nullable|string',
            'technical_notes' => 'nullable|string',
        ];
    }
}
