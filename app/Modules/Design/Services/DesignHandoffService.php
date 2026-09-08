<?php

namespace App\Modules\Design\Services;

use App\Modules\Design\Models\DesignHandoff;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Printing\Models\PrintJob;
use Illuminate\Validation\ValidationException;

class DesignHandoffService
{
    public function createPrintingHandoffOnce(DesignItem $item): DesignHandoff
    {
        $item->loadMissing(['job.enquiry.client', 'type', 'printMaterial', 'documents']);

        $existing = $item->handoffs()
            ->where('target_module', 'printing')
            ->latest()
            ->first();

        $artwork = $this->finalArtworkLink($item);

        if ($existing) {
            $existing->update([
                'status' => 'pending',
                'target_record_id' => null,
                'payload_snapshot' => $this->printingPayload($item, $artwork),
                'rejection_reason' => null,
                'handed_off_by' => auth()->id(),
                'handed_off_at' => now(),
                'responded_by' => null,
                'responded_at' => null,
            ]);

            return $existing->fresh();
        }

        return DesignHandoff::create([
            'design_item_id' => $item->id,
            'target_module' => 'printing',
            'status' => 'pending',
            'payload_snapshot' => $this->printingPayload($item, $artwork),
            'handed_off_by' => auth()->id(),
            'handed_off_at' => now(),
        ]);
    }

    public function cancelPrintingQueueForChanges(DesignItem $item, string $reason = 'Returned to Design for changes.'): void
    {
        $handoffs = $item->handoffs()
            ->where('target_module', 'printing')
            ->whereIn('status', ['pending', 'accepted'])
            ->get();

        foreach ($handoffs as $handoff) {
            $job = $handoff->target_record_id
                ? PrintJob::find($handoff->target_record_id)
                : PrintJob::query()
                    ->where('design_handoff_id', $handoff->id)
                    ->where('design_item_id', $item->id)
                    ->latest()
                    ->first();

            if ($job && $job->status === 'queued') {
                $job->update([
                    'status' => 'cancelled',
                    'updated_by' => auth()->id(),
                ]);

                $job->events()->create([
                    'event_type' => 'cancelled_for_design_changes',
                    'from_status' => 'queued',
                    'to_status' => 'cancelled',
                    'reason' => $reason,
                    'created_by' => auth()->id(),
                ]);
            }

            if (!$job || in_array($job->status, ['queued', 'cancelled'], true)) {
                $handoff->update([
                    'status' => 'cancelled',
                    'rejection_reason' => $reason,
                    'responded_by' => auth()->id(),
                    'responded_at' => now(),
                ]);
            }
        }
    }

    public function reject(DesignHandoff $handoff, string $reason): DesignHandoff
    {
        if ($handoff->status === 'accepted') {
            throw ValidationException::withMessages([
                'status' => ['Accepted handoffs cannot be rejected.'],
            ]);
        }

        $handoff->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'responded_by' => auth()->id(),
            'responded_at' => now(),
        ]);

        return $handoff->fresh();
    }

    public function accept(DesignHandoff $handoff, ?int $targetRecordId = null): DesignHandoff
    {
        if ($handoff->status === 'rejected') {
            throw ValidationException::withMessages([
                'status' => ['Rejected handoffs cannot be accepted without a new review.'],
            ]);
        }

        $handoff->update([
            'status' => 'accepted',
            'target_record_id' => $targetRecordId ?? $handoff->target_record_id,
            'responded_by' => auth()->id(),
            'responded_at' => now(),
        ]);

        return $handoff->fresh();
    }

    private function finalArtworkLink(DesignItem $item)
    {
        $artwork = $item->documents
            ->where('status', 'active')
            ->where('document_type', 'artwork')
            ->where('source', 'link')
            ->sortByDesc(fn ($document) => sprintf(
                '%010d-%010d-%s',
                (int) ($document->version ?? 0),
                (int) $document->id,
                optional($document->created_at)->timestamp ?? 0
            ))
            ->first();

        if (!$artwork) {
            throw ValidationException::withMessages([
                'documents' => ['Attach an active Artwork link before syncing to Printing.'],
            ]);
        }

        return $artwork;
    }

    private function printingPayload(DesignItem $item, $artwork): array
    {
        return [
            'design_item_id' => $item->id,
            'design_job_id' => $item->design_job_id,
            'redesign_of_item_id' => $item->redesign_of_item_id,
            'redesign_of_print_job_id' => $item->redesign_of_print_job_id,
            'redesign_source' => $item->redesign_source,
            'redesign_reason' => $item->redesign_reason,
            'project_enquiry_id' => $item->job?->project_enquiry_id,
            'project_id' => $item->job?->project_id,
            'client_id' => $item->job?->client_id,
            'client_name' => $item->job?->enquiry?->client?->full_name
                ?? $item->job?->enquiry?->client?->name,
            'job_number' => $item->job?->job_number,
            'project_name' => $item->job?->enquiry?->title ?? $item->job?->title,
            'job_title' => $item->job?->title,
            'title' => $item->title,
            'type' => $item->type?->name,
            'quantity' => $item->quantity !== null ? (float) $item->quantity : null,
            'dimension_unit' => $item->dimension_unit,
            'length_value' => $item->length_value !== null ? (float) $item->length_value : null,
            'width_value' => $item->width_value !== null ? (float) $item->width_value : null,
            'length_m' => $item->length_m !== null ? (float) $item->length_m : null,
            'width_m' => $item->width_m !== null ? (float) $item->width_m : null,
            'design_height_m' => $item->width_m !== null ? (float) $item->width_m : null,
            'design_length_m' => $item->length_m !== null ? (float) $item->length_m : null,
            'print_width_m' => $item->width_m !== null ? (float) $item->width_m : null,
            'running_length_m' => $item->length_m !== null ? (float) $item->length_m : null,
            'print_material_id' => $item->print_material_id,
            'print_material_name' => $item->printMaterial?->material_name
                ?? $item->printMaterial?->name,
            'print_notes' => $item->print_notes,
            'final_artwork' => [
                'id' => $artwork->id,
                'name' => $artwork->name,
                'url' => $artwork->external_url ?: $artwork->file_path,
                'version' => $artwork->version,
            ],
            'synced_at' => now()->toISOString(),
        ];
    }
}