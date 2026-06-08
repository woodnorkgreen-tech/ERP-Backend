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
        $request->validate([
            'job_ref'  => 'required|string|max:100',
            'job_name' => 'nullable|string|max:255',
            'material_id' => 'required|integer|exists:library_materials,id',
            'qty'         => 'required|integer|min:1|max:200',
            'notes'       => 'nullable|string|max:500',
        ]);

        $material = LibraryMaterial::findOrFail($request->material_id);
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
                'job_name'     => $request->job_name,
                'material_id'  => $material->id,
                'qty_requested'=> $request->qty,
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
        $request->validate([
            'board_ids' => 'nullable|array',
            'board_ids.*' => 'integer|exists:boards,id',
        ]);

        $boardRequest = BoardRequest::with('material')->findOrFail($id);

        if ($boardRequest->isFulfilled()) {
            return response()->json(['message' => 'This request has already been fulfilled.'], 422);
        }

        $needed = $boardRequest->qty_requested - $boardRequest->qty_fulfilled;

        // Use provided board IDs or auto-select FIFO
        if (!empty($request->board_ids)) {
            $boards = Board::whereIn('id', $request->board_ids)
                ->where('library_material_id', $boardRequest->material_id)
                ->where('status', 'Available')
                ->get();

            if ($boards->count() !== count($request->board_ids)) {
                return response()->json(['message' => 'One or more selected boards are not available.'], 422);
            }
        } else {
            $boards = Board::where('library_material_id', $boardRequest->material_id)
                ->where('status', 'Available')
                ->oldest()
                ->limit($needed)
                ->get();
        }

        if ($boards->isEmpty()) {
            return response()->json(['message' => 'No available boards found for this material.'], 422);
        }

        DB::transaction(function () use ($boards, $boardRequest) {
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

            // Release reservation, deduct stock
            $stock = Stock::where('material_id', $boardRequest->material_id)->first();
            if ($stock) {
                $stock->decrement('quantity_reserved', $fulfilled);
                $stock->decrement('quantity_on_hand', $fulfilled);
            }

            InventoryLog::create([
                'material_id'  => $boardRequest->material_id,
                'user_id'      => auth()->id(),
                'type'         => 'check_out',
                'usage_type'   => 'reusable',
                'quantity'     => -$fulfilled,
                'balance_after'=> $stock?->fresh()->quantity_on_hand ?? 0,
                'reference_no' => $boardRequest->job_ref,   // enables outstandingReusables grouping by job
                'notes'        => "{$fulfilled} board(s) issued to job {$boardRequest->job_ref}. "
                    . 'Codes: ' . $boards->pluck('tracking_code')->join(', '),
                'logged_at'    => now(),
            ]);
        });

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
        $boardRequest = BoardRequest::findOrFail($id);

        if ($boardRequest->isFulfilled()) {
            return response()->json(['message' => 'Cannot cancel a fulfilled request.'], 422);
        }

        DB::transaction(function () use ($boardRequest) {
            $unreleased = $boardRequest->qty_requested - $boardRequest->qty_fulfilled;

            Stock::where('material_id', $boardRequest->material_id)
                ->decrement('quantity_reserved', $unreleased);

            $boardRequest->update(['status' => 'cancelled']);
        });

        return response()->json(['message' => 'Board request cancelled and reservation released.']);
    }
}
