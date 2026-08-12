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
        $roll = PrintRoll::findOrFail($data['print_roll_id']);
        $calculated = $this->calculator->calculate($data);
        $actual = (float) ($data['actual_running_m'] ?? $calculated['calculated_running_m'] ?? 0);

        return DB::transaction(function () use ($job, $roll, $data, $calculated, $actual) {
            $consumption = $job->consumptions()->create(array_merge($data, $calculated, [
                'material_id' => $roll->material_id,
            ]));

            $this->rolls->deduct($roll, $actual);

            $job->events()->create([
                'event_type' => 'material_consumed',
                'payload' => [
                    'print_roll_id' => $roll->id,
                    'actual_running_m' => $actual,
                ],
                'created_by' => auth()->id(),
            ]);

            return $consumption->fresh(['roll', 'material']);
        });
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
