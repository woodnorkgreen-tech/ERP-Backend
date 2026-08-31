<?php

namespace App\Modules\Finance\PettyCash\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PettyCashRequisitionType extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'icon', 'recipient_mode', 'requires_project',
        'default_expense_code_id', 'default_payment_source_id',
        'request_fields', 'item_fields', 'schema', 'schema_version', 'instructions',
        'is_active', 'sort_order',
    ];

    protected $casts = [
        'requires_project' => 'boolean',
        'request_fields' => 'array',
        'item_fields' => 'array',
        'schema' => 'array',
        'schema_version' => 'integer',
        'instructions' => 'array',
        'is_active' => 'boolean',
    ];

    public const SCHEMA_VERSION = 2;

    /**
     * Every question the form knows how to ask.
     *
     * A new kind of question is added here and registered against a component
     * in the client's field registry — never another branch in a template.
     *
     * This list holds only what the registry can actually draw. An entry here
     * that the client cannot render would let an administrator configure a
     * question no requester can answer, which is a worse failure than the
     * field type simply not existing yet.
     *
     * `file` is deliberately still absent. Unlike the pickers — which were
     * already implemented inside RequisitionForm and only needed lifting — petty
     * cash has no attachment infrastructure at all: no column, no upload route,
     * no storage, and an unauthenticated public form that would need its own
     * answer for who may upload what. That is a slice of its own, not one more
     * component.
     */
    public const FIELD_TYPES = [
        'text', 'textarea', 'number', 'select', 'date', 'phone', 'boolean',
        'money', 'multiselect', 'employee_picker', 'project_picker',
    ];

    /**
     * Pickers store {id, label} rather than a bare id.
     *
     * The requisition's frozen snapshot is what an approver — or an auditor two
     * years later — actually reads. A bare id renders as "42" and becomes
     * meaningless the moment the employee or project is renamed or removed, so
     * the label chosen at the time is stored beside the reference.
     */
    public const REFERENCE_TYPES = ['employee_picker', 'project_picker'];

    public const WIDTHS = ['full', 'half', 'third'];

    /**
     * Panels the form already implements. A section may host one, and carries
     * its schema fields alongside it; a section with no builtin is a pure
     * schema panel, which is what makes a fourth section possible without
     * touching the template.
     */
    public const BUILTINS = ['request', 'project', 'items'];

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

            $type = in_array($field['type'] ?? 'text', self::FIELD_TYPES, true)
                ? ($field['type'] ?? 'text') : 'text';

            return [
                'key' => Str::snake($field['key']),
                'label' => $field['label'] ?? Str::headline($field['key']),
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'options' => in_array($type, ['select', 'multiselect'], true) ? array_values($field['options'] ?? []) : null,
                'placeholder' => $field['placeholder'] ?? null,
                'help' => $field['help'] ?? null,
                'unit' => $field['unit'] ?? null,
                'min' => $field['min'] ?? null,
                'max' => $field['max'] ?? null,
                'width' => in_array($field['width'] ?? 'half', self::WIDTHS, true) ? ($field['width'] ?? 'half') : 'half',
                'visible_when' => self::normaliseCondition($field['visible_when'] ?? null),
            ];
        })->filter()->values()->all();
    }

    /**
     * A visibility rule, reduced to the two forms that can be evaluated safely
     * on both sides: another field's value, or a flag on the type itself.
     *
     * Deliberately not an expression language. An administrator who can write
     * an arbitrary predicate can write a form that cannot be filled in, and
     * there would be no debugger to tell them why.
     */
    public static function normaliseCondition(mixed $condition): ?array
    {
        if (! is_array($condition)) {
            return null;
        }

        if (filled($condition['type_flag'] ?? null)) {
            return in_array($condition['type_flag'], ['requires_project'], true)
                ? ['type_flag' => $condition['type_flag']]
                : null;
        }

        if (blank($condition['field'] ?? null)) {
            return null;
        }

        $field = Str::snake($condition['field']);

        if (array_key_exists('in', $condition) && is_array($condition['in'])) {
            return ['field' => $field, 'in' => array_values($condition['in'])];
        }

        if (array_key_exists('is', $condition)) {
            return ['field' => $field, 'is' => $condition['is']];
        }

        return ['field' => $field, 'filled' => true];
    }

    /** Sections, normalised. Unknown builtins are dropped rather than guessed. */
    public static function normaliseSections(mixed $sections): array
    {
        if (! is_array($sections)) {
            return [];
        }

        return collect($sections)->map(function ($section) {
            if (! is_array($section) || blank($section['key'] ?? null)) {
                return null;
            }

            $builtin = in_array($section['builtin'] ?? null, self::BUILTINS, true)
                ? $section['builtin']
                : null;

            return [
                'key' => Str::snake($section['key']),
                'title' => $section['title'] ?? Str::headline($section['key']),
                'hint' => $section['hint'] ?? null,
                'builtin' => $builtin,
                'visible_when' => self::normaliseCondition($section['visible_when'] ?? null),
                'fields' => self::normaliseFields($section['fields'] ?? []),
            ];
        })->filter()->values()->all();
    }

    /**
     * The type's schema as one v2 document.
     *
     * A type that has never been edited through the builder has no `schema`,
     * so one is synthesised from the v1 columns. That keeps every existing
     * type working untouched and means this migration needs no back-fill —
     * v1 rows are read as v2, and only writing through the builder promotes a
     * row to a stored document.
     */
    public function schemaDocument(): array
    {
        if (filled($this->schema['sections'] ?? null)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'sections' => self::normaliseSections($this->schema['sections']),
                'line_fields' => self::normaliseFields($this->schema['line_fields'] ?? $this->item_fields),
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'sections' => self::normaliseSections([
                [
                    'key' => 'request',
                    'title' => "What's this for",
                    'hint' => 'Choose a category and describe the expense',
                    'builtin' => 'request',
                    'fields' => $this->request_fields ?? [],
                ],
                [
                    'key' => 'charge',
                    'title' => 'Which project',
                    'hint' => 'What this spend is charged against',
                    'builtin' => 'project',
                    // Left unconditional on purpose. v1 has no way to say "this
                    // type never charges a project" — requires_project only says
                    // it *must*, and a type that does not require one may still
                    // legitimately be charged to one. Synthesis therefore
                    // preserves today's behaviour exactly; hiding the panel
                    // becomes expressible once a type is edited through the
                    // builder and stores its own document.
                    'visible_when' => null,
                    'fields' => [],
                ],
                [
                    'key' => 'lines',
                    'title' => $this->recipient_mode === 'per_item' ? 'Who gets paid' : 'What to buy',
                    'builtin' => 'items',
                    'fields' => [],
                ],
            ]),
            'line_fields' => self::normaliseFields($this->item_fields),
        ];
    }

    /** Every schema field on the type, whichever section holds it. */
    public function allRequestFields(): array
    {
        return collect($this->schemaDocument()['sections'])
            ->flatMap(fn (array $section) => $section['fields'])
            ->values()
            ->all();
    }

    public function definition(): array
    {
        $document = $this->schemaDocument();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'recipient_mode' => $this->recipient_mode,
            'requires_project' => $this->requires_project,
            // Carried so the payment sheet can classify an approved requisition
            // without asking a payer to re-decide what the type already knows.
            'default_expense_code_id' => $this->default_expense_code_id,
            'default_payment_source_id' => $this->default_payment_source_id,
            // Top-level on purpose: a historical requisition can choose its
            // snapshot reader before interpreting the versioned schema body.
            'schema_version' => self::SCHEMA_VERSION,
            // v1 keys stay in the payload: requisitions already carry them in
            // their type_snapshot, and RequisitionShow reads them by name.
            'request_fields' => collect($document['sections'])
                ->flatMap(fn (array $section) => $section['fields'])
                ->values()
                ->all(),
            'item_fields' => $document['line_fields'],
            'instructions' => array_values($this->instructions ?? []),
            'schema' => $document,
        ];
    }
}
