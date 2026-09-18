<?php

namespace App\Modules\Printing\Support;

final class PrintVarianceReason
{
    public const VALUES = [
        'damaged_material',
        'pixelated_artwork',
        'colour_correction',
        'printer_calibration',
        'printhead_cleaning',
        'material_alignment',
        'media_jam',
        'equipment_fault',
        'power_interruption',
        'incorrect_dimensions',
        'incorrect_artwork',
        'cutting_finishing_error',
        'operator_error',
        'client_change',
        'site_measurement_change',
        'quality_failure_reprint',
        'other',
    ];
}
