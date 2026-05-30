<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\BoardMovement;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Requests\StoreBoardRequest;
use App\Modules\ProcurementStores\Services\BoardIngestionService;
use App\Modules\ProcurementStores\Services\BoardRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BoardController extends Controller
{
    public function __construct(
        private readonly BoardIngestionService    $ingestionService,
        private readonly BoardRegistrationService $registrationService,
    ) {}

    // ─── Ingestion ────────────────────────────────────────────────────────────

    /**
     * POST /boards/ingest
     * Create N board records from a Materials Library material.
     */
    public function ingest(StoreBoardRequest $request): JsonResponse
    {
        try {
            $boards = $this->ingestionService->ingestBatch(
                libraryMaterialId: $request->library_material_id,
                quantity:          $request->quantity,
                batchNumber:       $request->batch_number ?? 'BATCH-' . date('Ymd-His'),
                length:            $request->length,
                width:             $request->width,
                thickness:         $request->thickness,
                userId:            auth()->id(),
            );

            return response()->json([
                'message' => count($boards) . ' board(s) ingested successfully.',
                'boards'  => $this->formatBoards($boards),
                'count'   => count($boards),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ─── Board listing & detail ───────────────────────────────────────────────

    /**
     * GET /boards
     * List all boards with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Board::with(['libraryMaterial.workstation', 'movements' => fn($q) => $q->latest('ts')->limit(1)])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('batch_number')) {
            $query->where('batch_number', $request->batch_number);
        }

        if ($request->filled('job_ref')) {
            $query->where('assigned_job_ref', $request->job_ref);
        }

        if ($request->filled('is_offcut')) {
            $query->where('is_offcut', $request->boolean('is_offcut'));
        }

        if ($request->filled('library_material_id')) {
            $query->where('library_material_id', $request->library_material_id);
        }

        if ($request->filled('search')) {
            $query->where('tracking_code', 'like', '%' . $request->search . '%');
        }

        $paginator = $query->paginate($request->get('per_page', 30));

        // Transform each board so the material field matches the shape used by
        // formatBoardDetail — the frontend always reads board.material.name
        $paginator->getCollection()->transform(function (Board $board) {
            return [
                'id'               => $board->id,
                'tracking_code'    => $board->tracking_code,
                'batch_number'     => $board->batch_number,
                'status'           => $board->status,
                'length'           => $board->length,
                'width'            => $board->width,
                'thickness'        => $board->thickness,
                'area_m2'          => $board->area_m2,
                'current_value'    => $board->current_value,
                'is_offcut'        => $board->is_offcut,
                'assigned_job_ref' => $board->assigned_job_ref,
                'label_printed'    => $board->label_printed,
                // Age 1 — time in current status (immutable movement log timestamp)
                'last_movement_at' => $board->movements->first()?->ts,
                // Age 2 — board lifespan since physical receipt
                'created_at'       => $board->created_at,
                'updated_at'       => $board->updated_at,
                'material'         => $board->relationLoaded('libraryMaterial') && $board->libraryMaterial ? [
                    'id'          => $board->libraryMaterial->id,
                    'name'        => $board->libraryMaterial->material_name,
                    'code'        => $board->libraryMaterial->material_code,
                    'category'    => $board->libraryMaterial->category,
                    'subcategory' => $board->libraryMaterial->subcategory,
                ] : null,
            ];
        });

        return response()->json($paginator);
    }

    /**
     * GET /boards/by-code/{trackingCode}
     * Resolve a board by its human-readable tracking code (used by QR scan links).
     */
    public function showByCode(string $trackingCode): JsonResponse
    {
        $board = Board::with([
            'libraryMaterial.workstation',
            'movements.performer',
            'parent',
            'offcuts',
        ])->where('tracking_code', $trackingCode)->firstOrFail();

        return response()->json(['data' => $this->formatBoardDetail($board)]);
    }

    /**
     * GET /boards/available
     * Boards in Available status, optionally filtered by material.
     */
    public function available(Request $request): JsonResponse
    {
        $query = Board::available()
            ->with(['libraryMaterial.workstation'])
            ->latest();

        if ($request->filled('library_material_id')) {
            $query->where('library_material_id', $request->library_material_id);
        }

        $paginator = $query->paginate($request->get('per_page', 30));

        return response()->json([
            'data'  => $paginator,
            'count' => $paginator->total(),   // paginator already computed this
        ]);
    }

    /**
     * GET /boards/{id}
     * Full board detail with complete movement history.
     */
    public function show(int $id): JsonResponse
    {
        $board = Board::with([
            'libraryMaterial.workstation',
            'movements.performer',
            'parent',
            'offcuts',
        ])->findOrFail($id);

        return response()->json(['data' => $this->formatBoardDetail($board)]);
    }

    /**
     * GET /boards/job/{jobRef}
     * All boards assigned to a specific job.
     */
    public function byJob(string $jobRef): JsonResponse
    {
        $boards = Board::byJob($jobRef)
            ->with(['libraryMaterial.workstation', 'movements' => fn($q) => $q->latest('ts')->limit(1)])
            ->get();

        return response()->json([
            'data'    => $boards->map(fn($b) => $this->formatBoardDetail($b)),
            'job_ref' => $jobRef,
            'count'   => $boards->count(),
        ]);
    }

    // ─── Lifecycle transitions ────────────────────────────────────────────────

    /**
     * POST /boards/{id}/allocate
     * Allocate a board to a production job.
     *
     * This is the direct-allocation path (used from the board detail UI).
     * It must decrement stock and write a check_out log here — the same work
     * that BoardRequestController::fulfil() does on the board-request path.
     * Without this, a board allocated directly never has its stock decremented,
     * so any subsequent offcut creation (which adds +1 for the offcut returning
     * to stores) results in a net stock increase when a board is consumed.
     */
    public function allocate(Request $request, int $id): JsonResponse
    {
        $request->validate(['job_ref' => 'required|string|max:100']);

        $board = Board::findOrFail($id);

        try {
            DB::transaction(function () use ($board, $request) {
                $board->transitionTo('Allocated', auth()->id(), $request->notes, $request->job_ref);

                $stock = Stock::where('material_id', $board->library_material_id)->first();
                if ($stock) {
                    $stock->decrement('quantity_on_hand', 1);
                }

                \App\Modules\ProcurementStores\Models\InventoryLog::create([
                    'material_id'  => $board->library_material_id,
                    'user_id'      => auth()->id(),
                    'type'         => 'check_out',
                    'usage_type'   => 'reusable',
                    'batch_number' => $board->batch_number,
                    'quantity'     => -1,
                    'balance_after'=> $stock?->fresh()->quantity_on_hand ?? 0,
                    'reference_no' => $request->job_ref,
                    'notes'        => "Board [{$board->tracking_code}] issued directly to job [{$request->job_ref}].",
                    'logged_at'    => now(),
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Board [{$board->tracking_code}] allocated to job [{$request->job_ref}].",
            'board'   => $this->formatBoardDetail($board->fresh(['movements', 'libraryMaterial'])),
        ]);
    }

    /**
     * POST /boards/{id}/start-processing
     * Advance board through the production flow:
     *   Allocated → At Station → WIP
     */
    public function startProcessing(int $id): JsonResponse
    {
        $board = Board::findOrFail($id);

        $next = match ($board->status) {
            'Allocated'  => 'At Station',
            'At Station' => 'WIP',
            default      => null,
        };

        if (!$next) {
            return response()->json([
                'message' => "Board is in [{$board->status}] — no further processing step available.",
            ], 422);
        }

        try {
            $board->transitionTo($next, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Board [{$board->tracking_code}] advanced to [{$next}].",
            'board'   => $this->formatBoardDetail($board->fresh(['movements', 'libraryMaterial'])),
        ]);
    }

    /**
     * POST /boards/{id}/consume
     * Mark board as Consumed. If offcut dimensions provided, generate an offcut board.
     */
    public function consume(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'offcut_length'    => 'nullable|integer|min:1',
            'offcut_width'     => 'nullable|integer|min:1',
            'offcut_thickness' => 'nullable|integer|min:1',
            'notes'            => 'nullable|string',
        ]);

        $board = Board::findOrFail($id);

        // Must be WIP before consuming
        if (!$board->hasStatus('WIP')) {
            try {
                $board->transitionTo('WIP', auth()->id(), 'Auto-advanced to WIP for consumption');
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $offcut = null;
        $hasOffcut = $request->filled('offcut_length') && $request->filled('offcut_width');

        try {
            if ($hasOffcut) {
                $offcut = $this->registrationService->registerOffcut(
                    parentBoard: $board,
                    length:      (int) $request->offcut_length,
                    width:       (int) $request->offcut_width,
                    thickness:   (int) ($request->offcut_thickness ?? $board->thickness),
                    userId:      auth()->id(),
                );
            } else {
                $board->transitionTo('Consumed', auth()->id(), $request->notes ?? 'Fully consumed');
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Stock was already decremented when the board was issued to the job (fulfil endpoint).
        // We only write a production note to the activity log — no stock movement here.

        return response()->json([
            'message' => "Board [{$board->tracking_code}] consumed." . ($offcut ? " Offcut [{$offcut->tracking_code}] created." : ''),
            'board'   => $this->formatBoardDetail($board->fresh(['movements', 'libraryMaterial'])),
            'offcut'  => $offcut ? $this->formatBoardDetail($offcut->load(['movements', 'libraryMaterial'])) : null,
        ]);
    }

    // ─── Dashboard endpoints ──────────────────────────────────────────────────

    /**
     * GET /boards/command-center-metrics
     * KPIs and stock movement summary for the dashboard.
     */
    public function commandCenterMetrics(): JsonResponse
    {
        // Single query replaces 7 sequential COUNTs
        $counts = DB::table('boards')
            ->selectRaw("
                COUNT(*)                                                        AS total,
                SUM(CASE WHEN status = 'Available'                       THEN 1 ELSE 0 END) AS available,
                SUM(CASE WHEN status IN ('Allocated','At Station','WIP')  THEN 1 ELSE 0 END) AS on_job,
                SUM(CASE WHEN status = 'Consumed'                        THEN 1 ELSE 0 END) AS consumed,
                SUM(CASE WHEN status = 'Scrapped'                        THEN 1 ELSE 0 END) AS scrapped,
                SUM(CASE WHEN is_offcut = 1 AND status = 'Available'     THEN 1 ELSE 0 END) AS offcuts,
                SUM(CASE WHEN status IN ('Allocated','At Station','WIP')
                          AND updated_at < ?                              THEN 1 ELSE 0 END) AS overdue
            ", [now()->subDays(14)])
            ->first();

        $total     = (int) $counts->total;
        $available = (int) $counts->available;
        $onJob     = (int) $counts->on_job;
        $consumed  = (int) $counts->consumed;
        $scrapped  = (int) $counts->scrapped;
        $offcuts   = (int) $counts->offcuts;
        $overdue   = (int) $counts->overdue;

        // Recent activity — last 10 movements
        $recentActivity = BoardMovement::with(['board', 'performer'])
            ->latest('ts')
            ->limit(10)
            ->get()
            ->map(fn($m) => [
                'board_code'   => $m->board->tracking_code ?? '—',
                'from_status'  => $m->from_status,
                'to_status'    => $m->to_status,
                'performed_by' => $m->performer?->name ?? 'System',
                'ts'           => $m->ts,
            ]);

        return response()->json([
            'kpis' => [
                'total_boards'    => $total,
                'available'       => $available,
                'on_job'          => $onJob,
                'consumed'        => $consumed,
                'scrapped'        => $scrapped,
                'offcuts_in_stock'=> $offcuts,
                'overdue_on_job'  => $overdue,
            ],
            'stock_movement' => [
                'available' => $available,
                'on_job'    => $onJob,
                'consumed'  => $consumed,
                'scrapped'  => $scrapped,
            ],
            'recent_gate_activity' => $recentActivity,
            'open_exceptions'      => [],
        ]);
    }

    /**
     * GET /boards/stock-registry
     * Inventory view — boards grouped by material.
     */
    public function stockRegistry(Request $request): JsonResponse
    {
        // Pre-load all available-board counts in one query — eliminates N+1
        $availableCounts = Board::query()
            ->selectRaw('library_material_id, COUNT(*) as cnt')
            ->where('status', 'Available')
            ->groupBy('library_material_id')
            ->pluck('cnt', 'library_material_id');

        $stocks = Stock::with(['material.workstation'])
            ->where('tracking_mode', Stock::TRACK_BY_AREA)
            ->get()
            ->map(function ($stock) use ($availableCounts) {
                $material   = $stock->material;
                $boardCount = (int) ($availableCounts[$material?->id] ?? 0);

                $statusLabel = match (true) {
                    $boardCount === 0                                                      => 'Critical',
                    $stock->min_stock_level > 0 && $boardCount <= $stock->min_stock_level => 'Low Stock',
                    default                                                                => 'Optimal',
                };

                return [
                    'id'           => $stock->id,
                    'sku'          => $material?->material_code ?? '—',
                    'name'         => $material?->material_name ?? '—',
                    'workstation'  => $material?->workstation?->name ?? '—',
                    'qty'          => $boardCount,
                    'reorder_point'=> (int) $stock->min_stock_level,
                    'status'       => $statusLabel,
                    'is_board'     => true,
                    'unit_label'   => 'boards',
                    'unit_cost'    => (float) ($material?->unit_cost ?? 0),
                    'total_value'  => round($boardCount * ($material?->unit_cost ?? 0), 2),
                ];
            });

        return response()->json([
            'total_items'      => $stocks->count(),
            'low_stock_alerts' => $stocks->where('status', 'Low Stock')->count() + $stocks->where('status', 'Critical')->count(),
            'stock_value'      => $stocks->sum('total_value'),
            'items'            => $stocks->values(),
        ]);
    }

    /**
     * GET /boards/compliance-exceptions
     * Boards that need attention (overdue, scrapped, etc.).
     */
    public function complianceExceptions(): JsonResponse
    {
        $overdue = Board::onJob()
            ->where('updated_at', '<', now()->subDays(14))
            ->with(['libraryMaterial'])
            ->get()
            ->map(fn($b) => [
                'id'          => $b->id,
                'code'        => $b->tracking_code,
                'type'        => 'overdue_on_job',
                'description' => "Board on job [{$b->assigned_job_ref}] for over 14 days",
                'priority'    => 'high',
                'status'      => 'Open',
                'job_ref'     => $b->assigned_job_ref,
                'raised_by'   => 'System',
                'created_at'  => $b->updated_at,
            ]);

        return response()->json([
            'summary' => [
                'total'            => $overdue->count(),
                'open'             => $overdue->count(),
                'pending'          => 0,
                'resolved_today'   => 0,
                'avg_resolution_hrs' => 0,
            ],
            'exceptions'    => $overdue->values(),
            'approval_queue' => [],
        ]);
    }

    /**
     * GET /boards/consumption-details
     * Active job board usage summary.
     */
    public function consumptionDetails(): JsonResponse
    {
        $jobGroups = Board::onJob()
            ->whereNotNull('assigned_job_ref')
            ->get()
            ->groupBy('assigned_job_ref')
            ->map(fn($boards, $jobRef) => [
                'id'      => $jobRef,
                'name'    => $jobRef,
                'progress'=> 0,
                'req_m2'  => 0,
                'used_m2' => round($boards->sum('area_m2'), 4),
            ])
            ->values();

        $consumedArea = Board::where('status', 'Consumed')->sum('area_m2');
        $offcutArea   = Board::where('is_offcut', true)->where('status', 'Available')->sum('area_m2');

        return response()->json([
            'active_jobs'   => $jobGroups,
            'material_usage' => [
                'target_yield'   => 100,
                'current_yield'  => 0,
                'waste_m2'       => 0,
                'offcuts_m2'     => round($offcutArea, 4),
                'consumed_m2'    => round($consumedArea, 4),
            ],
        ]);
    }

    /**
     * POST /boards/job/{jobRef}/calculate-variance
     */
    public function calculateVariance(Request $request, string $jobRef): JsonResponse
    {
        $request->validate(['expected_area' => 'nullable|numeric|min:0']);

        $boards      = Board::byJob($jobRef)->get();
        $actualArea  = round($boards->sum('area_m2'), 4);
        $expectedArea= (float) ($request->expected_area ?? $actualArea);
        $variancePct = $expectedArea > 0 ? round((($actualArea - $expectedArea) / $expectedArea) * 100, 2) : 0;
        $tolerance   = config('boards.variance_tolerance_multiplier', 1.05);

        return response()->json([
            'job_ref'          => $jobRef,
            'board_count'      => $boards->count(),
            'expected_area_m2' => $expectedArea,
            'actual_area_m2'   => $actualArea,
            'variance_pct'     => $variancePct,
            'within_tolerance' => $actualArea <= ($expectedArea * $tolerance),
            'tolerance_pct'    => round(($tolerance - 1) * 100, 1),
        ]);
    }

    // ─── Update (super admin only, Quarantine status only) ───────────────────

    /**
     * PUT /boards/{id}
     * Correct metadata on a board that has not yet left Quarantine.
     * Only batch_number, dimensions, and current_value may be changed.
     * Restricted to Super Admin.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (!auth()->user()?->hasRole('Super Admin')) {
            return response()->json(['message' => 'Only Super Admins can edit board records.'], 403);
        }

        $board = Board::findOrFail($id);

        if ($board->status !== 'Quarantine') {
            return response()->json([
                'message' => "Board [{$board->tracking_code}] is in [{$board->status}] status. "
                    . 'Editing is only permitted while a board is in Quarantine (before labels are printed).',
            ], 422);
        }

        $request->validate([
            'batch_number'  => 'sometimes|string|max:100',
            'length'        => 'sometimes|integer|min:1',
            'width'         => 'sometimes|integer|min:1',
            'thickness'     => 'sometimes|integer|min:1',
            'current_value' => 'sometimes|numeric|min:0',
        ]);

        $changes = [];
        $updatable = ['batch_number', 'length', 'width', 'thickness', 'current_value'];

        foreach ($updatable as $field) {
            if ($request->has($field) && $request->$field != $board->$field) {
                $changes[$field] = ['from' => $board->$field, 'to' => $request->$field];
                $board->$field = $request->$field;
            }
        }

        if (empty($changes)) {
            return response()->json(['message' => 'No changes detected.', 'board' => $this->formatBoardDetail($board)]);
        }

        // Recalculate area if dimensions changed
        if (isset($changes['length']) || isset($changes['width'])) {
            $board->area_m2 = round(($board->length * $board->width) / 1_000_000, 4);
        }

        $board->save();

        // Append an audit movement so the change is visible in the board timeline
        $summary = collect($changes)
            ->map(fn($c, $f) => "{$f}: {$c['from']} → {$c['to']}")
            ->join(', ');

        BoardMovement::create([
            'board_id'     => $board->id,
            'from_status'  => 'Quarantine',
            'to_status'    => 'Quarantine',
            'performed_by' => auth()->id(),
            'notes'        => "Correction by Super Admin — {$summary}",
        ]);

        return response()->json([
            'message' => "Board [{$board->tracking_code}] updated.",
            'board'   => $this->formatBoardDetail($board->fresh(['movements', 'libraryMaterial'])),
            'changes' => $changes,
        ]);
    }

    // ─── Delete (super admin only) ────────────────────────────────────────────

    /**
     * DELETE /boards/{id}
     * Permanently remove a board record. Restricted to Super Admin.
     * Writes a final movement entry for audit before deletion.
     */
    public function destroy(int $id): JsonResponse
    {
        if (!auth()->user()?->hasRole('Super Admin')) {
            return response()->json(['message' => 'Only Super Admins can delete board records.'], 403);
        }

        $board = Board::with('libraryMaterial')->findOrFail($id);

        DB::transaction(function () use ($board) {
            // Final audit entry before the row is gone
            BoardMovement::create([
                'board_id'     => $board->id,
                'from_status'  => $board->status,
                'to_status'    => 'Deleted',
                'performed_by' => auth()->id(),
                'notes'        => 'Board record permanently deleted by Super Admin.',
            ]);

            // Release any reserved stock if board was holding a reservation
            if (in_array($board->status, ['Available', 'Quarantine'])) {
                Stock::where('material_id', $board->library_material_id)
                    ->decrement('quantity_on_hand', 1);
            }

            $board->delete();
        });

        return response()->json(['message' => "Board [{$board->tracking_code}] permanently deleted."]);
    }

    // ─── Label confirmation ───────────────────────────────────────────────────

    /**
     * POST /boards/batch/{batchNumber}/confirm-labels
     * Mark all boards in a batch as label_printed and transition Quarantine → Available.
     * Called after the storekeeper prints and sticks labels on physical boards.
     */
    public function confirmLabels(string $batchNumber): JsonResponse
    {
        $boards = Board::where('batch_number', $batchNumber)
            ->where('status', 'Quarantine')
            ->where('label_printed', false)
            ->get();

        if ($boards->isEmpty()) {
            return response()->json([
                'message' => "No boards pending label confirmation in batch [{$batchNumber}].",
            ], 404);
        }

        $confirmed = 0;
        DB::transaction(function () use ($boards, &$confirmed) {
            foreach ($boards as $board) {
                $board->update([
                    'label_printed'    => true,
                    'label_printed_by' => auth()->id(),
                    'label_printed_at' => now(),
                ]);
                $board->transitionTo('Available', auth()->id(), 'Labels printed and confirmed by storekeeper');
                $confirmed++;
            }
        });

        return response()->json([
            'message'   => "{$confirmed} board(s) confirmed — now Available in stores.",
            'confirmed' => $confirmed,
            'batch'     => $batchNumber,
        ]);
    }

    // ─── Generic transition ───────────────────────────────────────────────────

    /**
     * POST /boards/{id}/transition
     * Apply any valid status transition. Used by the frontend lifecycle manager
     * for transitions not covered by a dedicated endpoint (e.g. Scrap, At Station,
     * return to Available).
     */
    public function transition(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status'  => ['required', 'string', 'in:' . implode(',', config('boards.statuses', []))],
            'notes'   => 'nullable|string|max:500',
            'job_ref' => 'nullable|string|max:100',
        ]);

        $board          = Board::findOrFail($id);
        $previousStatus = $board->status;

        try {
            $board->transitionTo(
                $request->status,
                auth()->id(),
                $request->notes,
                $request->job_ref,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $stock = Stock::where('material_id', $board->library_material_id)->first();

        // Return to stores — board came back intact from a job
        // Triggered from: Allocated, At Station, or WIP → Available
        if ($request->status === 'Available' && in_array($previousStatus, ['Allocated', 'At Station', 'WIP'])) {
            if ($stock) {
                $stock->increment('quantity_on_hand', 1);
            }
            \App\Modules\ProcurementStores\Models\InventoryLog::create([
                'material_id'  => $board->library_material_id,
                'user_id'      => auth()->id(),
                'type'         => 'return',
                'usage_type'   => 'reusable',
                'batch_number' => $board->batch_number,
                'quantity'     => 1,
                'balance_after'=> $stock?->fresh()->quantity_on_hand ?? 0,
                'notes'        => "Board returned to stores: {$board->tracking_code}. "
                    . ($request->notes ?? 'Returned intact from job.'),
                'logged_at'    => now(),
            ]);
        }

        // Scrap: stock handling depends on where the board was in its lifecycle.
        //
        // — Available / Quarantine (never left stores):
        //   quantity_on_hand must be decremented here.
        //   Also clamp quantity_reserved so available never goes negative if a
        //   pending board request was holding a soft reservation on this material.
        //
        // — Allocated / At Station / WIP (already issued to a job):
        //   quantity_on_hand was decremented at fulfil()/allocate() time.
        //   No second deduction. The log quantity is 0 to show no stock change
        //   occurred here — the board was already off the books.
        if ($request->status === 'Scrapped') {
            $wasInStores = in_array($previousStatus, ['Available', 'Quarantine']);

            if ($wasInStores && $stock) {
                $stock->decrement('quantity_on_hand', 1);

                // Clamp reserved to on-hand so available_quantity never goes negative.
                // This can happen when a board request reserved N boards and one of
                // those Available boards is scrapped before the request is fulfilled.
                $fresh = $stock->fresh();
                if ($fresh->quantity_reserved > $fresh->quantity_on_hand) {
                    $fresh->update(['quantity_reserved' => $fresh->quantity_on_hand]);
                }
            }

            \App\Modules\ProcurementStores\Models\InventoryLog::create([
                'material_id'  => $board->library_material_id,
                'user_id'      => auth()->id(),
                'type'         => 'defective',
                'usage_type'   => 'reusable',
                'batch_number' => $board->batch_number,
                // quantity = -1 only when stock actually decremented here.
                // quantity = 0 means board was already off the books (post-issue scrap).
                'quantity'     => $wasInStores ? -1 : 0,
                'balance_after'=> $stock?->fresh()->quantity_on_hand ?? 0,
                'notes'        => "Board scrapped: {$board->tracking_code}"
                    . " (was {$previousStatus}"
                    . ($wasInStores ? ', stock decremented' : ', stock already decremented at issue')
                    . '). '
                    . ($request->notes ?? 'No reason recorded.'),
                'logged_at'    => now(),
            ]);
        }

        return response()->json([
            'message' => "Board [{$board->tracking_code}] transitioned to [{$request->status}].",
            'board'   => $this->formatBoardDetail($board->fresh(['movements', 'libraryMaterial'])),
        ]);
    }

    // ─── Private formatters ───────────────────────────────────────────────────

    private function formatBoards(array $boards): array
    {
        return array_map(fn($b) => [
            'id'            => $b->id,
            'tracking_code' => $b->tracking_code,
            'batch_number'  => $b->batch_number,
            'status'        => $b->status,
            'length'        => $b->length,
            'width'         => $b->width,
            'thickness'     => $b->thickness,
            'area_m2'       => $b->area_m2,
            'current_value' => $b->current_value,
        ], $boards);
    }

    private function formatBoardDetail(Board $board): array
    {
        return [
            'id'              => $board->id,
            'tracking_code'   => $board->tracking_code,
            'batch_number'    => $board->batch_number,
            'status'          => $board->status,
            'length'          => $board->length,
            'width'           => $board->width,
            'thickness'       => $board->thickness,
            'area_m2'         => $board->area_m2,
            'current_value'   => $board->current_value,
            'is_offcut'       => $board->is_offcut,
            'parent_board_id' => $board->parent_board_id,
            'assigned_job_ref'=> $board->assigned_job_ref,
            'material'        => $board->relationLoaded('libraryMaterial') ? [
                'id'            => $board->libraryMaterial?->id,
                'name'          => $board->libraryMaterial?->material_name,
                'code'          => $board->libraryMaterial?->material_code,
                'category'      => $board->libraryMaterial?->category,
                'subcategory'   => $board->libraryMaterial?->subcategory,
                'workstation'   => $board->libraryMaterial?->workstation?->name,
            ] : null,
            'movements'       => $board->relationLoaded('movements')
                ? $board->movements->map(fn($m) => [
                    'from_status'  => $m->from_status,
                    'to_status'    => $m->to_status,
                    'job_ref'      => $m->job_ref,
                    'performed_by' => $m->performer?->name ?? 'System',
                    'notes'        => $m->notes,
                    'ts'           => $m->ts,
                ])
                : [],
            'offcuts'         => $board->relationLoaded('offcuts')
                ? $board->offcuts->map(fn($o) => [
                    'id'            => $o->id,
                    'tracking_code' => $o->tracking_code,
                    'status'        => $o->status,
                    'area_m2'       => $o->area_m2,
                    'current_value' => $o->current_value,
                ])
                : [],
            'scan_url'        => config('app.frontend_url', config('app.url')) . '/stores/boards/' . $board->tracking_code,
            'created_at'      => $board->created_at,
            'updated_at'      => $board->updated_at,
        ];
    }
}
