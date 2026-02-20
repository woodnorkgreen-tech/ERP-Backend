<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\ProductionNcr;
use App\Modules\Production\Models\ProductionNcrEvent;

class ProductionNcrService
{
    public function upsertFromQcFailure(array $data): ProductionNcr
    {
        $existing = ProductionNcr::where('work_order_id', $data['work_order_id'])
            ->where('source_type', $data['source_type'])
            ->where('source_ref', $data['source_ref'] ?? null)
            ->whereIn('status', ['open', 'assigned', 'in_progress', 'pending_reinspection'])
            ->first();

        if ($existing) {
            $previousStatus = $existing->status;

            $existing->update([
                'work_order_rework_id' => $data['work_order_rework_id'] ?? $existing->work_order_rework_id,
                'qc_stage' => $data['qc_stage'] ?? $existing->qc_stage,
                'workstation' => $data['workstation'] ?? $existing->workstation,
                'description' => $data['description'],
                'severity' => $data['severity'] ?? $existing->severity,
                'detected_at' => now(),
                'detected_by' => $data['detected_by'] ?? $existing->detected_by,
                'status' => 'open',
            ]);

            if ($previousStatus !== 'open') {
                $this->recordEvent(
                    $existing->id,
                    'status_changed',
                    $previousStatus,
                    'open',
                    'NCR was reopened from QC failure.',
                    $data['detected_by'] ?? null
                );
            }

            return $existing;
        }

        $ncr = ProductionNcr::create([
            'ncr_number' => $this->generateNcrNumber(),
            'work_order_id' => $data['work_order_id'],
            'work_order_rework_id' => $data['work_order_rework_id'] ?? null,
            'source_type' => $data['source_type'],
            'source_ref' => $data['source_ref'] ?? null,
            'qc_stage' => $data['qc_stage'] ?? null,
            'workstation' => $data['workstation'] ?? null,
            'severity' => $data['severity'] ?? $this->inferSeverity($data['description']),
            'status' => 'open',
            'description' => $data['description'],
            'detected_at' => now(),
            'detected_by' => $data['detected_by'] ?? null,
            'created_by' => $data['detected_by'] ?? null,
        ]);

        $this->recordEvent(
            $ncr->id,
            'created',
            null,
            'open',
            'NCR created from QC failure.',
            $data['detected_by'] ?? null
        );

        return $ncr;
    }

    private function recordEvent(
        int $ncrId,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $note,
        ?int $performedBy
    ): void {
        ProductionNcrEvent::create([
            'ncr_id' => $ncrId,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'performed_by' => $performedBy,
            'performed_at' => now(),
        ]);
    }

    public function generateNcrNumber(): string
    {
        $prefix = 'NCR-' . now()->format('Ymd') . '-';
        $latestId = ProductionNcr::max('id') ?? 0;
        $sequence = $latestId + 1;

        do {
            $candidate = $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $exists = ProductionNcr::where('ncr_number', $candidate)->exists();
            $sequence++;
        } while ($exists);

        return $candidate;
    }

    private function inferSeverity(string $description): string
    {
        $text = strtolower($description);

        if (
            str_contains($text, 'structural') ||
            str_contains($text, 'electrical') ||
            str_contains($text, 'safety') ||
            str_contains($text, 'hazard')
        ) {
            return 'critical';
        }

        return 'major';
    }
}
