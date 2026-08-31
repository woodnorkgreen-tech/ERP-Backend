<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Requisition types, as data an administrator owns.
 *
 * Until this existed, the eight types in the system were an INSERT inside the
 * migration that created their table — every reference to the model anywhere in
 * the codebase was a read. Adding or changing a requisition type meant writing
 * a migration and deploying, which is the whole reason the form kept having to
 * be re-coded.
 */
class PettyCashRequisitionTypeController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:'.Permissions::FINANCE_REQUISITION_TYPES_MANAGE);
    }

    /** Every type, active or not — this screen is where retired ones are seen. */
    public function index(): JsonResponse
    {
        $types = PettyCashRequisitionType::query()
            ->withCount('requisitions')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (PettyCashRequisitionType $type) => $this->present($type));

        return response()->json([
            'success' => true,
            'data' => $types,
            'meta' => [
                // The builder offers exactly what the client can draw and the
                // server can validate, rather than a list maintained by hand in
                // the UI that could drift from either.
                'field_types' => PettyCashRequisitionType::FIELD_TYPES,
                'widths' => PettyCashRequisitionType::WIDTHS,
                'builtins' => PettyCashRequisitionType::BUILTINS,
                'recipient_modes' => ['single', 'per_item'],
                // Chosen once per type here so the payment sheet never asks.
                'expense_codes' => \Illuminate\Support\Facades\DB::table('expense_codes')
                    ->where('is_active', true)
                    ->orderBy('expense_family')->orderBy('code')
                    ->get(['id', 'code', 'expense_family', 'expense_type']),
                'payment_sources' => \Illuminate\Support\Facades\DB::table('payment_sources')
                    ->orderBy('name')->get(['id', 'name']),
                'schema_version' => PettyCashRequisitionType::SCHEMA_VERSION,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['code'] = $data['code'] ?? Str::snake($data['name']);

        $type = PettyCashRequisitionType::create($data);

        return response()->json([
            'success' => true,
            'message' => "Requisition type \"{$type->name}\" created.",
            'data' => $this->present($type->fresh()),
        ], 201);
    }

    public function update(Request $request, PettyCashRequisitionType $requisitionType): JsonResponse
    {
        $data = $this->validated($request, $requisitionType);
        // `code` is the stable machine identity. Renaming the label must not
        // break integrations or turn an omitted editor field into a NULL write.
        $data['code'] = $requisitionType->code;
        $requisitionType->update($data);

        return response()->json([
            'success' => true,
            'message' => "Requisition type \"{$requisitionType->name}\" saved.",
            'data' => $this->present($requisitionType->fresh()),
        ]);
    }

    /**
     * Retire rather than delete.
     *
     * A type that has been used is referenced by every requisition raised under
     * it, and those rows carry its name and a snapshot of its schema. Deleting
     * it would either orphan them or rewrite history; deactivating removes it
     * from the picker and leaves the record intact.
     */
    public function destroy(PettyCashRequisitionType $requisitionType): JsonResponse
    {
        $used = $requisitionType->requisitions()->exists();

        if (! $used) {
            $requisitionType->delete();

            return response()->json([
                'success' => true,
                'message' => "Requisition type \"{$requisitionType->name}\" deleted.",
                'data' => null,
            ]);
        }

        $requisitionType->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => "\"{$requisitionType->name}\" has been used, so it was retired rather than deleted. Existing requisitions keep it.",
            'data' => $this->present($requisitionType->fresh()),
        ]);
    }

    /**
     * Validation for a whole schema document.
     *
     * Field types are checked against the model's own list, which the client
     * registry mirrors — so an administrator cannot save a question no
     * requester could be shown.
     */
    private function validated(Request $request, ?PettyCashRequisitionType $existing = null): array
    {
        $unique = Rule::unique('petty_cash_requisition_types');
        $codeUnique = Rule::unique('petty_cash_requisition_types', 'code');
        if ($existing) {
            $unique = $unique->ignore($existing->id);
            $codeUnique = $codeUnique->ignore($existing->id);
        }

        $fieldRules = [
            'sections.*.fields' => ['array'],
            'sections.*.fields.*.key' => ['required', 'string', 'max:64'],
            'sections.*.fields.*.label' => ['nullable', 'string', 'max:120'],
            'sections.*.fields.*.type' => ['required', Rule::in(PettyCashRequisitionType::FIELD_TYPES)],
            'sections.*.fields.*.required' => ['boolean'],
            'sections.*.fields.*.width' => ['nullable', Rule::in(PettyCashRequisitionType::WIDTHS)],
            'sections.*.fields.*.options' => ['nullable', 'array'],
            'sections.*.fields.*.options.*' => ['string', 'max:120'],
            'sections.*.fields.*.placeholder' => ['nullable', 'string', 'max:160'],
            'sections.*.fields.*.help' => ['nullable', 'string', 'max:240'],
            'sections.*.fields.*.unit' => ['nullable', 'string', 'max:24'],
            'sections.*.fields.*.min' => ['nullable', 'numeric'],
            'sections.*.fields.*.max' => ['nullable', 'numeric'],

            // Line fields have the exact same question contract. Keeping the
            // rules symmetrical prevents malformed per-line configuration
            // being silently normalised while request fields are rejected.
            'line_fields.*.label' => ['nullable', 'string', 'max:120'],
            'line_fields.*.required' => ['boolean'],
            'line_fields.*.width' => ['nullable', Rule::in(PettyCashRequisitionType::WIDTHS)],
            'line_fields.*.options' => ['nullable', 'array'],
            'line_fields.*.options.*' => ['string', 'max:120'],
            'line_fields.*.placeholder' => ['nullable', 'string', 'max:160'],
            'line_fields.*.help' => ['nullable', 'string', 'max:240'],
            'line_fields.*.unit' => ['nullable', 'string', 'max:24'],
            'line_fields.*.min' => ['nullable', 'numeric'],
            'line_fields.*.max' => ['nullable', 'numeric'],
        ];

        $validated = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:100', (clone $unique)->where(fn ($q) => $q)],
            'code' => ['nullable', 'string', 'max:64', $codeUnique],
            'description' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:64'],
            'recipient_mode' => ['required', Rule::in(['single', 'per_item'])],
            'requires_project' => ['boolean'],
            'default_expense_code_id' => ['nullable', 'integer', 'exists:expense_codes,id'],
            'default_payment_source_id' => ['nullable', 'integer', 'exists:payment_sources,id'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'instructions' => ['nullable', 'array'],
            'instructions.*' => ['string', 'max:240'],

            'sections' => ['required', 'array', 'min:1'],
            'sections.*.key' => ['required', 'string', 'max:64'],
            'sections.*.title' => ['required', 'string', 'max:120'],
            'sections.*.hint' => ['nullable', 'string', 'max:240'],
            'sections.*.builtin' => ['nullable', Rule::in(PettyCashRequisitionType::BUILTINS)],

            'line_fields' => ['nullable', 'array'],
            'line_fields.*.key' => ['required', 'string', 'max:64'],
            'line_fields.*.type' => ['required', Rule::in(PettyCashRequisitionType::FIELD_TYPES)],
        ], $fieldRules));

        /*
         * Normalise from the raw input, not from $validated.
         *
         * validate() returns only the keys it was given rules for, so building
         * the document from it silently dropped every property without an
         * explicit rule — visible_when, options, placeholder, help. The rules
         * above are the guard on what must be right (keys, types, widths);
         * normaliseSections is the whitelist for everything else, and it
         * discards anything it cannot parse rather than storing it.
         */
        $document = [
            'sections' => PettyCashRequisitionType::normaliseSections($request->input('sections', [])),
            'line_fields' => PettyCashRequisitionType::normaliseFields($request->input('line_fields', [])),
        ];

        $this->assertSchemaIntegrity($document, (bool) ($validated['requires_project'] ?? false));

        $data = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'icon' => ($validated['icon'] ?? null) ?: 'mdi-tag-outline',
            'recipient_mode' => $validated['recipient_mode'],
            'requires_project' => (bool) ($validated['requires_project'] ?? false),
            'default_expense_code_id' => $validated['default_expense_code_id'] ?? null,
            'default_payment_source_id' => $validated['default_payment_source_id'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'sort_order' => $validated['sort_order'] ?? 0,
            'instructions' => array_values($validated['instructions'] ?? []),
            'schema' => $document,
            'schema_version' => PettyCashRequisitionType::SCHEMA_VERSION,
            // The v1 columns stay in step so anything still reading them — an
            // older client, an export — sees the same questions.
            'request_fields' => collect($document['sections'])->flatMap(fn ($s) => $s['fields'])->values()->all(),
            'item_fields' => $document['line_fields'],
        ];

        if (filled($validated['code'] ?? null)) {
            $data['code'] = Str::snake($validated['code']);
        }

        return $data;
    }

    /**
     * Reject a schema that the generic form cannot render or answer safely.
     *
     * Request and line fields live in separate answer scopes, so their keys may
     * overlap. Inside either scope, however, duplicate keys overwrite answers.
     * The request and items built-ins are the stable financial envelope and
     * must exist exactly once; project is optional, but required types need it.
     */
    private function assertSchemaIntegrity(array $document, bool $requiresProject): void
    {
        $errors = [];
        $sections = collect($document['sections']);
        $sectionKeys = $sections->pluck('key');
        if ($duplicate = $sectionKeys->duplicates()->first()) {
            $errors['sections'] = ["Two sections share the key \"{$duplicate}\"."];
        }

        $builtins = $sections->pluck('builtin')->filter();
        foreach (['request', 'items'] as $builtin) {
            if ($builtins->filter(fn ($value) => $value === $builtin)->count() !== 1) {
                $errors['sections'] = ["The form needs exactly one {$builtin} panel."];
            }
        }
        if (($sections->first()['builtin'] ?? null) !== 'request') {
            $errors['sections'] = ['The request panel must stay first so the requester can choose the category.'];
        } elseif (($sections->last()['builtin'] ?? null) !== 'items') {
            $errors['sections'] = ['The items and amounts panel must stay last.'];
        }
        $projectPanels = $builtins->filter(fn ($value) => $value === 'project')->count();
        if ($projectPanels > 1) {
            $errors['sections'] = ['The form can contain only one project panel.'];
        } elseif ($requiresProject && $projectPanels !== 1) {
            $errors['sections'] = ['A type that needs a project must include the project panel.'];
        }

        $requestFields = $sections->flatMap(fn ($section) => $section['fields'])->values();
        $this->assertFieldScope($requestFields, 'sections', $errors);
        $this->assertFieldScope(collect($document['line_fields']), 'line_fields', $errors);

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertFieldScope($fields, string $path, array &$errors): void
    {
        $keys = $fields->pluck('key');
        if ($duplicate = $keys->duplicates()->first()) {
            $errors[$path] = ["Two fields share the key \"{$duplicate}\". Each answer needs its own key."];
        }

        $known = $keys->flip();
        foreach ($fields as $index => $field) {
            if (in_array($field['type'], ['select', 'multiselect'], true) && empty($field['options'])) {
                $errors["{$path}.{$index}.options"] = ["\"{$field['label']}\" needs at least one choice."];
            }
            if ($field['min'] !== null && $field['max'] !== null && $field['min'] > $field['max']) {
                $errors["{$path}.{$index}.min"] = ['Minimum cannot be greater than maximum.'];
            }

            $dependency = $field['visible_when']['field'] ?? null;
            if ($dependency === $field['key']) {
                $errors["{$path}.{$index}.visible_when"] = ['A question cannot control its own visibility.'];
            } elseif ($dependency && ! $known->has($dependency)) {
                $errors["{$path}.{$index}.visible_when"] = ["The visibility rule refers to missing field \"{$dependency}\"."];
            }
        }
    }

    private function present(PettyCashRequisitionType $type): array
    {
        return array_merge($type->definition(), [
            'is_active' => $type->is_active,
            'sort_order' => $type->sort_order,
            // Whether this type is safe to delete outright, so the screen can
            // say "retire" instead of promising a deletion it will not perform.
            'requisitions_count' => $type->requisitions_count ?? $type->requisitions()->count(),
        ]);
    }
}
