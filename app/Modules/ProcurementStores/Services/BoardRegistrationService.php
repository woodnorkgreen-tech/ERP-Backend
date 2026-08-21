<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\BoardMovement;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use Illuminate\Support\Facades\DB;

/**
 * BoardRegistrationService — the SINGLE entry point for all board record creation.
 *
 * Every board that enters the system — whether from a delivery, a GRN, or as an
 * offcut from production — must be created through this service. No other class
 * calls Board::create() directly.
 *
 * Responsibilities:
 *   - Tracking code generation (private — nothing outside this class generates codes)
 *   - Board record creation (registerBoard — private)
 *   - Batch registration for deliveries (registerBatch)
 *   - Offcut registration from production (registerOffcut)
 *   - Material eligibility validation
 */
class BoardRegistrationService
{
    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Create board records only — no stock or inventory_log side-effects.
     *
     * Called by: ProcurementStoresController::checkIn() AFTER InventoryService
     * has already updated the stock and written the inventory log. Calling
     * registerBatch() from check-in would double-count both.
     *
     * @return Board[]
     */
    public function createBoardRecords(
        LibraryMaterial $material,
        int    $quantity,
        string $batchNumber,
        ?int   $length    = null,
        ?int   $width     = null,
        ?int   $thickness = null,
        ?int   $userId    = null,
        ?float $unitValue = null,
    ): array {
        $material->loadMissing(['workstation', 'materialCategory.parent']);

        // MaterialController wraps attributes as { "attributes": { ... } } on save.
        // Unwrap one level so dimension keys are directly accessible.
        $raw       = is_array($material->attributes) ? $material->attributes : [];
        $attrs     = $raw['attributes'] ?? $raw;
        $length    = $length    ?? ($attrs['standard_length_mm'] ?? config('boards.default_dimensions.length', 2440));
        $width     = $width     ?? ($attrs['standard_width_mm']  ?? config('boards.default_dimensions.width',  1220));
        $thickness = $thickness ?? ($attrs['thickness_mm']       ?? config('boards.default_dimensions.thickness', 18));
        $boardValue = $this->receiptValue($material, $unitValue);

        return DB::transaction(function () use ($material, $quantity, $batchNumber, $length, $width, $thickness, $userId, $boardValue) {
            $boards = [];
            for ($i = 0; $i < $quantity; $i++) {
                $boards[] = $this->registerBoard(
                    material:  $material,
                    batch:     $batchNumber,
                    status:    'Quarantine',
                    length:    $length,
                    width:     $width,
                    thickness: $thickness,
                    value:     $boardValue,
                    userId:    $userId,
                    notes:     "Received — batch {$batchNumber}. Awaiting label print.",
                );
            }

            // Mark the stock row as individually tracked so the board registry and
            // every tracking_mode-aware query treat this material as boards.
            // The caller (checkIn / batchCheckIn) has already created the stock row
            // via adjustStock; the legacy registerBatch() path does this inline.
            // Without it, a board material received through the main check-in path
            // keeps the default 'count' mode and disappears from /boards/stock-registry.
            $stock = Stock::where('material_id', $material->id)->first();
            if ($stock && $stock->tracking_mode !== Stock::TRACK_BY_AREA) {
                $stock->update(['tracking_mode' => Stock::TRACK_BY_AREA]);
            }

            return $boards;
        });
    }

    /**
     * Register N boards from a physical delivery.
     *
     * Called by: the legacy /boards/ingest endpoint and direct service calls.
     * Manages stock AND creates board records in one transaction.
     * Do NOT call this from checkIn() — use createBoardRecords() instead.
     *
     * @param  LibraryMaterial|int  $material
     * @param  int                  $quantity
     * @param  string               $batchNumber
     * @param  int|null             $length     mm — falls back to material attrs then config
     * @param  int|null             $width      mm
     * @param  int|null             $thickness  mm
     * @param  int|null             $userId
     * @return Board[]
     *
     * @throws \InvalidArgumentException  if material is not board-eligible
     */
    public function registerBatch(
        LibraryMaterial|int $material,
        int    $quantity,
        string $batchNumber,
        ?int   $length    = null,
        ?int   $width     = null,
        ?int   $thickness = null,
        ?int   $userId    = null,
        ?float $unitValue = null,
    ): array {
        if (is_int($material)) {
            $material = LibraryMaterial::with(['workstation', 'materialCategory.parent'])->findOrFail($material);
        } else {
            $material->loadMissing(['workstation', 'materialCategory.parent']);
        }

        $this->validateMaterial($material);

        // MaterialController wraps attributes as { "attributes": { ... } } on save.
        // Unwrap one level so dimension keys are directly accessible.
        $raw       = is_array($material->attributes) ? $material->attributes : [];
        $attrs     = $raw['attributes'] ?? $raw;
        $length    = $length    ?? ($attrs['standard_length_mm'] ?? config('boards.default_dimensions.length', 2440));
        $width     = $width     ?? ($attrs['standard_width_mm']  ?? config('boards.default_dimensions.width',  1220));
        $thickness = $thickness ?? ($attrs['thickness_mm']       ?? config('boards.default_dimensions.thickness', 18));
        $boardValue = $this->receiptValue($material, $unitValue);

        return DB::transaction(function () use ($material, $quantity, $batchNumber, $length, $width, $thickness, $userId, $boardValue) {
            $boards = [];

            for ($i = 0; $i < $quantity; $i++) {
                $boards[] = $this->registerBoard(
                    material:   $material,
                    batch:      $batchNumber,
                    status:     'Quarantine',
                    length:     $length,
                    width:      $width,
                    thickness:  $thickness,
                    value:      $boardValue,
                    userId:     $userId,
                    notes:      "Received — batch {$batchNumber}. Awaiting label print.",
                );
            }

            $stock = Stock::firstOrCreate(
                ['material_id' => $material->id],
                ['quantity_on_hand' => 0, 'warehouse_code' => 'MAIN', 'tracking_mode' => Stock::TRACK_BY_AREA]
            );

            $stock->increment('quantity_on_hand', $quantity);

            if ($stock->tracking_mode !== Stock::TRACK_BY_AREA) {
                $stock->update(['tracking_mode' => Stock::TRACK_BY_AREA]);
            }

            // One inventory log entry per batch — visible in the activity feed
            InventoryLog::create([
                'material_id'  => $material->id,
                'user_id'      => $userId,
                'type'         => 'check_in',
                'usage_type'   => 'reusable',
                'batch_number' => $batchNumber,
                'quantity'     => $quantity,
                'balance_after'=> $stock->fresh()->quantity_on_hand,
                'notes'        => "{$quantity} board(s) received. Codes: "
                    . implode(', ', array_map(fn($b) => $b->tracking_code, $boards)),
                'logged_at'    => now(),
            ]);

            return $boards;
        });
    }

    /**
     * Register an offcut board generated during production.
     *
     * Called by: BoardController::consume() when offcut dimensions are provided.
     * The offcut starts as Available immediately — it inherits the parent's label
     * and gets a new tracking code for the offcut portion.
     * The parent board is transitioned to Consumed.
     *
     * @throws \InvalidArgumentException  if parent board is not in WIP status
     */
    public function registerOffcut(
        Board $parentBoard,
        int   $length,
        int   $width,
        int   $thickness,
        ?int  $userId = null,
    ): Board {
        if (!$parentBoard->hasStatus('WIP')) {
            throw new \InvalidArgumentException(
                "Board [{$parentBoard->tracking_code}] must be WIP to generate an offcut. Current: [{$parentBoard->status}]."
            );
        }

        $parentBoard->loadMissing(['libraryMaterial.materialCategory', 'libraryMaterial.workstation']);

        $parentAreaM2  = ($parentBoard->length * $parentBoard->width) / 1_000_000;
        $offcutAreaM2  = ($length * $width) / 1_000_000;
        if ($offcutAreaM2 >= $parentAreaM2) {
            throw new \InvalidArgumentException('A remainder must be smaller than the original board. Return an untouched board through inspection instead.');
        }
        $minimumArea = (float) ($parentBoard->libraryMaterial->minimum_reusable_area_m2 ?? 0);
        $minimumLength = (float) ($parentBoard->libraryMaterial->minimum_reusable_length_mm ?? 0);
        $minimumWidth = (float) ($parentBoard->libraryMaterial->minimum_reusable_width_mm ?? 0);
        if (($minimumArea > 0 && $offcutAreaM2 < $minimumArea)
            || ($minimumLength > 0 && $length < $minimumLength)
            || ($minimumWidth > 0 && $width < $minimumWidth)) {
            throw new \InvalidArgumentException('The remainder is below this material’s minimum reusable dimensions and must be recorded as consumed or waste.');
        }
        $proportion    = $parentAreaM2 > 0 ? $offcutAreaM2 / $parentAreaM2 : 0;
        $offcutValue   = round($parentBoard->current_value * $proportion, 2);

        return DB::transaction(function () use ($parentBoard, $length, $width, $thickness, $offcutValue, $userId) {
            $offcut = $this->registerBoard(
                material:      $parentBoard->libraryMaterial,
                batch:         $parentBoard->batch_number . '-OFFCUT',
                status:        'Quarantine',  // becomes Available only after Stores physically racks it
                length:        $length,
                width:         $width,
                thickness:     $thickness,
                value:         $offcutValue,
                userId:        $userId,
                parentBoardId: $parentBoard->id,
                isOffcut:      true,
                labelPrinted:  true,          // inherits parent's label identity
                notes:         "Offcut from {$parentBoard->tracking_code}",
            );

            // The remainder is still physically with Production. Preserve its
            // project lineage until Stores scans it into the rack; do not count
            // it as Stores stock merely because Production declared it.
            $offcut->update([
                'assigned_job_ref' => $parentBoard->assigned_job_ref,
                'original_issue_log_id' => $parentBoard->original_issue_log_id,
                'project_id' => $parentBoard->project_id,
                'project_material_id' => $parentBoard->project_material_id,
            ]);

            // Consume the parent
            $parentBoard->transitionTo(
                'Consumed',
                $userId,
                "Consumed — offcut {$offcut->tracking_code} generated"
            );

            return $offcut;
        });
    }

    /**
     * What one received board is worth.
     *
     * The receipt price is the truth for a physical board: it is what this
     * delivery actually cost, not a blended catalogue average. Board issue and
     * every downstream project cost are gated on this being non-zero, so a
     * board received without a value is unissuable until someone repairs it.
     *
     * The catalogue fallback re-reads the material because check-in updates
     * `unit_cost` through its own model instance inside adjustStock. The caller
     * is holding an instance loaded before that write, so reading `unit_cost`
     * off it would use the pre-receipt figure — zero, for a first delivery.
     */
    /**
     * The value each board carries from this receipt.
     *
     * @throws \InvalidArgumentException  if neither the receipt nor the catalogue prices the board
     */
    private function receiptValue(LibraryMaterial $material, ?float $unitValue): float
    {
        if ($unitValue !== null && $unitValue > 0) {
            return $unitValue;
        }

        $catalogue = (float) ($material->newQuery()->whereKey($material->getKey())->value('unit_cost') ?? 0);

        // A board with no value cannot be issued: fulfil() rejects it rather than
        // post a zero-cost line to a project. Refusing the receipt here surfaces
        // that while the storekeeper still holds the delivery note, instead of
        // days later at the materials desk with the boards already on the rack.
        if ($catalogue <= 0) {
            throw new \InvalidArgumentException(
                "[{$material->material_name}] has no catalogue cost, so every board received would be unissuable. "
                . 'Record the receipt price per board.'
            );
        }

        return $catalogue;
    }

    // ─── Private core ─────────────────────────────────────────────────────────

    /**
     * The ONE place in the codebase that calls Board::create().
     *
     * All board registration flows (batch, offcut, future GRN) come through here.
     * Tracking code generation happens here and nowhere else.
     */
    private function registerBoard(
        LibraryMaterial $material,
        string  $batch,
        string  $status,
        int     $length,
        int     $width,
        int     $thickness,
        float   $value,
        ?int    $userId       = null,
        ?int    $parentBoardId = null,
        bool    $isOffcut     = false,
        bool    $labelPrinted = false,
        ?string $notes        = null,
    ): Board {
        $trackingCode = $this->generateTrackingCode($material);
        $areaM2       = round(($length * $width) / 1_000_000, 4);

        $board = Board::create([
            'tracking_code'       => $trackingCode,
            'library_material_id' => $material->id,
            'batch_number'        => $batch,
            'length'              => $length,
            'width'               => $width,
            'thickness'           => $thickness,
            'area_m2'             => $areaM2,
            'current_value'       => $value,
            'status'              => $status,
            'parent_board_id'     => $parentBoardId,
            'is_offcut'           => $isOffcut,
            'label_printed'       => $labelPrinted,
            'created_by'          => $userId,
        ]);

        BoardMovement::create([
            'board_id'     => $board->id,
            'from_status'  => null,
            'to_status'    => $status,
            'performed_by' => $userId,
            'notes'        => $notes,
        ]);

        return $board;
    }

    /**
     * Generate the next unique tracking code for a material.
     *
     * Format: WNG-{CAT_CODE}-{YEAR}-{SEQ:04d}
     * e.g.    WNG-MDF-2026-0042
     *
     * The category code comes from material_categories.code (seeded: MDF, PLY, HPL …).
     * Falls back to subcategory string → workstation code → 'BRD'.
     *
     * This method is private. Nothing outside this service generates tracking codes.
     */
    private function generateTrackingCode(LibraryMaterial $material): string
    {
        $fromCategory = strtoupper($material->materialCategory?->code ?? '');
        $fromSubcat   = strtoupper(preg_replace('/[^A-Z0-9]/i', '', substr($material->subcategory ?? '', 0, 4)));
        $fromWs       = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $material->workstation?->code ?? ''));
        $catCode      = $fromCategory ?: $fromSubcat ?: $fromWs ?: 'BRD';

        // tracking_code is char(30) and the rest of the format costs 14, so the
        // category segment has 16 to work with. Seeded category codes are short,
        // but the workstation fallback is user-entered and unbounded — without
        // this clamp a long workstation code makes board registration die on a
        // raw column-length error rather than producing a usable code.
        $catCode = substr($catCode, 0, 16) ?: 'BRD';

        $year   = now()->year;
        $prefix = "WNG-{$catCode}-{$year}-";

        // Use MAX on the numeric suffix instead of COUNT so that deleted boards
        // (which create gaps) never cause sequence re-use.
        // lockForUpdate() ensures concurrent transactions block here and each
        // gets a strictly increasing value.
        $last = DB::table('boards')
            ->where('tracking_code', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('tracking_code');

        $seq  = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    // ─── Validation ───────────────────────────────────────────────────────────

    /**
     * @throws \InvalidArgumentException
     */
    public function validateMaterial(LibraryMaterial $material): void
    {
        if (!$material->isBoardTrackable()) {
            throw new \InvalidArgumentException(
                "[{$material->material_name}] is not configured as a board/sheet material in the Material Library."
            );
        }
    }
}
