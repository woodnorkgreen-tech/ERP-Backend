<?php

namespace App\Modules\MaterialsLibrary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\Workstation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\MaterialsLibrary\Support\MaterialControl;
use App\Modules\MaterialsLibrary\Services\MaterialAttributeNormalizationService;

class CategoryController extends Controller
{
    public function normalizationPreview(int $id, MaterialAttributeNormalizationService $service): JsonResponse
    {
        $category = MaterialCategory::findOrFail($id);
        return response()->json(['data' => $service->preview($category)]);
    }

    public function normalizeAttributes(Request $request, int $id, MaterialAttributeNormalizationService $service): JsonResponse
    {
        $this->assertManager();
        $request->validate(['confirm' => 'accepted']);
        $result = $service->apply(MaterialCategory::findOrFail($id), auth()->id());
        return response()->json(['message' => "{$result['materials_changed']} material(s) standardized. {$result['materials_skipped']} conflict(s) were left unchanged.", 'data' => $result]);
    }

    public function rollbackNormalization(Request $request, int $runId, MaterialAttributeNormalizationService $service): JsonResponse
    {
        $this->assertManager();
        $request->validate(['confirm' => 'accepted']);
        $result = $service->rollback($runId);
        return response()->json(['message' => "{$result['materials_restored']} material(s) restored.", 'data' => $result]);
    }
    /**
     * Return the full category tree (parents with their children).
     * Used by the frontend to build cascading dropdowns.
     */
    public function tree(): JsonResponse
    {
        $parents = MaterialCategory::with(['children' => fn ($q) => $q->active()->ordered()])
            ->active()
            ->roots()
            ->ordered()
            ->get()
            ->map(fn ($parent) => [
                'id'       => $parent->id,
                'name'     => $parent->name,
                'code'     => $parent->code,
                'item_type_id' => $parent->item_type_id,
                'parent_id' => $parent->parent_id,
                'is_selectable' => $parent->is_selectable,
                'default_issue_disposition' => $parent->default_issue_disposition,
                'default_tracking_mode' => $parent->default_tracking_mode,
                'allowed_uoms' => $parent->allowed_uoms,
                'required_attributes' => $parent->required_attributes,
                'children' => $parent->children->map(fn ($child) => [
                    'id'        => $child->id,
                    'name'      => $child->name,
                    'code'      => $child->code,
                    'parent_id' => $child->parent_id,
                    'item_type_id' => $child->item_type_id,
                    'is_selectable' => $child->is_selectable,
                    'default_issue_disposition' => $child->default_issue_disposition,
                    'default_tracking_mode' => $child->default_tracking_mode,
                    'allowed_uoms' => $child->allowed_uoms,
                    'required_attributes' => $child->required_attributes,
                ]),
            ]);

        return response()->json(['data' => $parents]);
    }

    /**
     * Return a flat list of all active categories.
     * Used for simple select dropdowns and validation lookups.
     */
    public function index(): JsonResponse
    {
        $categories = MaterialCategory::with('parent')
            ->active()
            ->ordered()
            ->get()
            ->map(fn ($cat) => [
                'id'          => $cat->id,
                'name'        => $cat->name,
                'item_type_id' => $cat->item_type_id,
                'parent_id'   => $cat->parent_id,
                'parent_name' => $cat->parent?->name,
                'is_root'     => $cat->isRoot(),
                'is_selectable' => $cat->is_selectable,
                'default_issue_disposition' => $cat->default_issue_disposition,
                'default_tracking_mode' => $cat->default_tracking_mode,
                'allowed_uoms' => $cat->allowed_uoms,
                'required_attributes' => $cat->required_attributes,
            ]);

        return response()->json(['data' => $categories]);
    }


    /**
     * The specification fields that describe a material in this category,
     * inherited from its group.
     *
     * Schema used to be a PHP switch keyed by workstation, which meant new spec
     * fields needed a deploy and a material used at two workstations had two
     * spec sheets. Roll width is a fact about vinyl, not about the printer.
     */
    public function schema(int $id): JsonResponse
    {
        $category = MaterialCategory::with('parent')->findOrFail($id);
        $parentSchema = MaterialCategory::normaliseSchema($category->parent?->required_attributes);
        $ownSchema = MaterialCategory::normaliseSchema($category->required_attributes);
        $ownKeys = collect($ownSchema)->pluck('key')->all();
        $configured = collect($category->resolvedAttributeSchema())->map(function (array $field) use ($ownKeys): array {
            $field['source'] = in_array($field['key'], $ownKeys, true) ? 'category' : 'inherited';
            return $field;
        })->all();
        $observed = $this->observedMaterialSchema($category, collect($configured)->pluck('key')->all());
        $observedUoms = $this->observedMaterialUoms($category);

        return response()->json([
            'data' => array_values(array_merge($configured, $observed)),
            'meta' => [
                'category' => $category->name,
                'group' => $category->parent?->name,
                'configured_count' => count($configured),
                'inherited_count' => count($parentSchema),
                'discovered_count' => count($observed),
                'observed_uoms' => $observedUoms,
            ],
        ]);
    }

    /** @return array<int,string> */
    private function observedMaterialUoms(MaterialCategory $category): array
    {
        $categoryIds = [$category->id];
        if ($category->isRoot()) {
            $categoryIds = $category->children()->pluck('id')->push($category->id)->all();
        }

        $materials = LibraryMaterial::query()
            ->whereIn('material_category_id', $categoryIds)
            ->get(['base_uom_id', 'purchase_uom_id', 'issue_uom_id', 'unit_of_measure']);
        $unitIds = $materials->flatMap(fn ($material) => [
            $material->base_uom_id,
            $material->purchase_uom_id,
            $material->issue_uom_id,
        ])->filter()->unique();
        $codes = UnitOfMeasure::whereIn('id', $unitIds)->pluck('code');
        $registeredCodes = UnitOfMeasure::pluck('code')->keyBy(fn ($code) => strtolower(trim($code)));

        foreach ($materials->pluck('unit_of_measure')->filter() as $legacyCode) {
            $matched = $registeredCodes->get(strtolower(trim($legacyCode)));
            if ($matched) $codes->push($matched);
        }

        return $codes->filter()->unique()->sort()->values()->all();
    }

    /** @param array<int,string> $configuredKeys
     *  @return array<int,array<string,mixed>>
     */
    private function observedMaterialSchema(MaterialCategory $category, array $configuredKeys): array
    {
        $categoryIds = [$category->id];
        if ($category->isRoot()) {
            $categoryIds = $category->children()->pluck('id')->push($category->id)->all();
        }

        $valuesByKey = [];
        LibraryMaterial::query()
            ->whereIn('material_category_id', $categoryIds)
            ->whereNotNull('attributes')
            ->select(['id', 'attributes'])
            ->chunkById(250, function ($materials) use (&$valuesByKey, $configuredKeys): void {
                foreach ($materials as $material) {
                    $attributes = $material->attributes ?? [];
                    $attributes = is_array($attributes['attributes'] ?? null) ? $attributes['attributes'] : $attributes;
                    foreach ($attributes as $rawKey => $value) {
                        if (! is_scalar($value) && $value !== null) continue;
                        $key = Str::of((string) $rawKey)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
                        if ($key === '' || in_array($key, $configuredKeys, true)) continue;
                        $valuesByKey[$key][] = $value;
                    }
                }
            });

        return collect($valuesByKey)->map(function (array $values, string $key): array {
            $present = array_values(array_filter($values, fn ($value) => $value !== null && $value !== ''));
            return [
                'key' => $key,
                'label' => Str::headline($key),
                'type' => $present !== [] && collect($present)->every(fn ($value) => is_numeric($value)) ? 'number' : 'text',
                'unit' => null,
                'options' => null,
                'required' => false,
                'discovered' => true,
                'source' => 'discovered',
            ];
        })->values()->all();
    }

    /**
     * Create a category.
     *
     * The people who know what a material is are the people typing the list. A
     * taxonomy only a developer can extend guarantees the master list gets built
     * somewhere else and imported around the rules — which is exactly what the
     * 418 unclassified materials in this database are.
     *
     * Adding a leaf is an everyday act. Adding a root group reshapes the whole
     * catalogue, so it stays with an administrator.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'parent_id' => 'nullable|integer|exists:material_categories,id',
            'code' => 'nullable|string|max:20',
            'item_type_id' => 'nullable|integer|exists:material_item_types,id',
            'default_issue_disposition' => ['nullable', Rule::in(MaterialControl::DISPOSITIONS)],
            'default_tracking_mode' => ['nullable', Rule::in(MaterialControl::TRACKING_MODES)],
            'allowed_uoms' => 'nullable|array',
            'allowed_uoms.*' => 'string|exists:units_of_measure,code',
            'required_attributes' => 'nullable|array',
            'required_attributes.*.key' => ['required_with:required_attributes', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'required_attributes.*.label' => 'nullable|string|max:120',
            'required_attributes.*.type' => 'nullable|in:text,number,select,textarea',
            'required_attributes.*.unit' => 'nullable|string|max:30',
            'required_attributes.*.options' => 'nullable|array',
            'required_attributes.*.options.*' => 'string|max:120',
            'required_attributes.*.required' => 'nullable|boolean',
        ]);

        if (blank($validated['parent_id'] ?? null) && ! auth()->user()?->hasAnyRole(['Manager', 'Super Admin'])) {
            return response()->json([
                'message' => 'Only a manager can add a top-level category group. Pick the group this belongs under.',
            ], 403);
        }

        $parent = ! empty($validated['parent_id']) ? MaterialCategory::find($validated['parent_id']) : null;
        if ($parent && $parent->parent_id) {
            throw ValidationException::withMessages([
                'parent_id' => 'Categories are two levels deep: a group, and the categories inside it.',
            ]);
        }

        $duplicate = MaterialCategory::where('parent_id', $validated['parent_id'] ?? null)
            ->whereRaw('LOWER(name) = ?', [Str::lower(trim($validated['name']))])->first();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => "\"{$duplicate->name}\" already exists here. Use it instead of creating a second one.",
            ]);
        }

        $category = MaterialCategory::create([
            'name' => trim($validated['name']),
            'parent_id' => $validated['parent_id'] ?? null,
            'code' => $validated['code'] ?? $this->deriveCode($validated['name']),
            // A leaf inherits its group's item type unless told otherwise, so a
            // category created mid-form still knows how its materials behave.
            'item_type_id' => $validated['item_type_id'] ?? $parent?->item_type_id,
            'default_issue_disposition' => $validated['default_issue_disposition'] ?? $parent?->default_issue_disposition,
            'default_tracking_mode' => $validated['default_tracking_mode'] ?? $parent?->default_tracking_mode,
            'allowed_uoms' => $validated['allowed_uoms'] ?? null,
            'required_attributes' => $validated['required_attributes'] ?? null,
            'is_active' => true,
            // Only leaves hold materials; a group is for navigating.
            'is_selectable' => $parent !== null,
            'sort_order' => (int) MaterialCategory::where('parent_id', $validated['parent_id'] ?? null)->max('sort_order') + 1,
        ]);

        return response()->json([
            'message' => "Category \"{$category->name}\" created.",
            'data' => $category->load('parent'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! auth()->user()?->hasAnyRole(['Manager', 'Super Admin'])) {
            return response()->json(['message' => 'Only a manager can change category blueprints.'], 403);
        }

        $category = MaterialCategory::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'code' => 'nullable|string|max:20',
            'item_type_id' => 'nullable|integer|exists:material_item_types,id',
            'default_issue_disposition' => ['nullable', Rule::in(MaterialControl::DISPOSITIONS)],
            'default_tracking_mode' => ['nullable', Rule::in(MaterialControl::TRACKING_MODES)],
            'allowed_uoms' => 'nullable|array',
            'allowed_uoms.*' => 'string|exists:units_of_measure,code',
            'required_attributes' => 'nullable|array',
            'required_attributes.*.key' => ['required_with:required_attributes', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'required_attributes.*.label' => 'nullable|string|max:120',
            'required_attributes.*.type' => 'nullable|in:text,number,select,textarea',
            'required_attributes.*.unit' => 'nullable|string|max:30',
            'required_attributes.*.options' => 'nullable|array',
            'required_attributes.*.options.*' => 'string|max:120',
            'required_attributes.*.required' => 'nullable|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        $category->update($validated);

        return response()->json(['message' => 'Category updated.', 'data' => $category->fresh()->load('parent')]);
    }

    /**
     * Fold one category into another, carrying its materials across.
     *
     * Consolidating a taxonomy is a judgement about meaning — whether "Hardware"
     * and "Hardware & Fasteners" are the same thing is not something a string
     * comparison should decide. So merging is deliberate, one pair at a time,
     * with the material count visible before it happens.
     */
    public function merge(Request $request, int $id): JsonResponse
    {
        if (! auth()->user()?->hasAnyRole(['Manager', 'Super Admin'])) {
            return response()->json(['message' => 'Only a manager can merge categories.'], 403);
        }

        $validated = $request->validate([
            'into_category_id' => 'required|integer|exists:material_categories,id|different:'.$id,
        ]);

        $source = MaterialCategory::with('children')->findOrFail($id);
        $target = MaterialCategory::findOrFail($validated['into_category_id']);

        if ($source->children->isNotEmpty()) {
            throw ValidationException::withMessages([
                'into_category_id' => "\"{$source->name}\" still has categories inside it. Merge or move those first.",
            ]);
        }
        if ((bool) $source->parent_id !== (bool) $target->parent_id) {
            throw ValidationException::withMessages([
                'into_category_id' => 'A category can only merge into one at the same level.',
            ]);
        }

        $moved = 0;
        DB::transaction(function () use ($source, $target, &$moved) {
            $moved = LibraryMaterial::where('material_category_id', $source->id)
                ->update([
                    'material_category_id' => $target->id,
                    // The legacy strings travel too, or the two lookup paths disagree.
                    'category' => $target->parent?->name ?? $target->name,
                    'subcategory' => $target->parent ? $target->name : null,
                ]);

            $source->delete();
        });

        return response()->json([
            'message' => "{$moved} material(s) moved into \"{$target->name}\". \"{$source->name}\" was retired.",
            'moved' => $moved,
        ]);
    }

    private function deriveCode(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];

        $code = count($words) > 1
            ? strtoupper(implode('', array_map(fn ($word) => substr($word, 0, 1), array_slice($words, 0, 4))))
            : strtoupper(substr($words[0], 0, 4));

        // Keep room for the disambiguating suffix so a busy prefix cannot
        // overflow the column.
        $base = substr($code, 0, 16);
        $code = $base;
        for ($suffix = 2; MaterialCategory::where('code', $code)->exists(); $suffix++) {
            $code = $base.$suffix;
        }

        return $code;
    }

    private function assertManager(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403, 'Only a manager can change catalogue data.');
    }

    /**
     * Suggest the next available SKU code for a given workstation + category.
     *
     * Format: {WS_CODE}-{CAT_CODE}-{SEQ:04d}
     * Example: CARP-PLY-0001, CNC-MDF-0003, LFP-VNL-0015
     *
     * The sequence is per workstation+category prefix, ensuring each combination
     * has its own independent counter. Gaps in sequence are skipped; the next
     * truly unused code is returned.
     */
    public function suggestCode(Request $request): JsonResponse
    {
        $request->validate([
            'workstation_id' => 'required|integer|exists:workstations,id',
            'category_id'    => 'required|integer|exists:material_categories,id',
        ]);

        $workstation = Workstation::findOrFail($request->workstation_id);
        $category    = MaterialCategory::findOrFail($request->category_id);

        $wsCode  = strtoupper(trim($workstation->code));
        $catCode = strtoupper(trim($category->code ?? $category->name));

        // Trim category code to 6 chars max to keep SKUs readable
        $catCode = preg_replace('/[^A-Z0-9]/', '', substr($catCode, 0, 6));

        $prefix = "{$wsCode}-{$catCode}-";

        // Count existing codes with this prefix to seed the sequence
        $count = DB::table('library_materials')
            ->where('material_code', 'like', $prefix . '%')
            ->whereNull('deleted_at')
            ->count();

        // Find the next unused code, skipping any gaps
        $seq  = $count + 1;
        $code = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

        while (DB::table('library_materials')->where('material_code', $code)->exists()) {
            $seq++;
            $code = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
        }

        return response()->json([
            'code'   => $code,
            'prefix' => $prefix,
            'seq'    => $seq,
        ]);
    }
}
