<?php

namespace App\Modules\Design\Services;

use InvalidArgumentException;

class DimensionConversionService
{
    public function toMeters(null|float|int|string $value, string $unit = 'm'): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $numeric = (float) $value;

        return match ($unit) {
            'm' => round($numeric, 3),
            'cm' => round($numeric / 100, 3),
            'mm' => round($numeric / 1000, 3),
            default => throw new InvalidArgumentException("Unsupported dimension unit [{$unit}]."),
        };
    }

    public function normalize(array $data): array
    {
        $unit = $data['dimension_unit'] ?? 'm';

        $data['length_m'] = $this->toMeters($data['length_value'] ?? null, $unit);
        $data['width_m'] = $this->toMeters($data['width_value'] ?? null, $unit);
        $data['height_m'] = $this->toMeters($data['height_value'] ?? null, $unit);

        return $data;
    }
}
