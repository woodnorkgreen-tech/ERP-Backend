<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RequisitionSchemaService
{
    public function validate(PettyCashRequisitionType $type, array $payload): array
    {
        $rules = [];
        foreach (PettyCashRequisitionType::normaliseFields($type->request_fields) as $field) {
            $rules['custom_fields.'.$field['key']] = $this->rules($field);
        }
        foreach (PettyCashRequisitionType::normaliseFields($type->item_fields) as $field) {
            $rules['items.*.details.'.$field['key']] = $this->rules($field);
        }

        if ($type->requires_project) {
            $hasProject = filled($payload['project_id'] ?? null)
                || filled($payload['enquiry_id'] ?? null)
                || filled($payload['project_name'] ?? null);
            if (! $hasProject) {
                throw ValidationException::withMessages([
                    'project_id' => ['This requisition type must be linked to a project, enquiry or named project.'],
                ]);
            }
        }

        if ($type->recipient_mode === 'per_item') {
            foreach ($payload['items'] ?? [] as $index => $item) {
                if (blank($item['payee_id'] ?? null) && blank($item['payee_name'] ?? null)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.payee_name" => ['Name the recipient for this line.'],
                    ]);
                }
            }
        } elseif ($type->recipient_mode === 'single'
            && blank($payload['payee_id'] ?? null) && blank($payload['payee_name'] ?? null)) {
            throw ValidationException::withMessages(['payee_name' => ['Select or enter the main recipient.']]);
        }

        Validator::make($payload, $rules)->validate();

        return [
            'custom_fields' => $this->onlyDefined($payload['custom_fields'] ?? [], $type->request_fields),
            'item_details' => collect($payload['items'] ?? [])->map(fn ($item) =>
                $this->onlyDefined($item['details'] ?? [], $type->item_fields)
            )->all(),
        ];
    }

    private function rules(array $field): array
    {
        $rules = [$field['required'] ? 'required' : 'nullable'];
        $rules[] = match ($field['type']) {
            'number' => 'numeric',
            'date' => 'date',
            'boolean' => 'boolean',
            'phone' => 'string',
            default => 'string',
        };
        if ($field['type'] === 'select' && $field['options']) $rules[] = 'in:'.implode(',', $field['options']);
        if ($field['min'] !== null) $rules[] = 'min:'.$field['min'];
        if ($field['max'] !== null) $rules[] = 'max:'.$field['max'];
        return $rules;
    }

    private function onlyDefined(array $values, mixed $schema): array
    {
        $keys = collect(PettyCashRequisitionType::normaliseFields($schema))->pluck('key')->all();
        return Arr::only($values, $keys);
    }
}
