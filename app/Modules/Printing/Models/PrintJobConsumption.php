<?php

namespace App\Modules\Printing\Models;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintJobConsumption extends Model
{
    protected $fillable = [
        'print_job_id',
        'print_roll_id',
        'material_id',
        'artwork_width_m',
        'artwork_height_m',
        'artwork_count',
        'quantity',
        'bleed_preset',
        'bleed_left_m',
        'bleed_right_m',
        'bleed_top_m',
        'bleed_bottom_m',
        'spacing_m',
        'setup_allowance_m',
        'calculated_print_width_m',
        'calculated_print_length_m',
        'calculated_sqm',
        'calculated_running_m',
        'actual_running_m',
        'variance_m',
        'variance_percent',
        'variance_reason',
    ];

    protected $casts = [
        'artwork_width_m' => 'decimal:3',
        'artwork_height_m' => 'decimal:3',
        'artwork_count' => 'integer',
        'quantity' => 'decimal:3',
        'bleed_left_m' => 'decimal:3',
        'bleed_right_m' => 'decimal:3',
        'bleed_top_m' => 'decimal:3',
        'bleed_bottom_m' => 'decimal:3',
        'spacing_m' => 'decimal:3',
        'setup_allowance_m' => 'decimal:3',
        'calculated_print_width_m' => 'decimal:3',
        'calculated_print_length_m' => 'decimal:3',
        'calculated_sqm' => 'decimal:3',
        'calculated_running_m' => 'decimal:3',
        'actual_running_m' => 'decimal:3',
        'variance_m' => 'decimal:3',
        'variance_percent' => 'decimal:3',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class, 'print_job_id');
    }

    public function roll(): BelongsTo
    {
        return $this->belongsTo(PrintRoll::class, 'print_roll_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(LibraryMaterial::class, 'material_id');
    }
}
