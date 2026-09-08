<?php

namespace App\Modules\Design\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDesignBomItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'material_id' => 'nullable|integer|exists:library_materials,id',
            'description' => 'required|string|max:255',
            'specification' => 'nullable|string',
            'quantity' => 'required|numeric|min:0',
            'unit' => 'required|string|max:50',
            'wastage_percent' => 'nullable|numeric|min:0|max:100',
            'source' => 'nullable|in:designer,production,agreed',
            'status' => 'nullable|in:draft,production_review,approved',
            'notes' => 'nullable|string',
        ];
    }
}
