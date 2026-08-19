<?php

namespace App\Modules\Design\Services;

use App\Modules\Design\Models\DesignItem;
use App\Modules\Printing\Models\PrintJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DesignRedesignService
{
    public function requestFromDesignItem(DesignItem $item, string $reason): DesignItem
    {
        return $this->createRedesign($item, $reason, 'design');
    }

    public function requestFromPrintJob(PrintJob $job, string $reason): DesignItem
    {
        if (!in_array($job->status, ['completed', 'reprint_required'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only completed print jobs can be sent back to Design for redesign.'],
            ]);
        }

        if ($job->order_type === 'reprint') {
            throw ValidationException::withMessages([
                'order_type' => ['Start redesign from the original completed print job.'],
            ]);
        }

        $item = $job->designItem()->first();
        if (!$item) {
            throw ValidationException::withMessages([
                'design_item_id' => ['This print job is not linked to a design item.'],
            ]);
        }

        return $this->createRedesign($item, $reason, 'printing', $job);
    }

    private function createRedesign(
        DesignItem $item,
        string $reason,
        string $source,
        ?PrintJob $printJob = null
    ): DesignItem {
        return DB::transaction(function () use ($item, $reason, $source, $printJob) {
            $item = DesignItem::query()
                ->whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($source === 'printing' && $printJob) {
                $existing = DesignItem::query()
                    ->where('redesign_of_print_job_id', $printJob->id)
                    ->whereIn('status', ['pending', 'in_design', 'awaiting_client_approval', 'client_changes_requested'])
                    ->latest()
                    ->first();

                if ($existing) {
                    return $existing->fresh(['job', 'type', 'printMaterial', 'documents', 'bomItems.material.baseUom', 'handoffs']);
                }
            }

            $redesign = $item->replicate([
                'status',
                'submitted_at',
                'approved_at',
                'print_ready_at',
                'production_ready_at',
                'created_at',
                'updated_at',
            ]);

            $redesign->title = $this->redesignTitle($item->title);
            $redesign->description = $this->withRedesignNote($item->description, $reason);
            $redesign->status = 'pending';
            $redesign->assigned_to = null;
            $redesign->redesign_of_item_id = $item->id;
            $redesign->redesign_of_print_job_id = $printJob?->id;
            $redesign->redesign_source = $source;
            $redesign->redesign_reason = $reason;
            $redesign->redesign_requested_at = now();
            $redesign->created_by = auth()->id();
            $redesign->updated_by = auth()->id();
            $redesign->save();

            return $redesign->fresh(['job', 'type', 'printMaterial', 'documents', 'bomItems.material.baseUom', 'handoffs']);
        });
    }

    private function redesignTitle(string $title): string
    {
        return str_contains(strtolower($title), 'redesign') ? $title : "{$title} - Redesign";
    }

    private function withRedesignNote(?string $description, string $reason): string
    {
        $note = "Redesign request: {$reason}";

        return $description ? "{$note}\n\nPrevious brief:\n{$description}" : $note;
    }
}
