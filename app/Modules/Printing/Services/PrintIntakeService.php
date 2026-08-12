<?php

namespace App\Modules\Printing\Services;

use App\Modules\Design\Models\DesignHandoff;
use App\Modules\Design\Services\DesignHandoffService;
use App\Modules\Printing\Models\PrintJob;
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

        if ($handoff->status === 'accepted' && $handoff->target_record_id) {
            return PrintJob::findOrFail($handoff->target_record_id);
        }

        $payload = $handoff->payload_snapshot ?? [];
        $artwork = $payload['final_artwork'] ?? [];

        $job = PrintJob::create([
            'design_handoff_id' => $handoff->id,
            'design_item_id' => $payload['design_item_id'] ?? $handoff->design_item_id,
            'design_job_id' => $payload['design_job_id'] ?? null,
            'project_enquiry_id' => $payload['project_enquiry_id'] ?? null,
            'project_id' => $payload['project_id'] ?? null,
            'client_id' => $payload['client_id'] ?? null,
            'job_number' => $payload['job_number'] ?? null,
            'project_name' => $payload['job_title'] ?? null,
            'client_name' => $payload['client_name'] ?? null,
            'title' => $payload['title'] ?? 'Print job',
            'description' => $payload['type'] ?? null,
            'final_artwork_url' => $artwork['url'] ?? null,
            'final_artwork_document_id' => $artwork['id'] ?? null,
            'artwork_version' => $artwork['version'] ?? null,
            'order_type' => 'original',
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
    }

    public function reject(DesignHandoff $handoff, string $reason): DesignHandoff
    {
        if ($handoff->target_module !== 'printing') {
            throw ValidationException::withMessages(['handoff' => ['Only printing handoffs can be rejected here.']]);
        }

        return $this->handoffs->reject($handoff, $reason);
    }
}
