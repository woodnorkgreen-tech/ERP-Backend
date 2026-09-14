<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\FinanceWorkAssignment;
use App\Modules\Finance\Models\FinanceWorkAssignmentEvent;
use Illuminate\Support\Facades\DB;

class FinanceWorkAssignmentLifecycle
{
    public function complete(string $workType, int $sourceId, ?int $actorId = null, ?string $note = null): void
    {
        DB::transaction(function () use ($workType, $sourceId, $actorId, $note): void {
            $assignment = FinanceWorkAssignment::query()->where([
                'work_type' => $workType, 'source_id' => $sourceId,
            ])->lockForUpdate()->first();
            if (! $assignment) {
                return;
            }

            FinanceWorkAssignmentEvent::create([
                'work_type' => $workType,
                'source_id' => $sourceId,
                'event' => 'completed',
                'from_user_id' => $assignment->assigned_to,
                'actor_id' => $actorId,
                'note' => $note,
            ]);
            $assignment->delete();
        });
    }
}
