<?php

namespace App\Modules\ProcurementStores\Services;

use App\Events\Stores\BoardRequestRaised;
use App\Events\Stores\BoardRequestFulfilled;
use App\Events\Stores\BoardsDispatchedToStation;
use App\Events\Stores\OffcutRegistered;
use App\Models\User;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\BoardRequest;
use App\Modules\ProcurementStores\Models\BoardWorkflowTask;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BoardWorkflowService
{
    /**
     * Called immediately after a BoardRequest is created by Production.
     * Creates a pending task for the Stores team and fires the event.
     */
    public function onRequestRaised(BoardRequest $boardRequest): BoardWorkflowTask
    {
        $task = BoardWorkflowTask::create([
            'job_ref'         => $boardRequest->job_ref,
            'board_request_id'=> $boardRequest->id,
            'task_type'       => BoardWorkflowTask::TYPE_REQUEST_RAISED,
            'assigned_role'   => 'Stores',
            'status'          => 'pending',
            'due_at'          => now()->addHours(4),
            'payload'         => [
                'job_name'      => $boardRequest->job_name,
                'material_id'   => $boardRequest->material_id,
                'qty_requested' => $boardRequest->qty_requested,
            ],
        ]);

        event(new BoardRequestRaised($boardRequest, $task));

        return $task;
    }

    /**
     * Called after the Storekeeper fulfils a request (boards transition to Allocated).
     * Marks the Stores task done, creates a Logistics dispatch task, and fires event.
     */
    public function onRequestFulfilled(BoardRequest $boardRequest, Collection $issuedBoards): BoardWorkflowTask
    {
        return DB::transaction(function () use ($boardRequest, $issuedBoards) {
            // Close the open Stores task
            BoardWorkflowTask::where('board_request_id', $boardRequest->id)
                ->where('task_type', BoardWorkflowTask::TYPE_REQUEST_RAISED)
                ->where('status', 'pending')
                ->update([
                    'status'       => 'done',
                    'completed_at' => now(),
                    'completed_by' => auth()->id(),
                ]);

            $storesTask = BoardWorkflowTask::where('board_request_id', $boardRequest->id)
                ->where('task_type', BoardWorkflowTask::TYPE_REQUEST_RAISED)
                ->latest()
                ->first();

            // Create the Logistics dispatch task
            $dispatchTask = BoardWorkflowTask::create([
                'job_ref'             => $boardRequest->job_ref,
                'board_request_id'    => $boardRequest->id,
                'task_type'           => BoardWorkflowTask::TYPE_BOARDS_TO_DISPATCH,
                'assigned_role'       => 'Logistics',
                'status'              => 'pending',
                'triggered_by_task_id'=> $storesTask?->id,
                'due_at'              => now()->addHours(2),
                'payload'             => [
                    'job_name'     => $boardRequest->job_name,
                    'board_ids'    => $issuedBoards->pluck('id')->toArray(),
                    'board_codes'  => $issuedBoards->pluck('tracking_code')->toArray(),
                    'qty'          => $issuedBoards->count(),
                ],
            ]);

            event(new BoardRequestFulfilled($boardRequest, $issuedBoards, $dispatchTask));

            return $dispatchTask;
        });
    }

    /**
     * Called when Logistics marks boards as delivered to the station.
     * Boards transition Allocated → At Station.
     * Marks dispatch task done, creates Production task, notifies the operator.
     */
    public function onBoardsDispatched(string $jobRef, Collection $boards, User $actor): ?BoardWorkflowTask
    {
        return DB::transaction(function () use ($jobRef, $boards, $actor) {
            // Formal board requests create a dispatch task. Direct issue from the
            // board screen is also valid, so task completion is conditional while
            // the physical board transition remains authoritative.
            $dispatchTask = BoardWorkflowTask::where('job_ref', $jobRef)
                ->where('task_type', BoardWorkflowTask::TYPE_BOARDS_TO_DISPATCH)
                ->whereIn('status', ['pending', 'in_progress'])
                ->lockForUpdate()
                ->first();

            foreach ($boards as $board) {
                $board->transitionTo('At Station', $actor->id, "Delivered to station by {$actor->name}");
            }

            if ($dispatchTask) {
                $dispatchTask->update([
                    'status'       => 'done',
                    'completed_at' => now(),
                    'completed_by' => $actor->id,
                ]);
            }

            // Create next task: Production must start WIP
            $this->onBoardsAtStation($jobRef, $boards, $dispatchTask);

            if ($dispatchTask) {
                event(new BoardsDispatchedToStation($jobRef, $boards, $actor, $dispatchTask));
            }

            return $dispatchTask;
        });
    }

    /**
     * Creates a Production task when boards arrive at the station.
     * Called from onBoardsDispatched — not a standalone event chain entry.
     */
    public function onBoardsAtStation(string $jobRef, Collection $boards, ?BoardWorkflowTask $triggeredBy = null): BoardWorkflowTask
    {
        // Find the original requester so we can assign directly if known
        $requesterId = $triggeredBy?->boardRequest?->requested_by;

        return BoardWorkflowTask::create([
            'job_ref'              => $jobRef,
            'board_request_id'     => $triggeredBy?->board_request_id,
            'task_type'            => BoardWorkflowTask::TYPE_BOARDS_AT_STATION,
            'assigned_role'        => 'Production',
            'assigned_user_id'     => $requesterId,
            'status'               => 'pending',
            'triggered_by_task_id' => $triggeredBy?->id,
            'due_at'               => now()->addHours(6),
            'payload'              => [
                'job_name'    => $triggeredBy?->payload['job_name'] ?? null,
                'board_ids'   => $boards->pluck('id')->toArray(),
                'board_codes' => $boards->pluck('tracking_code')->toArray(),
                'qty'         => $boards->count(),
            ],
        ]);
    }

    /**
     * Called after an offcut is registered (board status → Quarantine).
     * Creates a return-to-rack task for Stores.
     */
    public function onOffcutRegistered(Board $offcut, ?BoardWorkflowTask $triggeredBy = null): BoardWorkflowTask
    {
        $task = BoardWorkflowTask::create([
            'job_ref'             => $offcut->assigned_job_ref ?? 'N/A',
            'task_type'           => BoardWorkflowTask::TYPE_OFFCUT_TO_RETURN,
            'assigned_role'       => 'Stores',
            'status'              => 'pending',
            'triggered_by_task_id'=> $triggeredBy?->id,
            'due_at'              => now()->addHours(8),
            'payload'             => [
                'job_name'       => $triggeredBy?->payload['job_name'] ?? null,
                'offcut_id'      => $offcut->id,
                'tracking_code'  => $offcut->tracking_code,
                'dimensions'     => "{$offcut->length}x{$offcut->width}x{$offcut->thickness}mm",
                'parent_board_id'=> $offcut->parent_board_id,
            ],
        ]);

        event(new OffcutRegistered($offcut, $task));

        return $task;
    }

    /**
     * Claim a pending task to prevent double-processing.
     * Uses a locked SELECT to guard against race conditions.
     */
    public function claimTask(int $taskId, User $actor): BoardWorkflowTask
    {
        return DB::transaction(function () use ($taskId, $actor) {
            $task = BoardWorkflowTask::where('id', $taskId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

            $task->update([
                'status'     => 'in_progress',
                'claimed_at' => now(),
                'claimed_by' => $actor->id,
            ]);

            return $task->fresh();
        });
    }

    /**
     * Creates a Stores task when an intact board is returned from the production floor.
     * The stock increment and status transition have already happened in BoardController::transition().
     * This task simply prompts Stores to physically rack the board.
     */
    public function onBoardReturned(Board $board, ?string $jobRef = null): BoardWorkflowTask
    {
        return BoardWorkflowTask::create([
            'job_ref'       => $jobRef ?? 'N/A',
            'task_type'     => BoardWorkflowTask::TYPE_BOARD_RETURNED,
            'assigned_role' => 'Stores',
            'status'        => 'pending',
            'due_at'        => now()->addHours(4),
            'payload'       => [
                'board_id'      => $board->id,
                'tracking_code' => $board->tracking_code,
                'dimensions'    => "{$board->length}x{$board->width}x{$board->thickness}mm",
            ],
        ]);
    }

    /**
     * Storekeeper confirms that an offcut or returned board has been racked.
     * Handles both TYPE_OFFCUT_TO_RETURN and TYPE_BOARD_RETURNED tasks.
     */
    public function returnOffcut(int $taskId, User $actor, ?string $conditionGrade = null, ?string $notes = null): BoardWorkflowTask
    {
        $recoveryLog = null;
        $task = DB::transaction(function () use ($taskId, $actor, $conditionGrade, $notes, &$recoveryLog) {
            $task = BoardWorkflowTask::where('id', $taskId)
                ->whereIn('task_type', [
                    BoardWorkflowTask::TYPE_OFFCUT_TO_RETURN,
                    BoardWorkflowTask::TYPE_BOARD_RETURNED,
                ])
                ->whereIn('status', ['pending', 'in_progress'])
                ->lockForUpdate()
                ->firstOrFail();

            // Payload key differs by task type: offcuts use 'offcut_id', board returns use 'board_id'
            $boardId = $task->payload['offcut_id'] ?? $task->payload['board_id'] ?? null;
            if ($boardId) {
                $board = Board::whereKey($boardId)->lockForUpdate()->firstOrFail();
                $note  = $task->task_type === BoardWorkflowTask::TYPE_OFFCUT_TO_RETURN
                    ? 'Offcut physically returned to rack by storekeeper'
                    : 'Board physically returned to rack by storekeeper';

                if ($board->hasStatus('Quarantine')) {
                    if ($task->task_type === BoardWorkflowTask::TYPE_OFFCUT_TO_RETURN) {
                        $accepted = in_array($conditionGrade, ['A', 'B'], true);
                        if ($accepted) {
                            $board->transitionTo('Available', $actor->id, $notes ?: $note, null, $conditionGrade);
                        } else {
                            $board->update(['condition_grade' => $conditionGrade]);
                            \App\Modules\ProcurementStores\Models\BoardMovement::create([
                                'board_id' => $board->id, 'from_status' => 'Quarantine', 'to_status' => 'Quarantine',
                                'performed_by' => $actor->id, 'notes' => $notes, 'job_ref' => $task->job_ref,
                                'condition_grade' => $conditionGrade,
                            ]);
                        }
                        $stock = Stock::where('material_id', $board->library_material_id)->lockForUpdate()->first();
                        if ($stock) $stock->increment('quantity_on_hand', 1);
                        $recoveryLog = InventoryLog::create([
                            'material_id' => $board->library_material_id,
                            'user_id' => $actor->id,
                            'type' => 'return',
                            'usage_type' => 'reusable',
                            'batch_number' => $board->batch_number,
                            'quantity' => 1,
                            'receipt_unit_cost' => $board->current_value,
                            'balance_after' => $stock?->fresh()->quantity_on_hand ?? 0,
                            'original_issue_log_id' => $board->original_issue_log_id,
                            'return_kind' => 'recovered_offcut',
                            'project_id' => $board->project_id,
                            'project_material_id' => $board->project_material_id,
                            'reference_no' => $task->job_ref,
                            'notes' => "Offcut {$board->tracking_code} physically received from parent board #{$board->parent_board_id}",
                            'logged_at' => now(),
                        ]);
                        $board->update([
                            'return_received_at' => now(),
                            'return_received_by' => $actor->id,
                            'return_log_id' => $recoveryLog->id,
                            'quarantine_review_status' => $accepted ? null : 'pending',
                        ]);
                        if (!$accepted) $recoveryLog = null; // Finance waits for manager valuation/release.
                    } else {
                        // Racking a damaged return confirms location, not quality.
                        $board->update(['assigned_job_ref' => null]);
                        \App\Modules\ProcurementStores\Models\BoardMovement::create([
                            'board_id' => $board->id,
                            'from_status' => 'Quarantine',
                            'to_status' => 'Quarantine',
                            'performed_by' => $actor->id,
                            'notes' => $note . ' — remains quarantined pending supervisor review',
                            'job_ref' => $task->job_ref,
                        ]);
                    }
                } elseif ($board->hasStatus('Available')) {
                    // Board already Available (normal offcut path) — clear job ref and
                    // write a racking confirmation note without changing status.
                    $board->update(['assigned_job_ref' => null]);
                    \App\Modules\ProcurementStores\Models\BoardMovement::create([
                        'board_id'     => $board->id,
                        'from_status'  => 'Available',
                        'to_status'    => 'Available',
                        'performed_by' => $actor->id,
                        'notes'        => $note,
                    ]);
                }
            }

            $task->update([
                'status'       => 'done',
                'completed_at' => now(),
                'completed_by' => $actor->id,
            ]);

            return $task->fresh();
        });

        if ($recoveryLog) \App\Events\Stores\StockReturned::dispatch($recoveryLog);

        return $task;
    }

    /**
     * Batch-start WIP for all At Station boards on a job.
     * Closes the boards_at_station task for that job.
     */
    public function startWip(string $jobRef, Collection $boards, User $actor): void
    {
        DB::transaction(function () use ($jobRef, $boards, $actor) {
            foreach ($boards as $board) {
                if ($board->hasStatus('At Station')) {
                    $board->transitionTo('WIP', $actor->id, "WIP started by {$actor->name}");
                }
            }

            BoardWorkflowTask::where('job_ref', $jobRef)
                ->where('task_type', BoardWorkflowTask::TYPE_BOARDS_AT_STATION)
                ->whereIn('status', ['pending', 'in_progress'])
                ->update([
                    'status'       => 'done',
                    'completed_at' => now(),
                    'completed_by' => $actor->id,
                ]);
        });
    }

    /**
     * Return pending workflow tasks for a given role (the action inbox).
     */
    public function pendingTasksForRoles(array $roles): \Illuminate\Database\Eloquent\Collection
    {
        return BoardWorkflowTask::with(['boardRequest.material', 'assignedUser'])
            ->whereIn('assigned_role', $roles)
            ->whereIn('status', ['pending', 'in_progress'])
            ->orderBy('due_at')
            ->get();
    }
}
