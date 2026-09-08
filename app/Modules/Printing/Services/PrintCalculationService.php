<?php

namespace App\Modules\Printing\Services;

class PrintCalculationService
{
    private const DEFAULT_SETUP_ALLOWANCE_M = 0.1;

    public function calculate(array $data): array
    {
        $width = (float) ($data['artwork_width_m'] ?? 0);
        $height = (float) ($data['artwork_height_m'] ?? 0);
        $count = max(1, (int) ($data['artwork_count'] ?? 1));
        $quantity = max(1, (float) ($data['quantity'] ?? 1));
        $tiles = max(1, (int) ($data['tile_count'] ?? 1));
        $left = (float) ($data['bleed_left_m'] ?? 0);
        $right = (float) ($data['bleed_right_m'] ?? 0);
        $top = (float) ($data['bleed_top_m'] ?? 0);
        $bottom = (float) ($data['bleed_bottom_m'] ?? 0);
        $spacing = (float) ($data['spacing_m'] ?? 0);
        $setup = (float) ($data['setup_allowance_m'] ?? self::DEFAULT_SETUP_ALLOWANCE_M);
        $actual = isset($data['actual_running_m']) ? (float) $data['actual_running_m'] : null;

        $printWidth = max(0, $width + $left + $right);
        $printLength = max(0, (($height + $top + $bottom + $spacing) * $count * $quantity * $tiles) + $setup);
        $sqm = $printWidth * $printLength;
        $running = $printLength;
        $variance = $actual !== null ? $actual - $running : null;

        return [
            'calculated_print_width_m' => round($printWidth, 3),
            'calculated_print_length_m' => round($printLength, 3),
            'calculated_sqm' => round($sqm, 3),
            'calculated_running_m' => round($running, 3),
            'variance_m' => $variance !== null ? round($variance, 3) : null,
            'variance_percent' => ($variance !== null && $running > 0)
                ? round(($variance / $running) * 100, 3)
                : null,
        ];
    }
}
