<?php

namespace App\Modules\Printing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintJobConsumptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'print_job_id' => $this->print_job_id,
            'print_roll_id' => $this->print_roll_id,
            'material_id' => $this->material_id,
            'artwork_width_m' => $this->float('artwork_width_m'),
            'artwork_height_m' => $this->float('artwork_height_m'),
            'artwork_count' => $this->artwork_count,
            'quantity' => $this->float('quantity'),
            'bleed_preset' => $this->bleed_preset,
            'bleed_left_m' => $this->float('bleed_left_m'),
            'bleed_right_m' => $this->float('bleed_right_m'),
            'bleed_top_m' => $this->float('bleed_top_m'),
            'bleed_bottom_m' => $this->float('bleed_bottom_m'),
            'spacing_m' => $this->float('spacing_m'),
            'setup_allowance_m' => $this->float('setup_allowance_m'),
            'calculated_print_width_m' => $this->float('calculated_print_width_m'),
            'calculated_print_length_m' => $this->float('calculated_print_length_m'),
            'calculated_sqm' => $this->float('calculated_sqm'),
            'calculated_running_m' => $this->float('calculated_running_m'),
            'actual_running_m' => $this->float('actual_running_m'),
            'variance_m' => $this->float('variance_m'),
            'variance_percent' => $this->float('variance_percent'),
            'variance_reason' => $this->variance_reason,
            'roll' => $this->whenLoaded('roll', fn () => new PrintRollResource($this->roll)),
        ];
    }

    private function float(string $field): ?float
    {
        return $this->{$field} !== null ? (float) $this->{$field} : null;
    }
}
