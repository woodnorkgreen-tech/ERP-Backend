<?php

namespace App\Modules\Design\Services;

use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignWorkSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DesignWorkTrackingService
{
    private const FINISHED = ['done', 'cancelled', 'print_ready', 'production_ready', 'handed_off', 'not_required'];

    public function begin(DesignItem $item): DesignItem
    {
        return DB::transaction(function () use ($item) {
            $item = DesignItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if (in_array($item->status, self::FINISHED, true)) {
                throw ValidationException::withMessages(['status' => ['Finished or cancelled design work cannot be started.']]);
            }
            if ($item->job?->status === 'cancelled' || $item->job?->enquiry?->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => ['Work cannot start on a cancelled design job.']]);
            }
            if ($item->workSessions()->whereNull('ended_at')->exists()) {
                throw ValidationException::withMessages(['work_tracking' => ['This design item is already being worked on.']]);
            }

            $latest = $item->revisions()->first();
            $round = $latest ? $latest->version_number + ($latest->status === 'approved' ? 1 : 0) : 1;
            $item->workSessions()->create([
                'user_id' => auth()->id(), 'started_at' => now(), 'revision_round' => $round,
            ]);
            if ($item->status === 'pending') {
                $item->update(['status' => 'in_design', 'updated_by' => auth()->id()]);
            }

            return $this->fresh($item);
        });
    }

    public function pause(DesignItem $item, string $reason, ?string $details = null): DesignItem
    {
        if ($reason === 'other' && blank($details)) {
            throw ValidationException::withMessages(['details' => ['Explain the pause when Other is selected.']]);
        }

        return DB::transaction(function () use ($item, $reason, $details) {
            $item = DesignItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $session = $item->workSessions()->whereNull('ended_at')->latest('started_at')->first();
            if (!$session) {
                throw ValidationException::withMessages(['work_tracking' => ['No active work session is available to pause.']]);
            }
            $session->update([
                'ended_at' => now(), 'end_type' => 'paused', 'pause_reason_code' => $reason,
                'pause_reason_details' => $details, 'ended_by' => auth()->id(),
            ]);

            return $this->fresh($item);
        });
    }

    public function stop(DesignItem $item, string $outcome, ?string $reason = null): DesignItem
    {
        if (in_array($outcome, ['cancelled', 'rejected'], true) && blank($reason)) {
            throw ValidationException::withMessages(['reason' => ['Give a reason for cancelled or rejected work.']]);
        }

        return DB::transaction(function () use ($item, $outcome, $reason) {
            $item = DesignItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $session = $item->workSessions()->whereNull('ended_at')->latest('started_at')->first();
            if (!$session) {
                throw ValidationException::withMessages(['work_tracking' => ['No active work session is available to stop.']]);
            }
            $session->update([
                'ended_at' => now(), 'end_type' => $outcome, 'stop_reason' => $reason,
                'ended_by' => auth()->id(),
            ]);

            return $this->fresh($item);
        });
    }

    public function closeForWorkflow(DesignItem $item, string $endType, ?string $reason = null, ?int $endedBy = null): void
    {
        $session = $item->workSessions()->whereNull('ended_at')->latest('started_at')->first();
        if (!$session) return;
        $session->update([
            'ended_at' => now(), 'end_type' => $endType,
            'pause_reason_code' => $endType === 'paused' ? $reason : null,
            'stop_reason' => $endType === 'paused' ? null : $reason,
            'ended_by' => $endedBy ?? auth()->id(),
        ]);
    }

    private function fresh(DesignItem $item): DesignItem
    {
        return $item->fresh([
            'job.documents', 'assignedUser:id,name', 'type', 'printMaterial', 'documents',
            'bomItems.material.baseUom', 'handoffs', 'workSessions.user:id,name', 'workSessions.endedBy:id,name',
            'revisions.documents', 'revisions.creator:id,name', 'revisions.approver:id,name',
            'changeRequests.againstRevision', 'changeRequests.addressedByRevision', 'changeRequests.recorder:id,name',
            'updates.creator:id,name',
        ]);
    }
}
