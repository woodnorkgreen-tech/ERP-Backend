<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\BoardRequest;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Services\BoardWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BoardRequestController extends Controller
{
    public function __construct(private readonly BoardWorkflowService $workflow) {}

    /**
     * GET /board-requests
     * List requests — pending ones first, optionally filtered by job.
     */
    public function index(Request $request): JsonResponse
    {
        $query = BoardRequest::with(['material.workstation', 'requester'])
            ->orderByRaw("FIELD(status, 'pending', 'partial', 'fulfilled', 'cancelled')")
            ->latest();

        if ($request->filled('job_ref')) {
            $query->where('job_ref', $request->job_ref);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // ?mine=1 — Production users fetch only their own requests
        if ($request->boolean('mine')) {
            $query->where('requested_by', auth()->id());
        }

        return response()->json($query->paginate($request->get('per_page', 30)));
    }

    /**
     * POST /board-requests
     * Production team raises a request for boards against a job.
     */
    public function store(Request $request): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Production', 'Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'You are not permitted to raise board requests.'], 403);
        }

        $request->validate([
            'job_ref'  => 'required|string|max:100',
            'job_name' => 'nullable|string|max:255',
            'material_id' => 'required|integer|exists:library_materials,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'project_material_id' => 'nullable|integer|exists:element_materials,id',
            'recipient_name' => 'nullable|string|max:255',
            'qty'         => 'required|integer|min:1|max:200',
            'notes'       => 'nullable|string|max:500',
        ]);

        $material = LibraryMaterial::with('materialCategory.parent')->findOrFail($request->material_id);
        if (!$material->isBoardTrackable()) {
            return response()->json([
                'message' => "'{$material->material_name}' is not a board/sheet item. Issue it through the normal Stores workflow.",
            ], 422);
        }

        // Raising a request reserves physical stock, and this path writes to
        // Stock and InventoryLog directly rather than through adjustStock — so
        // the item-status gate that covers every other movement has to be
        // repeated here or an unfinished item could be reserved against a job.
        if (($material->item_status ?? 'Active') !== 'Active') {
            return response()->json([
                'message' => "'{$material->material_name}' is {$material->item_status} and cannot be reserved yet. "
                    .'Finish its setup in the Materials Library first.',
            ], 422);
        }

        if ($request->filled('project_material_id')) {
            $project = \App\Models\Project::findOrFail($request->project_id);
            $planned = \App\Models\ElementMaterial::with('element.taskMaterialsData.task')
                ->findOrFail($request->project_material_id);
            $materialsData = $planned->element?->taskMaterialsData;
            // Validate project and catalogue identity before applying the same
            // sign-off gate used by quantity and controlled-stock issues.
            if ((int) $materialsData?->task?->project_enquiry_id !== (int) $project->enquiry_id
                || (int) $planned->library_material_id !== (int) $material->id) {
                return response()->json(['message' => 'This board line does not belong to the selected project.'], 422);
            }
            if (! (bool) data_get($materialsData?->project_info, 'approval_status.all_approved', false)) {
                throw ValidationException::withMessages([
                    'project_material_id' => 'Project Officer and Production must sign off the material list before Stores can issue it.',
                ]);
            }

            $alreadyIssued = (float) InventoryLog::where('project_material_id', $planned->id)
                ->whereIn('type', ['check_out', 'issue', 'consumption'])->sum(DB::raw('ABS(quantity)'))
                - (float) InventoryLog::where('project_material_id', $planned->id)
                    ->fulfilmentReopeningReturns()->sum('quantity');
            if ((float) $request->qty > max(0, (float) $planned->quantity - $alreadyIssued)) {
                return response()->json(['message' => 'Board quantity exceeds the remaining project requirement.'], 422);
            }
        }

        $available = Board::where('library_material_id', $material->id)
            ->where('status', 'Available')
            ->count();

        if ($available < $request->qty) {
            // Count every board for this material grouped by status so the
            // caller can tell the user exactly why nothing is available
            // (e.g. all allocated vs all consumed vs never checked in).
            $breakdown = Board::where('library_material_id', $material->id)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $total = array_sum($breakdown);
            $onJob = ($breakdown['Allocated'] ?? 0)
                   + ($breakdown['At Station'] ?? 0)
                   + ($breakdown['WIP'] ?? 0);

            if ($total === 0) {
                $reason = 'No boards have been checked into stores for this material yet.';
            } elseif ($onJob === $total) {
                $reason = "All {$total} board(s) are currently out on jobs.";
            } elseif ($onJob > 0) {
                $consumed = ($breakdown['Consumed'] ?? 0) + ($breakdown['Scrapped'] ?? 0);
                $reason   = "{$onJob} board(s) are out on jobs" . ($consumed > 0 ? ", {$consumed} consumed/scrapped." : '.');
            } else {
                $consumed = ($breakdown['Consumed'] ?? 0) + ($breakdown['Scrapped'] ?? 0);
                $reason   = $consumed > 0
                    ? "All {$consumed} board(s) have been consumed or scrapped."
                    : 'No boards are currently available.';
            }

            return response()->json([
                'message'   => "Only {$available} {$material->material_name} board(s) available. Requested: {$request->qty}.",
                'available' => $available,
                'reason'    => $reason,
                'breakdown' => $breakdown,
            ], 422);
        }

        $boardRequest = DB::transaction(function () use ($request, $material) {
            $br = BoardRequest::create([
                'job_ref'      => $request->job_ref,
                'project_id'   => $request->project_id,
                'job_name'     => $request->job_name,
                'material_id'  => $material->id,
                'project_material_id' => $request->project_material_id,
                'qty_requested'=> $request->qty,
                'recipient_name' => $request->recipient_name,
                'status'       => 'pending',
                'requested_by' => auth()->id(),
                'notes'        => $request->notes,
            ]);

            // Soft-reserve in stocks
            Stock::where('material_id', $material->id)
                ->increment('quantity_reserved', $request->qty);

            // Log the reservation
            $stock = Stock::where('material_id', $material->id)->first();
            InventoryLog::create([
                'material_id'  => $material->id,
                'user_id'      => auth()->id(),
                'type'         => 'allocated',
                'usage_type'   => 'reusable',
                'quantity'     => $request->qty,
                'balance_after'=> $stock?->quantity_on_hand ?? 0,
                'notes'        => "Board request raised — {$request->qty} boards for job {$request->job_ref}",
                'logged_at'    => now(),
            ]);

            return $br;
        });

        // Kick off the workflow chain: notify Stores team
        $this->workflow->onRequestRaised($boardRequest);

        return response()->json([
            'message' => "Board request raised. {$request->qty} {$material->material_name} board(s) reserved for job [{$request->job_ref}].",
            'request' => $boardRequest->load('material'),
        ], 201);
    }

    /**
     * POST /board-requests/{id}/fulfil
     * Storekeeper physically issues boards against a pending request.
     * Accepts specific board IDs or auto-selects FIFO.
     */
    public function fulfil(Request $request, int $id): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Stores', 'Super Admin'])) {
            return response()->json(['message' => 'Only Stores team members can fulfil board requests.'], 403);
        }

        $request->validate([
            'board_ids' => 'nullable|array',
            'board_ids.*' => 'integer|exists:boards,id',
        ]);

        // Selection, eligibility and stock all resolve under one transaction so
        // two storekeepers cannot claim the same physical board, and so a request
        // can never be fulfilled beyond the quantity it reserved.
        try {
            [$boardRequest, $boards, $issueLog] = DB::transaction(function () use ($request, $id) {
                $boardRequest = BoardRequest::with('material')->whereKey($id)->lockForUpdate()->firstOrFail();

                if (! in_array($boardRequest->status, ['pending', 'partial'], true)) {
                    throw new \InvalidArgumentException($boardRequest->status === 'cancelled'
                        ? 'This request was cancelled and its reservation has already been released.'
                        : 'This request has already been fulfilled.');
                }

                $needed = (int) $boardRequest->qty_requested - (int) $boardRequest->qty_fulfilled;
                if ($needed < 1) {
                    throw new \InvalidArgumentException('This request has no outstanding board quantity.');
                }

                // Use provided board IDs or auto-select FIFO. Either way the
                // count is capped by what this request still has reserved —
                // over-issuing here would bypass the approved-requirement check
                // that store() applied when the request was raised.
                if (!empty($request->board_ids)) {
                    $ids = array_values(array_unique($request->board_ids));
                    if (count($ids) > $needed) {
                        throw new \InvalidArgumentException(
                            "This request has only {$needed} board(s) outstanding. Raise another request for the rest."
                        );
                    }

                    $boards = Board::whereIn('id', $ids)
                        ->where('library_material_id', $boardRequest->material_id)
                        ->where('status', 'Available')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    if ($boards->count() !== count($ids)) {
                        throw new \InvalidArgumentException('One or more selected boards are not available.');
                    }
                } else {
                    $boards = Board::where('library_material_id', $boardRequest->material_id)
                        ->where('status', 'Available')
                        ->oldest()
                        ->limit($needed)
                        ->lockForUpdate()
                        ->get();
                }

                if ($boards->isEmpty()) {
                    throw new \InvalidArgumentException('No available boards found for this material.');
                }
                if ($boards->contains(fn (Board $board) => (float) $board->current_value <= 0)) {
                    throw new \InvalidArgumentException(
                        'One or more selected boards have no recorded value. Record their receipt valuation before issue.'
                    );
                }

                foreach ($boards as $board) {
                    $board->transitionTo('Allocated', auth()->id(),
                        "Issued for job {$boardRequest->job_ref} — request #{$boardRequest->id}",
                        $boardRequest->job_ref
                    );
                }

                $fulfilled = $boards->count();
                $boardRequest->increment('qty_fulfilled', $fulfilled);

                $newTotal = $boardRequest->qty_fulfilled;
                $boardRequest->update([
                    'status'       => $newTotal >= $boardRequest->qty_requested ? 'fulfilled' : 'partial',
                    'fulfilled_by' => auth()->id(),
                    'fulfilled_at' => now(),
                ]);

                // Release reservation, deduct stock. The row is locked for the
                // rest of this transaction so a concurrent fulfilment or cancel
                // cannot read the same balance and race it negative.
                $stock = Stock::where('material_id', $boardRequest->material_id)->lockForUpdate()->first();
                if ($stock) {
                    $stock->decrement('quantity_reserved', $fulfilled);
                    $stock->decrement('quantity_on_hand', $fulfilled);
                }

                $issueLog = InventoryLog::create([
                    'material_id'  => $boardRequest->material_id,
                    'user_id'      => auth()->id(),
                    'type'         => 'check_out',
                    'usage_type'   => 'reusable',
                    'quantity'     => -$fulfilled,
                    'receipt_unit_cost' => $boards->avg(fn (Board $board) => (float) $board->current_value),
                    'balance_after'=> $stock?->fresh()->quantity_on_hand ?? 0,
                    'project_id' => $boardRequest->project_id,
                    'project_material_id' => $boardRequest->project_material_id,
                    'reference_no' => $boardRequest->job_ref,   // enables outstandingReusables grouping by job
                    'recipient_name' => $boardRequest->recipient_name,
                    'notes'        => "{$fulfilled} board(s) issued to job {$boardRequest->job_ref}. "
                        . 'Codes: ' . $boards->pluck('tracking_code')->join(', '),
                    'logged_at'    => now(),
                ]);

                Board::whereIn('id', $boards->pluck('id'))->update([
                    'original_issue_log_id' => $issueLog->id,
                    'board_request_id' => $boardRequest->id,
                    'project_id' => $boardRequest->project_id,
                    'project_material_id' => $boardRequest->project_material_id,
                ]);

                return [$boardRequest, $boards, $issueLog];
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Board issues are cost-bearing Stores issues too. The generic inventory
        // service dispatches this automatically; this specialized lifecycle owns
        // its stock movement, so it must announce the same accounting event.
        \App\Events\Stores\StockIssued::dispatch($issueLog);

        // Advance the workflow: create Logistics dispatch task + notify
        $this->workflow->onRequestFulfilled($boardRequest, $boards);

        return response()->json([
            'message'      => "{$boards->count()} board(s) issued to job [{$boardRequest->job_ref}].",
            'boards_issued'=> $boards->pluck('tracking_code'),
            'request'      => $boardRequest->fresh(['material', 'requester']),
        ]);
    }

    /**
     * DELETE /board-requests/{id}
     * Cancel a pending request — releases the reservation.
     */
    public function cancel(int $id): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Production', 'Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'You are not permitted to cancel board requests.'], 403);
        }

        try {
            DB::transaction(function () use ($id) {
                $boardRequest = BoardRequest::whereKey($id)->lockForUpdate()->firstOrFail();

                // Releasing a reservation is a once-only act. Guarding on
                // 'fulfilled' alone let an already-cancelled request release
                // the same quantity again, driving quantity_reserved negative
                // and reporting more stock available than physically exists.
                if (! in_array($boardRequest->status, ['pending', 'partial'], true)) {
                    throw new \InvalidArgumentException($boardRequest->status === 'cancelled'
                        ? 'This request is already cancelled and its reservation has been released.'
                        : 'Cannot cancel a fulfilled request.');
                }

                $unreleased = (int) $boardRequest->qty_requested - (int) $boardRequest->qty_fulfilled;

                if ($unreleased > 0) {
                    $stock = Stock::where('material_id', $boardRequest->material_id)->lockForUpdate()->first();
                    if ($stock) {
                        $stock->decrement('quantity_reserved', min($unreleased, (int) $stock->quantity_reserved));
                    }
                }

                $boardRequest->update(['status' => 'cancelled']);
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Board request cancelled and reservation released.']);
    }
}
