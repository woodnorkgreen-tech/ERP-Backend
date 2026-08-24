<?php

namespace App\Modules\Finance\PettyCash\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PettyCashRequisitionType extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'icon', 'recipient_mode', 'requires_project',
        'request_fields', 'item_fields', 'instructions', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'requires_project' => 'boolean',
        'request_fields' => 'array',
        'item_fields' => 'array',
        'instructions' => 'array',
        'is_active' => 'boolean',
    ];

    public function requisitions(): HasMany
    {
        return $this->hasMany(PettyCashRequisition::class, 'requisition_type_id');
    }

    /** Stable client contract regardless of how an administrator entered it. */
    public static function normaliseFields(mixed $schema): array
    {
        if (! is_array($schema)) return [];

        return collect($schema)->map(function ($entry) {
            $field = is_string($entry) ? ['key' => $entry] : $entry;
            if (! is_array($field) || blank($field['key'] ?? null)) return null;

            $type = in_array($field['type'] ?? 'text', ['text', 'textarea', 'number', 'select', 'date', 'phone', 'boolean'], true)
                ? ($field['type'] ?? 'text') : 'text';

            return [
                'key' => Str::snake($field['key']),
                'label' => $field['label'] ?? Str::headline($field['key']),
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'options' => $type === 'select' ? array_values($field['options'] ?? []) : null,
                'placeholder' => $field['placeholder'] ?? null,
                'help' => $field['help'] ?? null,
                'unit' => $field['unit'] ?? null,
                'min' => $field['min'] ?? null,
                'max' => $field['max'] ?? null,
            ];
        })->filter()->values()->all();
    }

    public function definition(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'recipient_mode' => $this->recipient_mode,
            'requires_project' => $this->requires_project,
            'request_fields' => self::normaliseFields($this->request_fields),
            'item_fields' => self::normaliseFields($this->item_fields),
            'instructions' => array_values($this->instructions ?? []),
        ];
    }
}
