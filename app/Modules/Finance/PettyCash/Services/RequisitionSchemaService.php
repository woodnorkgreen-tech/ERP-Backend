<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RequisitionSchemaService
{
    public function validate(PettyCashRequisitionType $type, array $payload): array
    {
        $document = $type->schemaDocument();
        $requestValues = is_array($payload['custom_fields'] ?? null) ? $payload['custom_fields'] : [];
        $requestFields = collect($document['sections'])
            ->filter(fn (array $section) => $this->visible($section, $type, $requestValues))
            ->flatMap(fn (array $section) => $section['fields'])
            ->filter(fn (array $field) => $this->visible($field, $type, $requestValues))
            ->values()
            ->all();

        $rules = [];
        foreach ($requestFields as $field) {
            $rules['custom_fields.'.$field['key']] = $this->rules($field);
            $rules += $this->nestedRules($field, 'custom_fields.'.$field['key']);
        }

        $visibleLineFields = [];
        foreach (is_array($payload['items'] ?? null) ? $payload['items'] : [] as $index => $item) {
            $details = is_array($item['details'] ?? null) ? $item['details'] : [];
            $visibleLineFields[$index] = collect($document['line_fields'])
                ->filter(fn (array $field) => $this->visible($field, $type, $details))
                ->values()
                ->all();

            foreach ($visibleLineFields[$index] as $field) {
                $prefix = "items.{$index}.details.{$field['key']}";
                $rules[$prefix] = $this->rules($field);
                $rules += $this->nestedRules($field, $prefix);
            }
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
            'custom_fields' => $this->onlyKeys($requestValues, $requestFields),
            'item_details' => collect(is_array($payload['items'] ?? null) ? $payload['items'] : [])
                ->map(fn ($item, $index) => $this->onlyKeys(
                    is_array($item['details'] ?? null) ? $item['details'] : [],
                    $visibleLineFields[$index] ?? [],
                ))
                ->all(),
        ];
    }

    private function rules(array $field): array
    {
        $rules = [$field['required'] ? 'required' : 'nullable'];
        $rules[] = match ($field['type']) {
            'number', 'money' => 'numeric',
            'date' => 'date',
            'boolean' => 'boolean',
            'multiselect', 'employee_picker', 'project_picker' => 'array',
            'phone' => 'string',
            default => 'string',
        };
        if ($field['type'] === 'select' && $field['options']) $rules[] = Rule::in($field['options']);
        if ($field['min'] !== null) $rules[] = 'min:'.$field['min'];
        if ($field['max'] !== null) $rules[] = 'max:'.$field['max'];
        return $rules;
    }

    /**
     * A picker's answer is a reference plus the label it was chosen under.
     *
     * The id is checked against the table it points at, so a requisition can
     * never carry a reference to a record that does not exist; the label is
     * accepted as written, because its job is to stay readable in the frozen
     * snapshot even after the referenced record is renamed or deleted.
     */
    private function nestedRules(array $field, string $prefix): array
    {
        return match ($field['type']) {
            'multiselect' => [
                $prefix.'.*' => ['string', Rule::in($field['options'] ?? [])],
            ],
            'employee_picker' => [
                $prefix.'.id' => [$field['required'] ? 'required' : 'nullable', 'integer', 'exists:employees,id'],
                $prefix.'.label' => ['required_with:'.$prefix.'.id', 'nullable', 'string', 'max:255'],
            ],
            'project_picker' => [
                $prefix.'.id' => [$field['required'] ? 'required' : 'nullable', 'integer'],
                $prefix.'.kind' => ['required_with:'.$prefix.'.id', 'nullable', 'in:project,enquiry'],
                $prefix.'.label' => ['required_with:'.$prefix.'.id', 'nullable', 'string', 'max:255'],
            ],
            default => [],
        };
    }

    /**
     * Is this field being asked for, given what the requester has entered?
     *
     * Mirrors the client's evaluator exactly. Two shapes only — another field's
     * value, or a flag on the type — because anything richer becomes a language
     * an administrator can write an unfillable form in.
     */
    private function visible(array $field, PettyCashRequisitionType $type, array $values): bool
    {
        $condition = $field['visible_when'] ?? null;
        if (! $condition) {
            return true;
        }

        if (isset($condition['type_flag'])) {
            return (bool) ($type->{$condition['type_flag']} ?? false);
        }

        $actual = $values[$condition['field']] ?? null;

        if (array_key_exists('in', $condition)) {
            return in_array($actual, $condition['in'], true);
        }

        if (array_key_exists('is', $condition)) {
            return $actual === $condition['is'];
        }

        return filled($actual);
    }

    /** Drop anything the schema did not ask for, so stored data matches the contract. */
    private function onlyKeys(array $values, array $fields): array
    {
        return Arr::only($values, collect($fields)->pluck('key')->all());
    }
}
