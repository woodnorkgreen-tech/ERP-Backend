<?php

namespace App\Modules\MaterialsLibrary\Requests;

use App\Constants\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registering a unit the registry never held. Until now the only way to add one
 * was a migration (see add_packaging_units_of_measure), which meant a storeman
 * naming a material in "bags" had to wait for a deploy or pick a unit that lied.
 */
class StoreUnitOfMeasureRequest extends FormRequest
{
    /** Every dimension the registry recognises. A unit outside them has nothing to convert against. */
    public const DIMENSIONS = ['count', 'length', 'area', 'volume', 'mass', 'package'];

    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::MATERIALS_LIBRARY_MANAGE) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            // Left blank it is derived from the name. Short, because pickers show it beside every quantity.
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.\-]*$/'],
            'dimension' => 'required|string|in:'.implode(',', self::DIMENSIONS),
            'decimal_places' => 'nullable|integer|min:0|max:6',
            'allows_fraction' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'A unit code may only use letters, numbers, dot, dash and underscore.',
            'dimension.in' => 'Choose what the unit measures: count, length, area, volume, mass or package.',
        ];
    }
}
