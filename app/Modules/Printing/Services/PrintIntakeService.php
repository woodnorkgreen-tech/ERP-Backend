<?php

namespace App\Modules\Printing\Services;

use App\Modules\Design\Models\DesignHandoff;
use App\Modules\Design\Services\DesignHandoffService;
use App\Modules\Printing\Models\PrintJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrintIntakeService
{
    public function __construct(private readonly DesignHandoffService $handoffs)
    {
    }

    public function accept(DesignHandoff $handoff): PrintJob
    {
        if ($handoff->target_module !== 'printing') {
            throw ValidationException::withMessages(['handoff' => ['Only printing handoffs can be accepted here.']]);
        }

        return DB::transaction(function () use ($handoff) {
            $handoff = DesignHandoff::query()->whereKey($handoff->id)->lockForUpdate()->firstOrFail();

            $payload = $handoff->payload_snapshot ?? [];
            $artwork = $payload['final_artwork'] ?? [];
            $reprintOfJobId = $payload['redesign_of_print_job_id'] ?? null;
            $isRedesignReprint = $reprintOfJobId !== null;
            $artworkVersion = $this->artworkVersion($artwork['version'] ?? null, $isRedesignReprint, $reprintOfJobId);

            $existing = $handoff->target_record_id
                ? PrintJob::query()->whereKey($handoff->target_record_id)->first()
                : null;

            if (!$existing && !$isRedesignReprint) {
                $existing = PrintJob::query()
                    ->where('original_design_handoff_id', $handoff->id)
                    ->oldest()
                    ->first();
            }

            if (!$existing) {
                $existing = PrintJob::query()
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->where(function ($query) use ($handoff, $isRedesignReprint) {
                        $query->where('design_handoff_id', $handoff->id);

                        if (!$isRedesignReprint) {
                            $query->orWhere(fn ($candidate) => $candidate
                                ->where('design_item_id', $handoff->design_item_id)
                                ->where('order_type', 'original')
                                ->whereNull('reprint_of_job_id'));
                        }
                    })
                    ->oldest()
                    ->first();
            }

            if ($existing) {
                if ($existing->isLocked()) {
                    $this->handoffs->accept($handoff, $existing->id);

                    return $existing->fresh(['consumptions.roll', 'operator', 'machine']);
                }

                $existing->update(array_filter([
                    'design_handoff_id' => $existing->design_handoff_id ?: $handoff->id,
                    'original_design_handoff_id' => $isRedesignReprint ? null : ($existing->original_design_handoff_id ?: $handoff->id),
                    'design_item_id' => $payload['design_item_id'] ?? $handoff->design_item_id,
                    'design_job_id' => $payload['design_job_id'] ?? null,
                    'project_enquiry_id' => $payload['project_enquiry_id'] ?? null,
                    'project_id' => $payload['project_id'] ?? null,
                    'client_id' => $payload['client_id'] ?? null,
                    'job_number' => $payload['job_number'] ?? null,
                    'project_name' => $payload['project_name'] ?? $payload['job_title'] ?? null,
                    'client_name' => $payload['client_name'] ?? null,
                    'title' => $payload['title'] ?? 'Print job',
                    'description' => $payload['type'] ?? null,
                    'final_artwork_url' => $artwork['url'] ?? null,
                    'final_artwork_document_id' => $artwork['id'] ?? null,
                    'artwork_version' => $artworkVersion,
                    'design_height_m' => $payload['design_height_m'] ?? $payload['width_m'] ?? null,
                    'design_length_m' => $payload['design_length_m'] ?? $payload['length_m'] ?? null,
                    'print_width_m' => $payload['print_width_m'] ?? $payload['width_m'] ?? null,
                    'running_length_m' => $payload['running_length_m'] ?? $payload['length_m'] ?? null,
                    'artwork_quantity' => $payload['quantity'] ?? null,
                    'order_type' => $isRedesignReprint ? 'reprint' : $existing->order_type,
                    'reprint_of_job_id' => $reprintOfJobId ?? $existing->reprint_of_job_id,
                    'reprint_reason' => $payload['redesign_reason'] ?? $existing->reprint_reason,
                    'remarks' => $payload['print_notes'] ?? $existing->remarks,
                    'updated_by' => auth()->id(),
                ], fn ($value) => $value !== null));

                // A revised design may explicitly clear either handoff field.
                $existing->update([
                    'application_surface' => $payload['application_surface'] ?? null,
                    'bleed_per_side_m' => $payload['bleed_per_side_m'] ?? null,
                ]);

                // Carry the design's outside partner only when it is still set. A design that
                // has returned to internal work must not clear a print-side outsourcing decision.
                if (! empty($payload['outsourced_partner_name'])) {
                    $existing->update($this->outsourcingFromPayload($payload));
                }

                $this->handoffs->accept($handoff, $existing->id);

                return $existing->fresh(['consumptions.roll', 'operator', 'machine']);
            }

            $job = PrintJob::create([
                'design_handoff_id' => $handoff->id,
                'original_design_handoff_id' => $isRedesignReprint ? null : $handoff->id,
                'design_item_id' => $payload['design_item_id'] ?? $handoff->design_item_id,
                'design_job_id' => $payload['design_job_id'] ?? null,
                'project_enquiry_id' => $payload['project_enquiry_id'] ?? null,
                'project_id' => $payload['project_id'] ?? null,
                'client_id' => $payload['client_id'] ?? null,
                'job_number' => $payload['job_number'] ?? null,
                'project_name' => $payload['project_name'] ?? $payload['job_title'] ?? null,
                'client_name' => $payload['client_name'] ?? null,
                'title' => $payload['title'] ?? 'Print job',
                'description' => $payload['type'] ?? null,
                'final_artwork_url' => $artwork['url'] ?? null,
                'final_artwork_document_id' => $artwork['id'] ?? null,
                'artwork_version' => $artworkVersion,
                'design_height_m' => $payload['design_height_m'] ?? $payload['width_m'] ?? null,
                'design_length_m' => $payload['design_length_m'] ?? $payload['length_m'] ?? null,
                'print_width_m' => $payload['print_width_m'] ?? $payload['width_m'] ?? null,
                'running_length_m' => $payload['running_length_m'] ?? $payload['length_m'] ?? null,
                'artwork_quantity' => $payload['quantity'] ?? null,
                'application_surface' => $payload['application_surface'] ?? null,
                'bleed_per_side_m' => $payload['bleed_per_side_m'] ?? null,
                ...$this->outsourcingFromPayload($payload),
                'order_type' => $isRedesignReprint ? 'reprint' : 'original',
                'reprint_of_job_id' => $reprintOfJobId,
                'reprint_reason' => $payload['redesign_reason'] ?? null,
                'status' => 'queued',
                'remarks' => $payload['print_notes'] ?? null,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            $this->handoffs->accept($handoff, $job->id);
            $job->events()->create([
                'event_type' => 'accepted_from_design',
                'to_status' => $job->status,
                'payload' => ['design_handoff_id' => $handoff->id],
                'created_by' => auth()->id(),
            ]);

            return $job->fresh(['consumptions.roll', 'operator', 'machine']);
        });
    }

    /**
     * Outsourcing fields carried from a design handoff. Empty when the design item is not outsourced.
     *
     * @return array<string, mixed>
     */
    private function outsourcingFromPayload(array $payload): array
    {
        $partner = $payload['outsourced_partner_name'] ?? null;

        return [
            'outsourced_partner_name' => $partner,
            'outsourced_reason' => $payload['outsourced_reason'] ?? null,
            'outsourced_reason_note' => $payload['outsourced_reason_note'] ?? null,
            'outsourced_by' => $partner !== null ? auth()->id() : null,
            'outsourced_at' => $partner !== null ? now() : null,
        ];
    }

    public function reject(DesignHandoff $handoff, string $reason): DesignHandoff
    {
        if ($handoff->target_module !== 'printing') {
            throw ValidationException::withMessages(['handoff' => ['Only printing handoffs can be rejected here.']]);
        }

        return $this->handoffs->reject($handoff, $reason);
    }

    private function artworkVersion(mixed $version, bool $isReprint, ?int $originalJobId = null): int
    {
        $documentVersion = max(1, (int) ($version ?? 1));

        if (!$isReprint) {
            return $documentVersion;
        }

        $originalVersion = $originalJobId
            ? (int) (PrintJob::query()->whereKey($originalJobId)->value('artwork_version') ?? 1)
            : 1;

        return max(2, $documentVersion, $originalVersion + 1);
    }
}
