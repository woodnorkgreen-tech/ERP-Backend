<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Constants\Permissions;
use App\Events\Stores\StockIssued;
use App\Http\Controllers\Controller;
use App\Models\ElementMaterial;
use App\Models\Project;
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
        if (! auth()->user()?->can(Permissions::STORES_BOARD_MANAGE) && ! auth()->user()?->can(Permissions::PROJECT_READ)) {
            return response()->json(['message' => 'You are not permitted to raise board requests.'], 403);
        }

        $request->validate([
            'job_ref' => 'required|string|max:100',
            'job_name' => 'nullable|string|max:255',
            'material_id' => 'required|integer|exists:library_materials,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'project_material_id' => 'nullable|integer|exists:element_materials,id',
            'recipient_name' => 'nullable|string|max:255',
            'qty' => 'required|integer|min:1|max:200',
            'notes' => 'nullable|string|max:500',
            'lifecycle_override_reason' => 'nullable|string|max:1000',
        ]);

        // Report 78C: a board request reserves stock for a job, so the job must
        // be one Stores may issue to — the same rule as every other issue.
        $lifecycleOverride = null;
        if ($request->filled('project_id')) {
            $lifecycleOverride = app(\App\Modules\ProcurementStores\Services\ProjectMaterialWorkflowEligibility::class)->assertAllows(
                (int) Project::whereKey($request->project_id)->value('enquiry_id') ?: null,
                \App\Modules\ProcurementStores\Services\ProjectMaterialWorkflowEligibility::CAP_ISSUE,
                $request->input('lifecycle_override_reason'),
            );
        }

        $material = LibraryMaterial::with('materialCategory.parent')->findOrFail($request->material_id);
        if (! $material->isBoardTrackable()) {
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

        // Report 79E (P0-5): the requirement cap, the board count and the
        // reservation are one decision, so they run under one set of locks.
        // Before, two requests checked the same "remaining" without a lock and
        // ignored each other's open reservations, so both could reserve the
        // whole remainder (and the same physical boards).
        $boardRequest = DB::transaction(function () use ($request, $material, $lifecycleOverride) {
            if ($request->filled('project_material_id')) {
                $project = Project::findOrFail($request->project_id);
                $planned = ElementMaterial::with('element.taskMaterialsData.task')
                    ->lockForUpdate()
                    ->findOrFail($request->project_material_id);
                $materialsData = $planned->element?->taskMaterialsData;
                // Validate project and catalogue identity before applying the same
                // sign-off gate used by quantity and controlled-stock issues.
                if ((int) $materialsData?->task?->project_enquiry_id !== (int) $project->enquiry_id
                    || (int) $planned->library_material_id !== (int) $material->id) {
                    $this->refuse(['message' => 'This board line does not belong to the selected project.']);
                }
                if (! (bool) data_get($materialsData?->project_info, 'approval_status.all_approved', false)) {
                    throw ValidationException::withMessages([
                        'project_material_id' => 'Project Officer and Production must sign off the material list before Stores can issue it.',
                    ]);
                }

                // Locking reads: under REPEATABLE READ a plain SUM after the
                // line lock can read a snapshot from before a competing issue.
                $alreadyIssued = (float) InventoryLog::where('project_material_id', $planned->id)
                    ->whereIn('type', ['check_out', 'issue', 'consumption'])->lockForUpdate()->sum(DB::raw('ABS(quantity)'))
                    - (float) InventoryLog::where('project_material_id', $planned->id)
                        ->fulfilmentReopeningReturns()->lockForUpdate()->sum('quantity');
                // Report 78B: boards are counted in the material's stock unit, so the
                // requirement is read in it too (12 sqm is not 12 boards).
                $requiredBase = (float) app(\App\Modules\ProcurementStores\Services\RequirementUnits::class)
                    ->requiredInBaseOrFail($planned, $material, 'qty');
                // Report 78C: quantity recorded as fulfilled outside the ERP is not owed.
                $retirements = app(\App\Modules\ProcurementStores\Services\MaterialRequirementRetirements::class);
                $alreadyIssued += $retirements->retiredIn($planned, $requiredBase, (float) ($retirements->retiredBomByLine([$planned->id])[$planned->id] ?? 0));
                // Boards already promised to this line by other open requests.
                $reservedOnLine = (float) BoardRequest::where('project_material_id', $planned->id)
                    ->whereIn('status', ['pending', 'partial'])->lockForUpdate()
                    ->sum(DB::raw('qty_requested - qty_fulfilled'));
                $remaining = max(0, $requiredBase - $alreadyIssued - $reservedOnLine);
                if ((float) $request->qty > $remaining) {
                    $this->refuse(['message' => $reservedOnLine > 0
                        ? "Board quantity exceeds the remaining project requirement ({$remaining} left; {$reservedOnLine} already reserved by open requests)."
                        : 'Board quantity exceeds the remaining project requirement.']);
                }
            }

            // Serialise reservations per material on its stock row, then count
            // boards on the shelf less those other open requests hold.
            Stock::where('material_id', $material->id)->lockForUpdate()->first();
            $reservedForMaterial = (int) BoardRequest::where('material_id', $material->id)
                ->whereIn('status', ['pending', 'partial'])
                ->sum(DB::raw('qty_requested - qty_fulfilled'));
            $onShelf = Board::where('library_material_id', $material->id)
                ->where('status', 'Available')
                ->count();
            $available = max(0, $onShelf - $reservedForMaterial);

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
                } elseif ($reservedForMaterial > 0 && $onShelf >= $request->qty) {
                    $reason = "{$reservedForMaterial} board(s) on the shelf are already reserved by open requests.";
                } elseif ($onJob === $total) {
                    $reason = "All {$total} board(s) are currently out on jobs.";
                } elseif ($onJob > 0) {
                    $consumed = ($breakdown['Consumed'] ?? 0) + ($breakdown['Scrapped'] ?? 0);
                    $reason = "{$onJob} board(s) are out on jobs".($consumed > 0 ? ", {$consumed} consumed/scrapped." : '.');
                } else {
                    $consumed = ($breakdown['Consumed'] ?? 0) + ($breakdown['Scrapped'] ?? 0);
                    $reason = $consumed > 0
                        ? "All {$consumed} board(s) have been consumed or scrapped."
                        : 'No boards are currently available.';
                }

                $this->refuse([
                    'message' => "Only {$available} {$material->material_name} board(s) available. Requested: {$request->qty}.",
                    'available' => $available,
                    'reason' => $reason,
                    'breakdown' => $breakdown,
                ]);
            }

            $br = BoardRequest::create([
                'job_ref' => $request->job_ref,
                'project_id' => $request->project_id,
                'job_name' => $request->job_name,
                'material_id' => $material->id,
                'project_material_id' => $request->project_material_id,
                'qty_requested' => $request->qty,
                'recipient_name' => $request->recipient_name,
                'status' => 'pending',
                'requested_by' => auth()->id(),
                'notes' => trim($request->notes.($lifecycleOverride ? ' [Lifecycle override: '.$lifecycleOverride['reason'].']' : '')) ?: null,
            ]);

            // Soft-reserve in stocks
            Stock::where('material_id', $material->id)
                ->increment('quantity_reserved', $request->qty);

            // Log the reservation
            $stock = Stock::where('material_id', $material->id)->first();
            InventoryLog::create([
                'material_id' => $material->id,
                'user_id' => auth()->id(),
                'type' => 'allocated',
                'usage_type' => 'reusable',
                'quantity' => $request->qty,
                'balance_after' => $stock?->quantity_on_hand ?? 0,
                'notes' => "Board request raised — {$request->qty} boards for job {$request->job_ref}",
                'logged_at' => now(),
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
        if (! auth()->user()?->can(Permissions::STORES_BOARD_MANAGE) && ! auth()->user()?->can(Permissions::STORES_MANAGE)) {
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
                if (! empty($request->board_ids)) {
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
                // Name the offenders. The storekeeper has to go and record a
                // receipt valuation on these specific sheets, and on an
                // auto-selected FIFO issue they never saw which ones they were.
                $unvalued = $boards->filter(fn (Board $board) => (float) $board->current_value <= 0);
                if ($unvalued->isNotEmpty()) {
                    throw new \InvalidArgumentException(
                        $unvalued->count().' selected board(s) have no recorded value: '
                        .$unvalued->pluck('tracking_code')->join(', ')
                        .'. Record their receipt valuation before issue — issuing them would post a zero cost to this project.'
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
                    'status' => $newTotal >= $boardRequest->qty_requested ? 'fulfilled' : 'partial',
                    'fulfilled_by' => auth()->id(),
                    'fulfilled_at' => now(),
                ]);

                // Release reservation, deduct stock. The row is locked for the
                // rest of this transaction so a concurrent fulfilment or cancel
                // cannot read the same balance and race it negative.
                $stock = Stock::where('material_id', $boardRequest->material_id)->lockForUpdate()->first();
                if ($stock) {
                    $reservedToRelease = min((float) $stock->quantity_reserved, (float) $fulfilled);
                    if ($reservedToRelease > 0) {
                        $stock->decrement('quantity_reserved', $reservedToRelease);
                    }
                    if ((float) $stock->quantity_on_hand < $fulfilled) {
                        throw new \InvalidArgumentException("Insufficient physical stock on hand ({$stock->quantity_on_hand}) to fulfil {$fulfilled} board(s).");
                    }
                    $stock->decrement('quantity_on_hand', $fulfilled);
                }

                $issueLog = InventoryLog::create([
                    'material_id' => $boardRequest->material_id,
                    'user_id' => auth()->id(),
                    'type' => 'check_out',
                    'usage_type' => 'reusable',
                    'quantity' => -$fulfilled,
                    'receipt_unit_cost' => $boards->avg(fn (Board $board) => (float) $board->current_value),
                    'balance_after' => $stock?->fresh()->quantity_on_hand ?? 0,
                    'project_id' => $boardRequest->project_id,
                    'project_material_id' => $boardRequest->project_material_id,
                    'reference_no' => $boardRequest->job_ref,   // enables outstandingReusables grouping by job
                    'recipient_name' => $boardRequest->recipient_name,
                    'notes' => "{$fulfilled} board(s) issued to job {$boardRequest->job_ref}. "
                        .'Codes: '.$boards->pluck('tracking_code')->join(', '),
                    'logged_at' => now(),
                ]);

                Board::whereIn('id', $boards->pluck('id'))->update([
                    'original_issue_log_id' => $issueLog->id,
                    'board_request_id' => $boardRequest->id,
                    'project_id' => $boardRequest->project_id,
                    'project_material_id' => $boardRequest->project_material_id,
                ]);

                // Board issues are cost-bearing Stores issues too. Report 79E
                // (P0-5): announced inside the transaction, as /movements does,
                // so the outbox row commits with the stock or not at all; the
                // outbox itself defers the posting until after commit.
                StockIssued::dispatch($issueLog);

                return [$boardRequest, $boards, $issueLog];
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Advance the workflow: create Logistics dispatch task + notify
        $this->workflow->onRequestFulfilled($boardRequest, $boards);

        // Report 79D: the board issue's own Finance outcome, not an assumption.
        $posting = app(\App\Modules\ProcurementStores\Services\StoresPostingResult::class)->forLogs([$issueLog->id]);

        return response()->json([
            'message' => trim("{$boards->count()} board(s) issued to job [{$boardRequest->job_ref}]. "
                .app(\App\Modules\ProcurementStores\Services\StoresPostingResult::class)->sentence($posting['summary'])),
            'posting' => $posting['lines'],
            'posting_summary' => $posting['summary'],
            'boards_issued' => $boards->pluck('tracking_code'),
            'request' => $boardRequest->fresh(['material', 'requester']),
        ]);
    }

    /** Leave the transaction with a 422 carrying this body (rolls back). */
    private function refuse(array $body): never
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json($body, 422));
    }

    /**
     * DELETE /board-requests/{id}
     * Cancel a pending request — releases the reservation.
     */
    public function cancel(int $id): JsonResponse
    {
        if (! auth()->user()?->can(Permissions::STORES_BOARD_MANAGE) && ! auth()->user()?->can(Permissions::STORES_MANAGE)) {
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
