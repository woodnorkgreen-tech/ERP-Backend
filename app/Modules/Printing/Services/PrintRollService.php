<?php

namespace App\Modules\Printing\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\Printing\Models\PrintRoll;
use Illuminate\Support\Carbon;

class PrintRollService
{
    public function createRoll(array $data): PrintRoll
    {
        $material = LibraryMaterial::findOrFail($data['material_id']);
        $receivedAt = Carbon::parse($data['received_at'] ?? now())->toDateString();
        $sequence = $this->nextDisplaySequence($material->id, $receivedAt);
        $length = (float) $data['received_length_m'];

        return PrintRoll::create([
            'material_id' => $material->id,
            'source_inventory_log_id' => $data['source_inventory_log_id'] ?? null,
            'print_material_request_id' => $data['print_material_request_id'] ?? null,
            'material_code_snapshot' => $material->material_code,
            'material_name_snapshot' => $material->material_name,
            'roll_code' => $this->nextRollCode($material),
            'display_label' => sprintf('%s - %s - Roll %02d', $material->material_name, $receivedAt, $sequence),
            'received_sequence' => $sequence,
            'received_at' => $receivedAt,
            'received_length_m' => $length,
            'remaining_length_m' => $length,
            'roll_width_m' => $data['roll_width_m'] ?? null,
            'status' => $data['status'] ?? 'active',
            'location' => $data['location'] ?? 'Printing',
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
        ]);
    }

    public function deduct(PrintRoll $roll, float $quantity): PrintRoll
    {
        $remaining = max(0, (float) $roll->remaining_length_m - $quantity);

        $roll->update([
            'remaining_length_m' => $remaining,
            'status' => $remaining <= 0 ? 'depleted' : $roll->status,
        ]);

        return $roll->fresh();
    }

    public function adjust(PrintRoll $roll, float $remaining, ?string $reason = null): PrintRoll
    {
        $roll->update([
            'remaining_length_m' => max(0, $remaining),
            'status' => $remaining <= 0 ? 'depleted' : ($roll->status === 'depleted' ? 'active' : $roll->status),
            'notes' => trim(($roll->notes ? $roll->notes . "\n" : '') . ($reason ? 'Adjustment: ' . $reason : 'Balance adjusted')),
        ]);

        return $roll->fresh();
    }

    private function nextRollCode(LibraryMaterial $material): string
    {
        $prefix = trim((string) $material->material_code) ?: 'PRINT-MAT-' . $material->id;
        $lastId = (int) (PrintRoll::max('id') ?? 0) + 1;

        return sprintf('%s-R%06d', $prefix, $lastId);
    }

    private function nextDisplaySequence(int $materialId, string $date): int
    {
        return ((int) PrintRoll::where('material_id', $materialId)
            ->whereDate('received_at', $date)
            ->max('received_sequence')) + 1;
    }
}
