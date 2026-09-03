<?php

namespace App\Modules\MaterialsLibrary\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\MaterialsLibrary\Support\MaterialControl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every question the taxonomy can already answer should be answered by the
 * taxonomy, leaving the typist to override only the unusual item.
 *
 * All six item types carry a default_issue_disposition and default_tracking_mode
 * and every category carries an item_type_id. That data was being served to the
 * frontend and applied nowhere, so ten fields were mandatory on a form whose
 * answers were mostly already known. This class is where they get read.
 */
class MaterialDefaultsService
{
    /**
     * Fill anything the caller left blank that the category can settle.
     * Supplied values always win — this only ever fills gaps.
     */
    public function apply(array $data, ?LibraryMaterial $existing = null): array
    {
        $categoryId = $data['material_category_id'] ?? $existing?->material_category_id;
        $category = $categoryId
            ? MaterialCategory::with('parent.itemType', 'itemType')->find($categoryId)
            : null;

        if (! $category) {
            return $data;
        }

        // Item type is owned by the category (or inherited from its group), and
        // it is what knows how the thing behaves.
        $itemType = $category->itemType ?? $category->parent?->itemType;
        $data['item_type_id'] ??= $existing?->item_type_id ?? $itemType?->id;

        $data['issue_disposition'] ??= $existing?->issue_disposition
            ?? $category->default_issue_disposition
            ?? $category->parent?->default_issue_disposition
            ?? $itemType?->default_issue_disposition;

        $data['tracking_mode'] ??= $existing?->tracking_mode
            ?? $category->default_tracking_mode
            ?? $category->parent?->default_tracking_mode
            ?? $itemType?->default_tracking_mode;

        // A derived pair still has to be physically possible. If the category and
        // item type disagree, drop the tracking mode rather than saving a
        // combination MaterialControl would later reject.
        if (! empty($data['issue_disposition']) && ! empty($data['tracking_mode'])
            && ! MaterialControl::compatible($data['issue_disposition'], $data['tracking_mode'])) {
            unset($data['tracking_mode']);
        }

        $data['base_uom_id'] ??= $existing?->base_uom_id ?? $this->defaultUomId($category);

        return $data;
    }

    /**
     * A category that allows exactly one unit has already made the choice.
     * Where several are allowed the first is offered, and where none are
     * configured the typist picks.
     */
    private function defaultUomId(MaterialCategory $category): ?int
    {
        $allowed = $category->allowed_uoms ?: $category->parent?->allowed_uoms;
        if (blank($allowed)) {
            return null;
        }

        // Preference follows the order the category lists, which MySQL cannot
        // express through whereIn — so resolve the codes and pick in PHP.
        $byCode = UnitOfMeasure::where('is_active', true)
            ->whereIn('code', (array) $allowed)
            ->pluck('id', 'code');

        foreach ((array) $allowed as $code) {
            if ($byCode->has($code)) {
                return (int) $byCode->get($code);
            }
        }

        return null;
    }

    /**
     * Next free item code for a category, optionally namespaced by workstation.
     *
     * Workstation is a routing fact, not an identity fact, so a code must be
     * derivable without one — otherwise making the workstation optional would
     * quietly re-break code generation.
     *
     * Format: [{WS}-]{CAT}-{0001}
     */
    public function suggestCode(MaterialCategory $category, ?string $workstationCode = null): string
    {
        $segment = fn (?string $value) => preg_replace('/[^A-Z0-9]/', '', strtoupper(substr(trim((string) $value), 0, 6)));

        $catSegment = $segment($category->code ?: $category->name) ?: 'GEN';
        $wsSegment = $workstationCode ? $segment($workstationCode) : null;

        $prefix = ($wsSegment ? $wsSegment.'-' : '').$catSegment.'-';

        // Seed from the count, then walk forward over any gaps or codes that a
        // soft-deleted row still occupies.
        $seq = DB::table('library_materials')->where('material_code', 'like', $prefix.'%')->count() + 1;

        do {
            $code = $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $seq++;
        } while (DB::table('library_materials')->where('material_code', $code)->exists());

        return $code;
    }

    /**
     * Resolve a free-text unit against the registry so legacy strings such as
     * "PCS" or "Sheet" settle onto the governed unit rather than being retyped.
     */
    public function resolveUomId(?string $unit): ?int
    {
        if (blank($unit)) {
            return null;
        }

        $needle = Str::lower(trim($unit));

        return UnitOfMeasure::where('is_active', true)
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(code) = ?', [$needle])
                ->orWhereRaw('LOWER(name) = ?', [$needle]))
            ->value('id');
    }
}
