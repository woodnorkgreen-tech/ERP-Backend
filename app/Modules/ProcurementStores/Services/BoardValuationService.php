<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\BoardMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BoardValuationService — the SINGLE writer for a board's receipt valuation
 * after registration.
 *
 * A board's `current_value` is the money one physical sheet carries. It is set
 * once, at receipt, by BoardRegistrationService, and from then on it only ever
 * moves downwards: an offcut takes a proportional share of its parent, and a
 * quarantined return is written down to the accepted recoverable value.
 *
 * That leaves one hole, and this class fills it. Boards received before the
 * receipt-price gate existed carry a zero value, and `fulfil()` refuses to
 * issue them — correctly, because issuing one posts a zero-cost line to a
 * project. Until now the only repair was a shell command, so the message the
 * storekeeper saw ("record their receipt valuation before issue") named an
 * action the application did not offer.
 *
 * Scope is deliberately narrow: only a board that has NOT yet been issued.
 *   - Quarantine / Available  → priced here. Nothing has posted yet.
 *   - Allocated / At Station / WIP → its issue already posted at whatever value
 *     it then carried. Repricing the board would not restate that posting;
 *     ProcurementStoresController::resolveFinanceValuation() owns that repair.
 *   - Consumed / Scrapped → cost is history. Repricing restates closed work.
 *
 * Every write leaves a BoardMovement, so the valuation is as auditable as a
 * status change.
 */
class BoardValuationService
{
    /**
     * The only statuses at which a receipt valuation can still be recorded —
     * a board that has left Stores has already priced whatever it posted.
     */
    public const VALUABLE_STATUSES = ['Quarantine', 'Available'];

    /**
     * Boards still waiting on a receipt valuation.
     *
     * `current_value` is a non-null decimal defaulting to 0, but the null check
     * stays: it costs nothing and covers rows written before the default.
     */
    public function unvaluedQuery(): Builder
    {
        return Board::query()
            ->where(fn ($q) => $q->whereNull('current_value')->orWhere('current_value', '<=', 0))
            ->whereIn('status', self::VALUABLE_STATUSES);
    }

    /**
     * Record the receipt valuation for a set of boards of ONE material.
     *
     * @param  Collection<int,Board>|array<int,Board>  $boards
     * @param  float   $sheetValue  what one full sheet of this material cost on receipt
     * @param  string  $reason      what the figure is based on — delivery note, invoice, quote
     *
     * @return array{priced: Collection<int,Board>, skipped: Collection<int,Board>, catalogue_updated: bool}
     *
     * @throws \InvalidArgumentException
     */
    public function record(Collection|array $boards, float $sheetValue, ?int $userId, string $reason): array
    {
        $boards = $boards instanceof Collection ? $boards : collect($boards);

        if ($boards->isEmpty()) {
            throw new \InvalidArgumentException('Select at least one board to value.');
        }

        if ($sheetValue <= 0) {
            throw new \InvalidArgumentException('A receipt value must be greater than zero — a zero-value board is exactly what this repairs.');
        }

        // One price per call. The figure is a per-sheet receipt price for a
        // specific material; spreading it across two materials would silently
        // assert that an MDF sheet and a plywood sheet cost the same.
        $materialIds = $boards->pluck('library_material_id')->unique();
        if ($materialIds->count() !== 1) {
            throw new \InvalidArgumentException('Value one material at a time — the receipt price belongs to a single material.');
        }

        // A price is evidence about one delivery, not about every unpriced
        // sheet of this material.  Keep this invariant here as well as in the
        // controller so every future caller is protected.
        $receiptBatches = $boards->map(fn (Board $board) => $board->batch_number ?? '__NO_BATCH__')->unique();
        if ($receiptBatches->count() !== 1) {
            throw new \InvalidArgumentException('Value one receipt batch at a time — different deliveries may have different prices.');
        }

        $material = LibraryMaterial::findOrFail($materialIds->first());
        $fullSheetArea = $this->fullSheetAreaM2($material);

        return DB::transaction(function () use ($boards, $sheetValue, $userId, $reason, $material, $fullSheetArea) {
            $priced = collect();
            $skipped = collect();

            foreach ($boards as $board) {
                // Re-read under a lock: two storekeepers can open the same
                // unvalued list, and the second must not overwrite the first's
                // figure with their own.
                $locked = Board::whereKey($board->getKey())->lockForUpdate()->first();

                if (! $locked
                    || (float) $locked->current_value > 0
                    || ! in_array($locked->status, self::VALUABLE_STATUSES, true)) {
                    $skipped->push($locked ?? $board);
                    continue;
                }

                $value = $this->boardValue($locked, $sheetValue, $fullSheetArea);

                $locked->update(['current_value' => $value]);

                BoardMovement::create([
                    'board_id'     => $locked->id,
                    'from_status'  => $locked->status,
                    'to_status'    => $locked->status,
                    'performed_by' => $userId,
                    'job_ref'      => $locked->assigned_job_ref,
                    'notes'        => 'Receipt valuation recorded — ' . number_format($value, 2)
                        . ($locked->is_offcut ? ' (offcut share of ' . number_format($sheetValue, 2) . ' per sheet)' : '')
                        . ". {$reason}",
                ]);

                $priced->push($locked->fresh());
            }

            // A material with no catalogue cost blocks its own next delivery —
            // check-in refuses a board receipt it cannot price. Seed it from the
            // figure just confirmed, but never overwrite a price that exists:
            // that number is a weighted average of real receipts and this one
            // is a repair, not a new delivery.
            $catalogueUpdated = false;
            if ($priced->isNotEmpty() && (float) $material->unit_cost <= 0) {
                LibraryMaterial::whereKey($material->id)->update(['unit_cost' => $sheetValue]);
                $catalogueUpdated = true;
            }

            return ['priced' => $priced, 'skipped' => $skipped, 'catalogue_updated' => $catalogueUpdated];
        });
    }

    /**
     * What one board is worth at this receipt price.
     *
     * A full sheet is worth the sheet price. An offcut is worth its share of
     * one — pricing a half sheet as a whole one inflates stock value and
     * overcharges the project it is later issued to. The 0.01 floor keeps a
     * very small remainder issuable: rounding it to zero would recreate the
     * very condition this service exists to clear.
     */
    private function boardValue(Board $board, float $sheetValue, float $fullSheetArea): float
    {
        if (! $board->is_offcut || $fullSheetArea <= 0 || (float) $board->area_m2 <= 0) {
            return round($sheetValue, 2);
        }

        // A remainder cannot be worth more than the sheet it came off. Clamping
        // guards against a mis-measured offcut or a material whose standard
        // dimensions were edited smaller after the board was cut.
        $proportion = min((float) $board->area_m2 / $fullSheetArea, 1.0);

        return max(round($sheetValue * $proportion, 2), 0.01);
    }

    /**
     * The area of one full sheet of this material, in m².
     *
     * Read the same way registration reads it: MaterialController saves
     * attributes wrapped as { "attributes": { ... } }, so unwrap one level
     * before looking for the standard dimensions.
     */
    private function fullSheetAreaM2(LibraryMaterial $material): float
    {
        $raw = is_array($material->attributes) ? $material->attributes : [];
        $attrs = $raw['attributes'] ?? $raw;

        $length = (float) ($attrs['standard_length_mm'] ?? config('boards.default_dimensions.length', 2440));
        $width = (float) ($attrs['standard_width_mm'] ?? config('boards.default_dimensions.width', 1220));

        return round(($length * $width) / 1_000_000, 4);
    }
}
