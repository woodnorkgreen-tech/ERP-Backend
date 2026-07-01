<?php

namespace App\Modules\Assets\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAssetRequest extends FormRequest
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
        $assetId = $this->route('id');

        return [
            'asset_code' => "nullable|string|max:50|unique:assets,asset_code,{$assetId}",
            'name' => 'sometimes|required|string|max:255',
            'image' => 'nullable|image|max:4096',
            'ownership_type' => 'sometimes|required|in:Company,Client',
            'client_name' => 'nullable|string|max:150',
            'client_id' => 'nullable|integer',
            'assigned_to' => 'sometimes|required|integer|exists:users,id',
            'is_available' => 'boolean',
            'category' => 'nullable|string|max:100',
            'category_id' => 'nullable|integer|exists:asset_categories,id',
            'subcategory' => 'nullable|string|max:100',
            'manufacturer' => 'nullable|string|max:150',
            'model' => 'nullable|string|max:150',
            'serial_number' => 'nullable|string|max:150',
            'specifications' => 'nullable|string|max:255',
            'qty' => 'nullable|integer|min:1',
            'status' => 'nullable|in:Active,In Repair,Retired,Disposed,Lost',
            'condition' => 'nullable|in:New,Good,Fair,Poor',
            'department_id' => 'nullable|integer|exists:departments,id',
            'location' => 'nullable|string|max:150',
            'purchase_date' => 'nullable|date',
            'purchase_cost_kes' => 'nullable|numeric|min:0',
            'purchase_cost_usd' => 'nullable|numeric|min:0',
            'current_value' => 'nullable|numeric|min:0',
            'supplier' => 'nullable|string|max:150',
            'warranty_expiry' => 'nullable|date',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ];
    }
}
