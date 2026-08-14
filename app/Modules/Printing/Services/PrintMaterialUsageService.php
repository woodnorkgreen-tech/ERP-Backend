<?php

namespace App\Modules\Printing\Services;

use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintJobConsumption;
use App\Modules\Printing\Models\PrintManualConsumption;
use App\Modules\Printing\Models\PrintRoll;
use Illuminate\Support\Facades\DB;

class PrintMaterialUsageService
{
    public function __construct(
        private readonly PrintCalculationService $calculator,
        private readonly PrintRollService $rolls
    ) {
    }

    public function saveJobConsumption(PrintJob $job, array $data): PrintJobConsumption
    {
        return DB::transaction(function () use ($job, $data) {
            $consumption = $job->consumptions()->latest('id')->lockForUpdate()->first();
            $roll = PrintRoll::query()->lockForUpdate()->findOrFail($data['print_roll_id']);
            $data['artwork_width_m'] = $data['artwork_width_m'] ?? $job->print_width_m;
            $data['artwork_height_m'] = $data['artwork_height_m'] ?? $job->running_length_m;
            $data['quantity'] = $data['quantity'] ?? $job->artwork_quantity ?? 1;
            $data['tile_count'] = $data['tile_count'] ?? 1;
            $calculated = $this->calculator->calculate($data);
            $actual = (float) ($data['actual_running_m'] ?? $calculated['calculated_running_m'] ?? 0);
            $previousActual = $consumption ? (float) $consumption->actual_running_m : 0;
            $previousRollId = $consumption?->print_roll_id;

            $payload = array_merge($data, $calculated, [
                'material_id' => $roll->material_id,
            ]);

            if ($consumption) {
                $consumption->update($payload);
            } else {
                $consumption = $job->consumptions()->create($payload);
            }

            $this->applyRollConsumptionChange($roll, $actual, $previousActual, $previousRollId);

            $job->events()->create([
                'event_type' => $previousRollId ? 'material_consumption_updated' : 'material_consumed',
                'payload' => [
                    'print_roll_id' => $roll->id,
                    'actual_running_m' => $actual,
                    'previous_print_roll_id' => $previousRollId,
                    'previous_actual_running_m' => $previousActual,
                ],
                'created_by' => auth()->id(),
            ]);

            return $consumption->fresh(['roll', 'material']);
        });
    }

    private function applyRollConsumptionChange(PrintRoll $roll, float $actual, float $previousActual, ?int $previousRollId): void
    {
        if ($previousRollId && $previousRollId !== $roll->id) {
            $previousRoll = PrintRoll::query()->lockForUpdate()->find($previousRollId);
            if ($previousRoll) {
                $this->setRollRemaining($previousRoll, (float) $previousRoll->remaining_length_m + $previousActual);
            }

            $this->setRollRemaining($roll, (float) $roll->remaining_length_m - $actual);
            return;
        }

        $this->setRollRemaining($roll, (float) $roll->remaining_length_m + $previousActual - $actual);
    }

    private function setRollRemaining(PrintRoll $roll, float $remaining): void
    {
        $remaining = max(0, $remaining);

        $roll->update([
            'remaining_length_m' => $remaining,
            'status' => $remaining <= 0 ? 'depleted' : ($roll->status === 'depleted' ? 'active' : $roll->status),
        ]);
    }

    public function manualConsumption(array $data): PrintManualConsumption
    {
        $roll = PrintRoll::findOrFail($data['print_roll_id']);
        $quantity = (float) $data['quantity_m'];

        return DB::transaction(function () use ($roll, $data, $quantity) {
            $consumption = PrintManualConsumption::create($data + [
                'material_id' => $roll->material_id,
                'consumed_at' => $data['consumed_at'] ?? now(),
                'created_by' => auth()->id(),
            ]);

            $this->rolls->deduct($roll, $quantity);

            return $consumption->fresh(['roll', 'material', 'operator']);
        });
    }
}
