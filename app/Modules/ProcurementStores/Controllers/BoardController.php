<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\BoardMovement;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Requests\StoreBoardRequest;
use App\Modules\ProcurementStores\Services\BoardIngestionService;
use App\Modules\ProcurementStores\Services\BoardRegistrationService;
use App\Modules\ProcurementStores\Services\BoardWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BoardController extends Controller
{
    public function __construct(
        private readonly BoardIngestionService    $ingestionService,
        private readonly BoardRegistrationService $registrationService,
        private readonly BoardWorkflowService     $workflow,
    ) {}

    // ─── Ingestion ────────────────────────────────────────────────────────────

    /**
     * POST /boards/ingest
     * Create N board records from a Materials Library material.
     */
    public function ingest(StoreBoardRequest $request): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Stores', 'Super Admin'])) {
            return response()->json(['message' => 'Only Stores team members can receive boards.'], 403);
        }

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
                'message' => count($boards) . ' board(s) received successfully.',
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
        $query = Board::with(['libraryMaterial.workstation', 'parent:id,tracking_code', 'movements' => fn($q) => $q->latest('ts')->limit(1)])
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
                'parent_board_id'  => $board->parent_board_id,
                'parent_tracking_code' => $board->parent?->tracking_code,
                'assigned_job_ref' => $board->assigned_job_ref,
                'condition_grade'  => $board->condition_grade,
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
        if (!auth()->user()?->hasAnyRole(['Production', 'Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'You are not permitted to allocate boards.'], 403);
        }

        $request->validate(['job_ref' => 'required|string|max:100']);

        $board = Board::findOrFail($id);

        try {
            DB::transaction(function () use ($board, $request) {
                $board->transitionTo('Allocated', auth()->id(), $request->notes, $request->job_ref);

                $stock = Stock::where('material_id', $board->library_material_id)->first();
                if ($stock) {
                    $stock->decrement('quantity_on_hand', 1);

                    // Direct allocation bypasses the board-request reservation flow.
                    // If a pending request had soft-reserved this material, the board we
                    // just issued may have been one of the reserved units — clamp reserved
                    // down to on-hand so available_quantity (on_hand − reserved) can't go
                    // negative and the later fulfil() can't over-issue.
                    $fresh = $stock->fresh();
                    if ($fresh->quantity_reserved > $fresh->quantity_on_hand) {
                        $fresh->update(['quantity_reserved' => $fresh->quantity_on_hand]);
                    }
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
            'message' => "Board [{$board->tracking_code}] reserved for job [{$request->job_ref}].",
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
        if (!auth()->user()?->hasAnyRole(['Production', 'Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'You are not permitted to process boards.'], 403);
        }

        $board = Board::findOrFail($id);

        $next = match ($board->status) {
            'Allocated'  => 'At Station',
            'At Station' => 'WIP',
            default      => null,
        };

        if (!$next) {
            return response()->json([
                'message' => "This board has no next action from its current step.",
            ], 422);
        }

        try {
            $board->transitionTo($next, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // When a board goes WIP, close the Production boards_at_station task for that job
        if ($next === 'WIP' && $board->assigned_job_ref) {
            $this->workflow->startWip($board->assigned_job_ref, collect([$board]), auth()->user());
        }

        return response()->json([
            'message' => "Board [{$board->tracking_code}] moved to the next step.",
            'board'   => $this->formatBoardDetail($board->fresh(['movements', 'libraryMaterial'])),
        ]);
    }

    /**
     * POST /boards/job/{jobRef}/dispatch-to-station
     * Logistics marks all Allocated boards for a job as delivered to the station.
     * Transitions Allocated → At Station and fires the workflow event.
     */
    public function dispatchToStation(Request $request, string $jobRef): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Production', 'Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'Only Production, Stores or Managers can send boards to Production.'], 403);
        }

        $request->validate([
            'board_ids' => 'nullable|array',
            'board_ids.*' => 'integer|exists:boards,id',
        ]);

        $query = Board::where('assigned_job_ref', $jobRef)->where('status', 'Allocated');

        if (!empty($request->board_ids)) {
            $query->whereIn('id', $request->board_ids);
        }

        $boards = $query->get();

        if ($boards->isEmpty()) {
            return response()->json(['message' => "No reserved boards were found for job [{$jobRef}]."], 422);
        }

        $this->workflow->onBoardsDispatched($jobRef, $boards, auth()->user());

        return response()->json([
            'message'        => "{$boards->count()} board(s) sent to Production for job [{$jobRef}].",
            'boards_at_station' => $boards->pluck('tracking_code'),
        ]);
    }

    /**
     * GET /boards/workflow-tasks
     * Returns pending workflow tasks for the authenticated user's role.
     */
    public function workflowTasks(): JsonResponse
    {
        $role = auth()->user()->getRoleNames()->first() ?? '';

        $tasks = $this->workflow->pendingTasksForRole($role);

        return response()->json(['data' => $tasks]);
    }

    /**
     * POST /workflow-tasks/{taskId}/claim
     * Atomically claim a pending task to prevent double-processing.
     */
    public function claimWorkflowTask(int $taskId): JsonResponse
    {
        try {
            $task = $this->workflow->claimTask($taskId, auth()->user());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => 'Task not found or already claimed.'], 404);
        }

        return response()->json(['data' => $task]);
    }

    /**
     * POST /workflow-tasks/{taskId}/return-offcut
     * Storekeeper marks an offcut as returned to the rack.
     * Transitions offcut board Quarantine → Available and closes the task.
     */
    public function returnOffcut(int $taskId): JsonResponse
    {
        try {
            $task = $this->workflow->returnOffcut($taskId, auth()->user());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => 'Task not found or already completed.'], 404);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Offcut returned to stores rack.', 'data' => $task]);
    }

    /**
     * POST /boards/job/{jobRef}/start-wip
     * Production batch-starts WIP for all At Station boards on a job.
     * Closes the boards_at_station workflow task.
     */
    public function startWip(Request $request, string $jobRef): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Production', 'Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'Only Production, Stores or Managers can start board work.'], 403);
        }

        $boards = Board::where('assigned_job_ref', $jobRef)
            ->where('status', 'At Station')
            ->get();

        if ($boards->isEmpty()) {
            return response()->json(['message' => "No boards at Production were found for job [{$jobRef}]."], 422);
        }

        $this->workflow->startWip($jobRef, $boards, auth()->user());

        return response()->json([
            'message'   => "Work started on {$boards->count()} board(s) for job [{$jobRef}].",
            'board_ids' => $boards->pluck('id'),
        ]);
    }

    /**
     * GET /boards/my-wip
     * Returns all WIP boards for the current Production user to consume.
     */
    public function myWipBoards(): JsonResponse
    {
        $boards = Board::with('libraryMaterial')
            ->where('status', 'WIP')
            ->orderBy('updated_at')
            ->get()
            ->map(fn (Board $b) => [
                'id'            => $b->id,
                'tracking_code' => $b->tracking_code,
                'job_ref'       => $b->assigned_job_ref,
                'material'      => $b->libraryMaterial?->material_name,
                'dimensions'    => "{$b->length}×{$b->width}×{$b->thickness}mm",
                'status'        => $b->status,
            ]);

        return response()->json(['data' => $boards]);
    }

    /**
     * POST /boards/{id}/consume
     * Mark board as Consumed. If offcut dimensions provided, generate an offcut board.
     */
    public function consume(Request $request, int $id): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Production', 'Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'You are not permitted to record board use.'], 403);
        }

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

        $offcut    = null;
        $hasOffcut = $request->filled('offcut_length') && $request->filled('offcut_width');

        // Offcut dimensions must be physically possible — reject anything that exceeds the parent.
        if ($hasOffcut) {
            $errors = [];
            if ((int) $request->offcut_length > $board->length) {
                $errors[] = "Offcut length ({$request->offcut_length}mm) exceeds parent board length ({$board->length}mm).";
            }
            if ((int) $request->offcut_width > $board->width) {
                $errors[] = "Offcut width ({$request->offcut_width}mm) exceeds parent board width ({$board->width}mm).";
            }
            if ($request->filled('offcut_thickness') && (int) $request->offcut_thickness > $board->thickness) {
                $errors[] = "Offcut thickness ({$request->offcut_thickness}mm) exceeds parent board thickness ({$board->thickness}mm).";
            }
            if ($errors) {
                return response()->json([
                    'message' => implode(' ', $errors),
                    'parent'  => ['length' => $board->length, 'width' => $board->width, 'thickness' => $board->thickness],
                ], 422);
            }
        }

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

        // If an offcut was created, fire the return-to-rack workflow task for Stores
        if ($offcut) {
            $this->workflow->onOffcutRegistered($offcut);
        }

        return response()->json([
            'message' => "Board [{$board->tracking_code}] recorded as used." . ($offcut ? " Remaining piece [{$offcut->tracking_code}] created." : ''),
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

        // Condition grade breakdown for active (non-terminal) boards
        $gradeCounts = DB::table('boards')
            ->selectRaw("condition_grade, COUNT(*) as cnt")
            ->whereNotIn('status', ['Consumed', 'Scrapped'])
            ->whereNotNull('condition_grade')
            ->groupBy('condition_grade')
            ->pluck('cnt', 'condition_grade');

        $ungradedCount = DB::table('boards')
            ->whereNotIn('status', ['Consumed', 'Scrapped'])
            ->whereNull('condition_grade')
            ->count();

        // Top scrap reasons over last 90 days
        $scrapReasons = DB::table('board_movements')
            ->selectRaw("scrap_reason_code, COUNT(*) as cnt")
            ->where('to_status', 'Scrapped')
            ->whereNotNull('scrap_reason_code')
            ->where('ts', '>=', now()->subDays(90))
            ->groupBy('scrap_reason_code')
            ->orderByDesc('cnt')
            ->limit(5)
            ->get()
            ->map(fn($r) => ['reason' => $r->scrap_reason_code, 'count' => (int) $r->cnt]);

        // Recent activity — last 10 movements
        $recentActivity = BoardMovement::with(['board', 'performer'])
            ->latest('ts')
            ->limit(10)
            ->get()
            ->map(fn($m) => [
                'board_code'       => $m->board->tracking_code ?? '—',
                'from_status'      => $m->from_status,
                'to_status'        => $m->to_status,
                'performed_by'     => $m->performer?->name ?? 'System',
                'condition_grade'  => $m->condition_grade,
                'scrap_reason_code'=> $m->scrap_reason_code,
                'ts'               => $m->ts,
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
            'condition_breakdown' => [
                'A'        => (int) ($gradeCounts['A'] ?? 0),
                'B'        => (int) ($gradeCounts['B'] ?? 0),
                'C'        => (int) ($gradeCounts['C'] ?? 0),
                'D'        => (int) ($gradeCounts['D'] ?? 0),
                'ungraded' => (int) $ungradedCount,
            ],
            'top_scrap_reasons'    => $scrapReasons,
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
        // Pre-load per-material counts in two queries — eliminates N+1
        $availableCounts = Board::query()
            ->selectRaw('library_material_id, COUNT(*) as cnt')
            ->where('status', 'Available')
            ->groupBy('library_material_id')
            ->pluck('cnt', 'library_material_id');

        $onJobCounts = Board::query()
            ->selectRaw('library_material_id, COUNT(*) as cnt')
            ->whereIn('status', ['Allocated', 'At Station', 'WIP'])
            ->groupBy('library_material_id')
            ->pluck('cnt', 'library_material_id');

        // Value from each board's stored current_value, not the live material
        // unit_cost. Offcuts carry a reduced proportional value, and editing a
        // material's price must not retroactively restate already-received stock.
        $availableValues = Board::query()
            ->selectRaw('library_material_id, SUM(current_value) as val')
            ->where('status', 'Available')
            ->groupBy('library_material_id')
            ->pluck('val', 'library_material_id');

        $stocks = Stock::with(['material.workstation'])
            ->where('tracking_mode', Stock::TRACK_BY_AREA)
            ->get()
            ->map(function ($stock) use ($availableCounts, $onJobCounts, $availableValues) {
                $material   = $stock->material;
                $boardCount = (int) ($availableCounts[$material?->id] ?? 0);
                $onJob      = (int) ($onJobCounts[$material?->id]    ?? 0);

                $statusLabel = match (true) {
                    $boardCount === 0                                                      => 'Critical',
                    $stock->min_stock_level > 0 && $boardCount <= $stock->min_stock_level => 'Low Stock',
                    default                                                                => 'Optimal',
                };

                return [
                    'id'                  => $stock->id,
                    'library_material_id' => $material?->id,
                    'sku'                 => $material?->material_code ?? '—',
                    'name'                => $material?->material_name ?? '—',
                    'workstation'         => $material?->workstation?->name ?? '—',
                    'qty'                 => $boardCount,
                    'on_job'              => $onJob,
                    'reorder_point'       => (int) $stock->min_stock_level,
                    'status'              => $statusLabel,
                    'is_board'            => true,
                    'unit_label'          => 'boards',
                    'unit_cost'           => (float) ($material?->unit_cost ?? 0),
                    'total_value'         => round((float) ($availableValues[$material?->id] ?? 0), 2),
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

    // ─── Reconciliation ───────────────────────────────────────────────────────

    /**
     * POST /boards/reconciliation
     * Persist a completed reconciliation session to the database.
     * This is the single source of truth — localStorage is no longer the record.
     */
    public function saveReconciliation(Request $request): JsonResponse
    {
        $request->validate([
            'reconciler_name'  => 'required|string|max:255',
            'physical_count'   => 'nullable|integer|min:0',
            'system_count'     => 'required|integer|min:0',
            'variance'         => 'nullable|integer',
            'steps_completed'  => 'required|array|size:6',
            'steps_completed.*'=> 'boolean',
            'status_snapshot'  => 'required|array',
            'gap_notes'        => 'nullable|string|max:5000',
            'started_at'       => 'required|date',
        ]);

        $record = DB::table('board_reconciliations')->insertGetId([
            'performed_by'    => auth()->id(),
            'reconciler_name' => $request->reconciler_name,
            'physical_count'  => $request->physical_count,
            'system_count'    => $request->system_count,
            'variance'        => $request->variance,
            'steps_completed' => json_encode($request->steps_completed),
            'status_snapshot' => json_encode($request->status_snapshot),
            'gap_notes'       => $request->gap_notes,
            'started_at'      => $request->started_at,
            'completed_at'    => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return response()->json([
            'message' => 'Stock count saved.',
            'id'      => $record,
        ], 201);
    }

    /**
     * GET /boards/reconciliation/latest
     * Return the most recently completed reconciliation session.
     * Used by the frontend to show "last reconciled X days ago by Y" across all devices.
     */
    public function latestReconciliation(): JsonResponse
    {
        $row = DB::table('board_reconciliations')
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->first();

        if (!$row) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'id'              => $row->id,
                'reconciler_name' => $row->reconciler_name,
                'physical_count'  => $row->physical_count,
                'system_count'    => $row->system_count,
                'variance'        => $row->variance,
                'steps_completed' => json_decode($row->steps_completed, true),
                'status_snapshot' => json_decode($row->status_snapshot, true),
                'gap_notes'       => $row->gap_notes,
                'started_at'      => $row->started_at,
                'completed_at'    => $row->completed_at,
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
     * Apply condition grades, mark labels printed, and transition Quarantine → Available.
     * Called after the storekeeper inspects, grades, and prints labels on the batch.
     *
     * Payload:
     *   condition_grade  — batch-level default grade (A/B/C/D)
     *   condition_notes  — optional free text about the batch condition
     *   board_grades[]   — optional per-board overrides: [{ id, condition_grade }]
     */
    public function confirmLabels(Request $request, string $batchNumber): JsonResponse
    {
        if (!auth()->user()?->hasAnyRole(['Stores', 'Super Admin'])) {
            return response()->json(['message' => 'Only Stores team members can confirm labels.'], 403);
        }

        $request->validate([
            'condition_grade'                => 'required|in:A,B,C,D',
            'condition_notes'                => 'nullable|string|max:500',
            'board_grades'                   => 'nullable|array',
            'board_grades.*.id'              => 'required|integer|exists:boards,id',
            'board_grades.*.condition_grade' => 'required|in:A,B,C,D',
        ]);

        $batchGrade  = $request->condition_grade;
        $perBoardMap = collect($request->board_grades ?? [])
            ->keyBy('id')
            ->map(fn($bg) => $bg['condition_grade']);

        $boards = Board::where('batch_number', $batchNumber)
            ->where('status', 'Quarantine')
            ->get();

        if ($boards->isEmpty()) {
            return response()->json([
                'message' => "No boards awaiting labels were found for batch [{$batchNumber}].",
            ], 404);
        }

        $confirmed = 0;
        DB::transaction(function () use ($boards, $batchGrade, $perBoardMap, $request, &$confirmed) {
            foreach ($boards as $board) {
                // Use per-board override if provided; fall back to batch default
                $grade = $perBoardMap[$board->id] ?? $batchGrade;

                $board->update([
                    'label_printed'    => true,
                    'label_printed_by' => auth()->id(),
                    'label_printed_at' => now(),
                ]);

                $board->transitionTo(
                    'Available',
                    auth()->id(),
                    $request->condition_notes ?? "Grade {$grade} — labels confirmed",
                    null,
                    $grade,
                    null,
                );
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
        if (!auth()->user()?->hasAnyRole(['Production', 'Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'You are not permitted to change board status.'], 403);
        }

        // Allocated is excluded — callers must use POST /boards/{id}/allocate so that
        // quantity_on_hand is decremented and an inventory log entry is written.
        // This endpoint handles everything else: Scrap, Quarantine returns, At Station, WIP.
        $allowedStatuses = array_diff(config('boards.statuses', []), ['Allocated']);

        $request->validate([
            'status'            => ['required', 'string', 'in:' . implode(',', $allowedStatuses)],
            'notes'             => 'nullable|string|max:500',
            'job_ref'           => 'nullable|string|max:100',
            'condition_grade'   => 'nullable|string|in:A,B,C,D',
            'scrap_reason_code' => 'nullable|string|max:80',
        ]);

        $board          = Board::findOrFail($id);
        $previousStatus = $board->status;
        $returnLog      = null;
        // Capture before transitionTo() clears assigned_job_ref on → Available / Quarantine
        $previousJobRef = $board->assigned_job_ref;

        try {
            DB::transaction(function () use ($request, $board, $previousStatus, $previousJobRef, &$returnLog) {
                $board->transitionTo(
                    $request->status,
                    auth()->id(),
                    $request->notes,
                    $request->job_ref,
                    $request->condition_grade,
                    $request->scrap_reason_code,
                );

                $stock = Stock::where('material_id', $board->library_material_id)->first();

        // Return to stores — board came back from a job (Available = intact, Quarantine = Grade C/D for review)
        // Triggered from: Allocated, At Station, or WIP → Available or Quarantine
        if (in_array($request->status, ['Available', 'Quarantine']) && in_array($previousStatus, ['Allocated', 'At Station', 'WIP'])) {
            $issue = \App\Modules\ProcurementStores\Models\InventoryLog::query()
                ->where('material_id', $board->library_material_id)
                ->where('reference_no', $previousJobRef)
                ->whereIn('type', ['check_out', 'issue', 'consumption'])
                ->latest('id')
                ->lockForUpdate()
                ->get()
                ->first(function ($candidate) {
                    $returned = (float) \App\Modules\ProcurementStores\Models\InventoryLog::query()
                        ->where('original_issue_log_id', $candidate->id)
                        ->where('type', 'return')
                        ->sum('quantity');

                    return $returned < abs((float) $candidate->quantity);
                });

            if (! $issue) {
                throw new \InvalidArgumentException('The original board issue could not be found. Reconcile this board before returning it.');
            }

            if ($stock) {
                $stock->increment('quantity_on_hand', 1);
            }
            $returnLog = \App\Modules\ProcurementStores\Models\InventoryLog::create([
                'material_id'  => $board->library_material_id,
                'user_id'      => auth()->id(),
                'type'         => 'return',
                'usage_type'   => 'reusable',
                'batch_number' => $board->batch_number,
                'quantity'     => 1,
                'balance_after'=> $stock?->fresh()->quantity_on_hand ?? 0,
                // reference_no ties this return to its check_out so outstandingReusables()
                // can net the two off and stop showing the board as still outstanding.
                'reference_no' => $previousJobRef,
                'original_issue_log_id' => $issue->id,
                'project_id' => $issue->project_id,
                'project_material_id' => $issue->project_material_id,
                'notes'        => "Board returned to stores: {$board->tracking_code}. "
                    . ($request->notes ?? 'Returned intact from job.'),
                'logged_at'    => now(),
            ]);

            // If the board was on the production floor, prompt Stores to physically rack it.
            if (in_array($previousStatus, ['At Station', 'WIP'])) {
                app(\App\Modules\ProcurementStores\Services\BoardWorkflowService::class)
                    ->onBoardReturned($board, $previousJobRef);
            }
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
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($returnLog) {
            \App\Events\Stores\StockReturned::dispatch($returnLog);
        }

        return response()->json([
            'message' => "Board [{$board->tracking_code}] updated successfully.",
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
            'condition_grade' => $board->condition_grade,
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
                    'from_status'      => $m->from_status,
                    'to_status'        => $m->to_status,
                    'job_ref'          => $m->job_ref,
                    'performed_by'     => $m->performer?->name ?? 'System',
                    'notes'            => $m->notes,
                    'condition_grade'  => $m->condition_grade,
                    'scrap_reason_code'=> $m->scrap_reason_code,
                    'ts'               => $m->ts,
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
