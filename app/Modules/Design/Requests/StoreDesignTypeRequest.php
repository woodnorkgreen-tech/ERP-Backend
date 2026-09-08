<?php

namespace App\Modules\Design\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDesignTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // DesignType uses SoftDeletes; Rule::unique is a raw DB check that doesn't
        // know about deleted_at, so a soft-deleted type would otherwise permanently
        // block reusing its name.
        $nameRule = Rule::unique('design_types', 'name')
            ->where(fn ($query) => $query->where('stream', $this->input('stream'))->whereNull('deleted_at'));

        $typeId = $this->route('type')?->id;
        if ($typeId) {
            $nameRule = $nameRule->ignore($typeId);
        }

        return [
            'stream' => 'required|in:graphic,structural',
            'name' => ['required', 'string', 'max:150', $nameRule],
            'code' => 'nullable|string|max:80',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }
}
