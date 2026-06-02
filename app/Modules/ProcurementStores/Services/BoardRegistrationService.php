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
    ): array {
        $material->loadMissing(['workstation', 'materialCategory.parent']);

        // MaterialController wraps attributes as { "attributes": { ... } } on save.
        // Unwrap one level so dimension keys are directly accessible.
        $raw       = is_array($material->attributes) ? $material->attributes : [];
        $attrs     = $raw['attributes'] ?? $raw;
        $length    = $length    ?? ($attrs['standard_length_mm'] ?? config('boards.default_dimensions.length', 2440));
        $width     = $width     ?? ($attrs['standard_width_mm']  ?? config('boards.default_dimensions.width',  1220));
        $thickness = $thickness ?? ($attrs['thickness_mm']       ?? config('boards.default_dimensions.thickness', 18));

        return DB::transaction(function () use ($material, $quantity, $batchNumber, $length, $width, $thickness, $userId) {
            $boards = [];
            for ($i = 0; $i < $quantity; $i++) {
                $boards[] = $this->registerBoard(
                    material:  $material,
                    batch:     $batchNumber,
                    status:    'Quarantine',
                    length:    $length,
                    width:     $width,
                    thickness: $thickness,
                    value:     (float) $material->unit_cost,
                    userId:    $userId,
                    notes:     "Received — batch {$batchNumber}. Awaiting label print.",
                );
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

        return DB::transaction(function () use ($material, $quantity, $batchNumber, $length, $width, $thickness, $userId) {
            $boards = [];

            for ($i = 0; $i < $quantity; $i++) {
                $boards[] = $this->registerBoard(
                    material:   $material,
                    batch:      $batchNumber,
                    status:     'Quarantine',
                    length:     $length,
                    width:      $width,
                    thickness:  $thickness,
                    value:      (float) $material->unit_cost,
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
        $proportion    = $parentAreaM2 > 0 ? $offcutAreaM2 / $parentAreaM2 : 0;
        $offcutValue   = round($parentBoard->current_value * $proportion, 2);

        return DB::transaction(function () use ($parentBoard, $length, $width, $thickness, $offcutValue, $userId) {
            $offcut = $this->registerBoard(
                material:      $parentBoard->libraryMaterial,
                batch:         $parentBoard->batch_number . '-OFFCUT',
                status:        'Available',   // offcuts are immediately usable
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

            // Consume the parent
            $parentBoard->transitionTo(
                'Consumed',
                $userId,
                "Consumed — offcut {$offcut->tracking_code} generated"
            );

            // The offcut goes back into Available inventory — increment stock so
            // quantity_on_hand stays in sync with COUNT(*) boards WHERE status = Available.
            $stock = Stock::where('material_id', $parentBoard->library_material_id)->first();
            if ($stock) {
                $stock->increment('quantity_on_hand', 1);
            }

            InventoryLog::create([
                'material_id'  => $parentBoard->library_material_id,
                'user_id'      => $userId ?? \Illuminate\Support\Facades\Auth::id(),
                'type'         => 'return',
                'usage_type'   => 'reusable',
                'batch_number' => $parentBoard->batch_number . '-OFFCUT',
                'quantity'     => 1,
                'balance_after'=> $stock?->fresh()->quantity_on_hand ?? 0,
                'notes'        => "Offcut {$offcut->tracking_code} returned to Available from parent {$parentBoard->tracking_code}",
                'logged_at'    => now(),
            ]);

            return $offcut;
        });
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
        $fromWs       = strtoupper($material->workstation?->code ?? '');
        $catCode      = $fromCategory ?: $fromSubcat ?: $fromWs ?: 'BRD';

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
        if ($material->material_type !== 'reusable') {
            throw new \InvalidArgumentException(
                "Only reusable materials can be board-tracked. [{$material->material_name}] is [{$material->material_type}]."
            );
        }

        $eligibleParents = config('boards.tracking_categories', ['Boards', 'Sheet Materials', 'Veneer']);

        $parentName = $material->materialCategory?->parent?->name
            ?? $material->materialCategory?->name
            ?? $material->category
            ?? '';

        if (!in_array($parentName, $eligibleParents, true)) {
            throw new \InvalidArgumentException(
                "Material [{$material->material_name}] (category: [{$parentName}]) is not board-eligible. "
                . 'Eligible: ' . implode(', ', $eligibleParents) . '.'
            );
        }
    }
}
