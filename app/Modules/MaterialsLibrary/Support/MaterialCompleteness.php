<?php

namespace App\Modules\MaterialsLibrary\Support;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;

/**
 * A catalogue row is a name for a thing. Whether the system may *transact* it
 * is a separate, later question — and this class is the only place that
 * question is answered.
 *
 * Creation therefore asks for almost nothing, and everything the old form
 * demanded up front is required here instead: at the moment the item is
 * promoted to Active, which is the moment stock movement becomes possible.
 * Both checkIn() and adjustStock() already refuse anything that is not Active,
 * so this gate needs no new enforcement point.
 */
final class MaterialCompleteness
{
    /**
     * Governance fields a material must carry before it can move stock,
     * keyed by column with the label a person would recognise.
     */
    public const GOVERNANCE = [
        'material_code' => 'Item code',
        'material_category_id' => 'Category',
        'item_type_id' => 'Item type',
        'base_uom_id' => 'Base unit of measure',
        'issue_disposition' => 'Issue disposition',
        'tracking_mode' => 'Tracking mode',
    ];

    /**
     * What is still missing, as [field => label]. Empty means ready to activate.
     *
     * @return array<string,string>
     */
    public static function missing(LibraryMaterial $material): array
    {
        $missing = [];

        foreach (self::GOVERNANCE as $field => $label) {
            if (blank($material->{$field})) {
                $missing[$field] = $label;
            }
        }

        // A category may insist on specifications of its own — a board without a
        // thickness is not a usable board. Only checked once the category is
        // known, otherwise every draft would report the same downstream noise.
        if ($material->material_category_id) {
            $recorded = $material->attributes['attributes'] ?? [];
            foreach ($material->materialCategory?->resolvedAttributeSchema() ?? [] as $field) {
                if (($field['required'] ?? false) && blank($recorded[$field['key']] ?? null)) {
                    $missing['attributes.'.$field['key']] = $field['label'] ?? $field['key'];
                }
            }
        }

        return $missing;
    }

    public static function isComplete(LibraryMaterial $material): bool
    {
        return self::missing($material) === [];
    }

    /**
     * A material is only ever Active when it is complete. Anything short of that
     * waits under review — visible, searchable and editable, but not issuable.
     */
    public static function resolveStatus(LibraryMaterial $material, ?string $requested = null): string
    {
        if (! self::isComplete($material)) {
            return 'Under Review';
        }

        return in_array($requested, MaterialControl::STATUSES, true) ? $requested : 'Active';
    }
}
