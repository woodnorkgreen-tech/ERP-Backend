<?php

namespace App\Modules\MaterialsLibrary\Console;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Services\MaterialDefaultsService;
use App\Modules\MaterialsLibrary\Support\MaterialCompleteness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bring the existing library under governance without inventing facts.
 *
 * The seeded category tree and the categories people actually typed are two
 * entirely disjoint taxonomies — not one of the 418 unclassified materials
 * matches a seeded category name, even case-insensitively. Guessing a mapping
 * between them would file hundreds of materials under a plausible wrong answer,
 * which is worse than leaving them unfiled.
 *
 * So this adopts the taxonomy the business already built: every distinct
 * category/subcategory string becomes a real category, and the materials link
 * to it. Consolidating "Hardware" into "Hardware & Fasteners" is a judgement
 * about meaning and stays a human decision — made afterwards, one merge at a
 * time, through the category merge endpoint, with the material count visible.
 */
class AlignMaterialLibraryCommand extends Command
{
    protected $signature = 'materials:align
        {--apply : Write the changes. Without this the command only reports.}
        {--activate : Also promote materials that end up complete to Active.}';

    protected $description = 'Adopt the legacy category strings as real categories and backfill units, so existing materials become governed';

    public function handle(MaterialDefaultsService $defaults): int
    {
        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->warn('Dry run — nothing will be written. Re-run with --apply to commit.');
        }
        $this->newLine();

        $summary = [
            'groups_created' => 0, 'categories_created' => 0, 'materials_linked' => 0,
            'uoms_resolved' => 0, 'controls_derived' => 0, 'activated' => 0, 'unresolved' => [],
        ];

        // A dry run does the real work and then throws it away, so the report
        // describes exactly what --apply would do rather than a second guess at it.
        DB::beginTransaction();
        try {
            $this->adoptTaxonomy($summary);
            $this->backfillMaterials($defaults, $summary);

            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();
            $this->error('Nothing was changed — '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Outcome', 'Count'], [
            ['Category groups created', $summary['groups_created']],
            ['Categories created', $summary['categories_created']],
            ['Materials linked to a category', $summary['materials_linked']],
            ['Units of measure resolved', $summary['uoms_resolved']],
            ['Disposition / tracking derived', $summary['controls_derived']],
            ['Promoted to Active', $summary['activated']],
        ]);

        if ($summary['unresolved'] !== []) {
            $this->newLine();
            $this->warn(count($summary['unresolved']).' material(s) have no category string at all and need a person:');
            foreach (array_slice($summary['unresolved'], 0, 15) as $line) {
                $this->line('  '.$line);
            }
            if (count($summary['unresolved']) > 15) {
                $this->line('  … and '.(count($summary['unresolved']) - 15).' more.');
            }
            $this->line('  Assign these from the Materials Library "Needs finishing" list.');
        }

        $this->newLine();
        $this->info($apply
            ? 'Applied. Review anything still listed under "Needs finishing".'
            : 'Dry run complete. Re-run with --apply to commit.');

        return self::SUCCESS;
    }

    /**
     * Turn every distinct legacy category/subcategory pair into real categories.
     * A material with a category but no subcategory gets a leaf named after the
     * group, so it still lands on a selectable leaf rather than a group.
     */
    private function adoptTaxonomy(array &$summary): void
    {
        $pairs = LibraryMaterial::whereNull('material_category_id')
            ->whereNotNull('category')
            ->select('category', 'subcategory')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            $groupName = trim((string) $pair->category);
            $leafName = trim((string) $pair->subcategory) ?: $groupName;

            $group = $this->findOrCreateCategory($groupName, null, $summary, 'groups_created');
            $this->findOrCreateCategory($leafName, $group, $summary, 'categories_created');
        }
    }

    private function findOrCreateCategory(string $name, ?MaterialCategory $parent, array &$summary, string $counter): MaterialCategory
    {
        $existing = MaterialCategory::withTrashed()
            ->where('parent_id', $parent?->id)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();

        if ($existing) {
            return $existing;
        }

        $summary[$counter]++;

        return MaterialCategory::create([
            'name' => $name,
            'parent_id' => $parent?->id,
            'code' => $this->deriveCode($name),
            'item_type_id' => $parent?->item_type_id,
            'default_issue_disposition' => $parent?->default_issue_disposition,
            'default_tracking_mode' => $parent?->default_tracking_mode,
            'is_active' => true,
            'is_selectable' => $parent !== null,
            'sort_order' => (int) MaterialCategory::where('parent_id', $parent?->id)->max('sort_order') + 1,
        ]);
    }

    private function backfillMaterials(MaterialDefaultsService $defaults, array &$summary): void
    {
        LibraryMaterial::with('materialCategory.parent')
            ->orderBy('id')
            ->chunkById(200, function ($materials) use ($defaults, &$summary) {
                foreach ($materials as $material) {
                    $changes = [];

                    if (! $material->material_category_id && filled($material->category)) {
                        $leaf = $this->locateLeaf($material);
                        if ($leaf) {
                            $changes['material_category_id'] = $leaf->id;
                            $summary['materials_linked']++;
                        }
                    }

                    if (! $material->material_category_id && blank($material->category)) {
                        $summary['unresolved'][] = "#{$material->id} {$material->material_name}";
                    }

                    // "PCS", "Sheet", "kg" already name real registry units — the
                    // string was simply never resolved onto the governed FK.
                    if (! $material->base_uom_id) {
                        $uomId = $defaults->resolveUomId($material->unit_of_measure);
                        if ($uomId) {
                            $changes['base_uom_id'] = $uomId;
                            $changes['issue_uom_id'] = $material->issue_uom_id ?: $uomId;
                            $summary['uoms_resolved']++;
                        }
                    }

                    $material->fill($changes);
                    if ($changes !== []) {
                        $material->setRelation('materialCategory', isset($changes['material_category_id'])
                            ? MaterialCategory::with('parent')->find($changes['material_category_id'])
                            : $material->materialCategory);
                    }

                    $derived = $defaults->apply($material->only(['material_category_id']), $material);
                    foreach (['item_type_id', 'issue_disposition', 'tracking_mode'] as $field) {
                        if (blank($material->{$field}) && filled($derived[$field] ?? null)) {
                            $material->{$field} = $derived[$field];
                            $summary['controls_derived']++;
                        }
                    }

                    if (! $material->isDirty()) {
                        continue;
                    }

                    $material->save();

                    if ($this->option('activate') && $material->item_status !== 'Active'
                        && MaterialCompleteness::isComplete($material)) {
                        $material->update(['item_status' => 'Active', 'is_active' => true]);
                        $summary['activated']++;
                    }
                }
            });
    }

    /**
     * The leaf a material's legacy strings point at, matched inside its own
     * group so two groups may each hold a leaf of the same name.
     */
    private function locateLeaf(LibraryMaterial $material): ?MaterialCategory
    {
        $groupName = trim((string) $material->category);
        $leafName = trim((string) $material->subcategory) ?: $groupName;

        $group = MaterialCategory::whereNull('parent_id')
            ->whereRaw('LOWER(name) = ?', [Str::lower($groupName)])->first();

        if (! $group) {
            return null;
        }

        return MaterialCategory::where('parent_id', $group->id)
            ->whereRaw('LOWER(name) = ?', [Str::lower($leafName)])->first();
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
        for ($suffix = 2; MaterialCategory::withTrashed()->where('code', $code)->exists(); $suffix++) {
            $code = $base.$suffix;
        }

        return $code;
    }
}
