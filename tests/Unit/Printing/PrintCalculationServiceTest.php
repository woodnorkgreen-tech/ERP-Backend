<?php

namespace Tests\Unit\Printing;

use App\Modules\Printing\Services\PrintCalculationService;
use PHPUnit\Framework\TestCase;

class PrintCalculationServiceTest extends TestCase
{
    public function test_it_calculates_print_dimensions_usage_and_variance(): void
    {
        $result = (new PrintCalculationService())->calculate([
            'artwork_width_m' => 1.2,
            'artwork_height_m' => 2,
            'artwork_count' => 2,
            'quantity' => 3,
            'bleed_left_m' => 0.05,
            'bleed_right_m' => 0.05,
            'bleed_top_m' => 0.1,
            'bleed_bottom_m' => 0.1,
            'spacing_m' => 0.02,
            'setup_allowance_m' => 0.5,
            'actual_running_m' => 14,
        ]);

        $this->assertSame(1.3, $result['calculated_print_width_m']);
        $this->assertSame(13.82, $result['calculated_print_length_m']);
        $this->assertSame(17.966, $result['calculated_sqm']);
        $this->assertSame(13.82, $result['calculated_running_m']);
        $this->assertSame(0.18, $result['variance_m']);
        $this->assertSame(1.302, $result['variance_percent']);
    }

    public function test_it_multiplies_running_length_by_tile_count(): void
    {
        $result = (new PrintCalculationService())->calculate([
            'artwork_width_m' => 2,
            'artwork_height_m' => 3,
            'quantity' => 1,
            'tile_count' => 2,
            'setup_allowance_m' => 0,
            'actual_running_m' => 6.2,
        ]);

        $this->assertSame(6.0, $result['calculated_print_length_m']);
        $this->assertSame(6.0, $result['calculated_running_m']);
        $this->assertSame(12.0, $result['calculated_sqm']);
        $this->assertSame(0.2, $result['variance_m']);
    }

    public function test_it_defaults_setup_allowance_to_five_centimeters_at_start_and_end(): void
    {
        $result = (new PrintCalculationService())->calculate([
            'artwork_width_m' => 1,
            'artwork_height_m' => 2,
            'quantity' => 1,
        ]);

        $this->assertSame(2.1, $result['calculated_print_length_m']);
        $this->assertSame(2.1, $result['calculated_running_m']);
        $this->assertSame(2.1, $result['calculated_sqm']);
    }
}
