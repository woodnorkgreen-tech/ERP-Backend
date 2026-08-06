<?php

namespace App\Modules\MaterialsLibrary\Support;

final class MaterialControl
{
    public const STATUSES = ['Active', 'Inactive', 'Discontinued', 'Blocked', 'Under Review'];
    public const DISPOSITIONS = ['consumed', 'returnable', 'recoverable_remainder'];
    public const TRACKING_MODES = ['bulk_quantity', 'lot_batch', 'serialized_item', 'dimension_piece'];

    public static function compatible(string $disposition, string $trackingMode): bool
    {
        return match ($disposition) {
            'consumed' => in_array($trackingMode, ['bulk_quantity', 'lot_batch', 'serialized_item'], true),
            'returnable' => in_array($trackingMode, ['bulk_quantity', 'serialized_item'], true),
            'recoverable_remainder' => $trackingMode === 'dimension_piece',
            default => false,
        };
    }

    public static function legacyMaterialType(string $disposition): string
    {
        return in_array($disposition, ['returnable', 'recoverable_remainder'], true)
            ? 'reusable'
            : 'consumable';
    }

    public static function legacyUsageType(string $disposition): string
    {
        return $disposition === 'consumed' ? 'consumable' : 'reusable';
    }
}
