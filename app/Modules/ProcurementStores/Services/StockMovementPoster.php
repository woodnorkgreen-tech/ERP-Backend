<?php

namespace App\Modules\ProcurementStores\Services;

use App\Models\ElementMaterial;
use App\Models\Project;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\InventoryLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place a stock movement is posted.
 *
 * There used to be six endpoints that moved stock — check-in, check-out,
 * returns, defective, and a batch version of the first two — and the batch pair
 * were not the same code as the single pair. They were a reduced copy: no lot,
 * no expiry, no serial numbers, no GRN reconciliation. So receiving two pallets
 * of the same material enforced rules that receiving twenty did not, and the
 * screen had to warn people to use the slower form for anything tracked.
 *
 * Everything now goes through this class, one line at a time. A movement of one
 * line and a movement of forty differ only in how many times `post()` is called
 * and whether they share a batch number — never in what is checked.
 *
 * Lines are plain arrays rather than a Request, because a request carries one
 * movement and this has to serve many from the same payload. Callers validate
 * shape; this owns the rules that depend on the material.
 */
class StockMovementPoster
{
    /** The movement names the API speaks. */
    public const TYPES = ['receive', 'issue', 'return', 'damage'];

    /**
     * The ledger's own type strings, which are older than the API's and are
     * written into inventory_logs. Kept as a map rather than renamed: the
     * column has years of history in it and reports read those values.
     */
    private const LEDGER_TYPE = [
        'receive' => 'check_in',
        'issue'   => 'check_out',
        'return'  => 'return',
        'damage'  => 'defective',
    ];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly BoardRegistrationService $boards,
    ) {
    }

    /** A batch number every line of one posting shares, so they read as one act. */
    public function newBatchNumber(): string
    {
        return $this->inventory->generateBatchNumber();
    }

    /**
     * Post one line.
     *
     * @param  array<string, mixed>  $line
     * @return array{log: InventoryLog, boards: array}
     */
    public function post(string $type, array $line): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => "Unknown movement type '{$type}'."]);
        }

        return match ($type) {
            'receive' => $this->postReceipt($line),
            'issue'   => $this->postIssue($line),
            'return'  => $this->postReturn($line),
            'damage'  => $this->postDamage($line),
        };
    }

    /* ── Receiving ──────────────────────────────────────────────────────── */

    private function postReceipt(array $line): array
    {
        $material = LibraryMaterial::with(['materialCategory.parent', 'workstation'])
            ->findOrFail($line['material_id']);

        if (($material->item_status ?? 'Active') !== 'Active') {
            $this->reject('material_id', "Only Active Material Library items can be received. '{$material->material_name}' is {$material->item_status}.");
        }
        if ($material->is_batch_controlled && ! filled($line['lot_number'] ?? null)) {
            $this->reject('lot_number', "A supplier or internal lot number is required for '{$material->material_name}'.");
        }
        if ($material->is_expiry_controlled && ! filled($line['expiry_date'] ?? null)) {
            $this->reject('expiry_date', "An expiry date is required for '{$material->material_name}'.");
        }

        $line = $this->normaliseControlledMovement($line, $material, 'check_in');

        $quantity = (float) $line['quantity'];
        if ($material->isBoardTrackable() && $quantity !== (float) (int) $quantity) {
            $this->reject('quantity', "Board sheets for '{$material->material_name}' must be received as a whole number, because each sheet gets its own tracking code.");
        }

        // An unpriced board is an unissuable board. Caught here, while the
        // delivery note is still in hand, rather than at the materials desk.
        if ($material->isBoardTrackable()
            && ! filled($line['receipt_unit_cost'] ?? null)
            && (float) $material->unit_cost <= 0
            && (float) ($material->default_unit_cost ?? 0) <= 0) {
            $this->reject('receipt_unit_cost', "'{$material->material_name}' has no price yet. Enter the receipt price per board, or set a default price on the material in the Material Catalogue — boards received without a value cannot be issued to a project.");
        }

        $log = null;
        $boards = [];

        // adjustStock and createBoardRecords go together or not at all: a board
        // failure must not leave quantity_on_hand raised with no board records
        // behind it.
        DB::transaction(function () use ($line, $material, &$log, &$boards) {
            $meta = $line;
            $grnItem = $this->lockGrnLine($line, $material, $meta);

            $log = $this->inventory->adjustStock(
                (int) $line['material_id'],
                (float) $line['quantity'],
                'check_in',
                $meta,
            );

            if ($grnItem) {
                $this->assertGrnQuantityMatches($grnItem, $log);
            }

            if ($material->isBoardTrackable()) {
                $boards = $this->boards->createBoardRecords(
                    material:    $material,
                    quantity:    (int) $line['quantity'],
                    batchNumber: $log->batch_number,
                    length:      $line['length']    ?? null,
                    width:       $line['width']     ?? null,
                    thickness:   $line['thickness'] ?? null,
                    userId:      auth()->id(),
                    // What this delivery actually cost per board. Without it the
                    // boards inherit a catalogue average that is still zero on a
                    // first receipt, and every one of them is unissuable.
                    unitValue:   filled($line['receipt_unit_cost'] ?? null) ? (float) $line['receipt_unit_cost'] : null,
                );
                $log->update(['usage_type' => 'reusable']);
            }

            if ($grnItem) {
                // Checking a GRN line into stock is a store confirmation too —
                // see GoodsReceiptNoteController::store().
                $grnItem->update([
                    'entered_uom_id'    => $line['entered_uom_id'] ?? $material->base_uom_id,
                    'stock_quantity'    => abs((float) $log->quantity),
                    'receipt_unit_cost' => $line['receipt_unit_cost'] ?? null,
                    'stock_status'      => 'posted',
                    'inventory_log_id'  => $log->id,
                    'unit_price'        => $line['receipt_unit_cost'] ?? null,
                    'store_status'      => 'confirmed',
                    'confirmed_by'      => auth()->id(),
                    'confirmed_at'      => now(),
                ]);
            }
        });

        return ['log' => $log, 'boards' => $boards];
    }

    /**
     * Claim the delivery line this receipt completes, if it names one.
     *
     * Writes the reference and the expected unit into $meta by reference, so the
     * receipt is described by the delivery it came from rather than by whatever
     * the person retyped.
     */
    private function lockGrnLine(array $line, LibraryMaterial $material, array &$meta): ?GoodsReceiptNoteItem
    {
        if (! filled($line['grn_item_id'] ?? null)) {
            return null;
        }

        $grnItem = GoodsReceiptNoteItem::with(['goodsReceiptNote', 'purchaseOrderItem', 'inspection'])
            ->lockForUpdate()
            ->findOrFail((int) $line['grn_item_id']);

        if ((int) $grnItem->material_id !== (int) $line['material_id'] || ! $grnItem->accepted) {
            $this->reject('grn_item_id', 'This GRN line does not match the selected accepted material.');
        }
        if ($grnItem->inventory_log_id || $grnItem->stock_status === 'posted') {
            $this->reject('grn_item_id', 'This GRN line has already been added to Stores stock.');
        }

        // The PO line is an immutable buying-unit snapshot. An older approved
        // receipt must not change meaning when the catalogue's current buying
        // unit is edited later.
        $expectedUomId = (int) ($grnItem->purchaseOrderItem?->uom_id
            ?: $material->purchase_uom_id
            ?: $material->base_uom_id);
        if ((int) ($line['entered_uom_id'] ?? $material->base_uom_id) !== $expectedUomId) {
            $this->reject('entered_uom_id', 'Complete this GRN line in the buying unit recorded on the purchase order.');
        }

        $meta['expected_entered_uom_id'] = $expectedUomId;
        $meta['reference_no'] = $grnItem->goodsReceiptNote?->grn_number;
        $meta['notes'] = trim((filled($line['notes'] ?? null) ? $line['notes'].' · ' : '')
            ."Completed from GRN {$meta['reference_no']}");

        return $grnItem;
    }

    /** Rejected and quarantined quantities must never reach available stock. */
    private function assertGrnQuantityMatches(GoodsReceiptNoteItem $grnItem, InventoryLog $log): void
    {
        $factor = (float) ($log->uom_conversion_factor ?: 1);
        $approved = $grnItem->inspection
            ? (float) $grnItem->inspection->accepted_quantity
            : (float) $grnItem->received_quantity;

        if (abs(abs((float) $log->quantity) - $approved * $factor) > 0.00001) {
            $this->reject('quantity', "Receive the full quantity approved for Stores ({$approved}); rejected or quarantined quantities must not enter available stock.");
        }
    }

    /* ── Issuing ────────────────────────────────────────────────────────── */

    private function postIssue(array $line): array
    {
        $material = LibraryMaterial::with(['materialCategory.parent', 'uomConversions'])
            ->findOrFail($line['material_id']);

        $this->refuseBoard($material, 'Issue it through a Board Request, so individual boards are assigned to the job.');
        $line = $this->normaliseControlledMovement($line, $material, 'check_out');

        if (filled($line['project_material_id'] ?? null)) {
            $this->assertProjectLineCanTake(
                $line,
                $material,
                $this->inBaseUnit($material, (float) $line['quantity'], $line['entered_uom_id'] ?? null),
            );
        }

        // Sufficiency is checked inside adjustStock's row lock. Testing it here
        // with an unlocked read only produced a second, racier answer.
        $log = $this->inventory->adjustStock(
            (int) $line['material_id'],
            -(float) $line['quantity'],
            'check_out',
            $line,
        );

        return ['log' => $log, 'boards' => []];
    }

    /**
     * A project line may only take what it was approved for, in the unit it was
     * approved in, and only for the project it belongs to.
     *
     * This is the batch path's version of the rule, which was materially
     * stricter than the single-issue path's and is now what both use:
     *
     *  - the planned line is locked, so two people issuing the same requirement
     *    at once cannot each read the same remaining quantity;
     *  - the quantity is compared in the STOCK unit. The single path compared
     *    the entered quantity against a base-unit requirement, so issuing in
     *    boxes against a requirement counted in metres passed a check it should
     *    have failed;
     *  - issues made before project_material_id existed are allocated FIFO
     *    across repeated approved lines for the same material, so an old
     *    unlinked issue is charged once rather than against every line.
     */
    private function assertProjectLineCanTake(array $line, LibraryMaterial $material, float $quantityInBase): void
    {
        if (! filled($line['project_id'] ?? null)) {
            $this->reject('project_id', 'A project is required for approved material issues.');
        }

        $project = Project::findOrFail($line['project_id']);
        $planned = ElementMaterial::with('element.taskMaterialsData.task')
            ->lockForUpdate()
            ->findOrFail($line['project_material_id']);
        $materialsData = $planned->element?->taskMaterialsData;

        // Ownership and catalogue identity first, so a line belonging to
        // another project is never reported as an approval problem.
        if ((int) $materialsData?->task?->project_enquiry_id !== (int) $project->enquiry_id) {
            $this->reject('project_material_id', "{$planned->description} does not belong to this project.");
        }
        if ((int) $planned->library_material_id !== (int) $material->id) {
            $this->reject('project_material_id', "{$planned->description} is linked to a different Material Library item.");
        }

        $this->assertMaterialsApproved($materialsData);

        $netIssued = (float) InventoryLog::where('project_material_id', $planned->id)
                ->whereIn('type', ['check_out', 'issue', 'consumption'])->sum(DB::raw('ABS(quantity)'))
            - (float) InventoryLog::where('project_material_id', $planned->id)
                ->fulfilmentReopeningReturns()->sum('quantity');

        $netIssued += $this->legacyIssuedAgainst($planned, $project, $materialsData);

        $remaining = max(0, (float) $planned->quantity - $netIssued);
        if ($quantityInBase > $remaining + 0.00001) {
            $this->reject('quantity', "{$planned->description} has only {$remaining} remaining on the approved requirement.");
        }
    }

    /**
     * The share of this project's pre-linkage issues that belongs to this line.
     *
     * Issues posted before a line carried project_material_id name only the
     * project and the material. Where a project approved the same material on
     * several lines, charging that history to every one of them would show a
     * requirement as met many times over; charging it to none would let the
     * project draw the same stock twice. It is allocated FIFO instead: earlier
     * lines absorb it first, and this line takes what is left, capped at its own
     * approved quantity.
     */
    private function legacyIssuedAgainst(ElementMaterial $planned, Project $project, mixed $materialsData): float
    {
        $legacyIssued = (float) InventoryLog::where('project_id', $project->id)
                ->where('material_id', $planned->library_material_id)
                ->whereNull('project_material_id')
                ->whereIn('type', ['check_out', 'issue', 'consumption'])
                ->sum(DB::raw('ABS(quantity)'))
            - (float) InventoryLog::where('project_id', $project->id)
                ->where('material_id', $planned->library_material_id)
                ->whereNull('project_material_id')
                ->fulfilmentReopeningReturns()->sum('quantity');

        $earlierRequirement = (float) ElementMaterial::query()
            ->where('library_material_id', $planned->library_material_id)
            ->where('is_included', true)
            ->where('id', '<', $planned->id)
            ->whereHas('element', fn ($query) => $query->where('task_materials_data_id', $materialsData->id))
            ->sum('quantity');

        return min((float) $planned->quantity, max(0, $legacyIssued - $earlierRequirement));
    }

    /* ── Returning ──────────────────────────────────────────────────────── */

    private function postReturn(array $line): array
    {
        $material = LibraryMaterial::with(['materialCategory.parent', 'uomConversions'])
            ->findOrFail($line['material_id']);

        $this->refuseBoard($material, 'Return individual boards through the board lifecycle, with status Available.');
        $line = $this->normaliseControlledMovement($line, $material, 'return');

        $returnQuantityBase = $this->inBaseUnit($material, (float) $line['quantity'], $line['entered_uom_id'] ?? null);

        $log = DB::transaction(function () use ($line, $returnQuantityBase) {
            $issue = InventoryLog::query()->lockForUpdate()->find((int) $line['original_issue_log_id']);
            if (! $issue) {
                $this->reject('original_issue_log_id', 'This issue is no longer available. Refresh the project custody list and select the current issue.');
            }
            if (! in_array($issue->type, ['check_out', 'issue', 'consumption'], true)) {
                $this->reject('original_issue_log_id', 'Select an original stock issue.');
            }
            if ((int) $issue->material_id !== (int) $line['material_id']) {
                $this->reject('material_id', 'The returned material must match the original issue.');
            }
            if ($issue->usage_type !== 'reusable') {
                $this->reject('original_issue_log_id', 'Consumable issues are final and cannot be returned to stock.');
            }

            $alreadyReturned = (float) InventoryLog::where('original_issue_log_id', $issue->id)
                ->where('type', 'return')->sum('quantity');
            if ($alreadyReturned + $returnQuantityBase > abs((float) $issue->quantity) + 0.00001) {
                $this->reject('quantity', 'Return quantity exceeds the unreturned quantity from the original issue.');
            }

            // Custody follows the original movement, never what the person
            // filling the form happened to retype.
            $meta = $line;
            $meta['project_id'] = $issue->project_id;
            $meta['project_material_id'] = $issue->project_material_id;
            $meta['reference_no'] = $issue->reference_no;

            return $this->inventory->adjustStock(
                (int) $line['material_id'],
                (float) $line['quantity'],
                'return',
                $meta,
            );
        });

        return ['log' => $log, 'boards' => []];
    }

    /* ── Writing off ────────────────────────────────────────────────────── */

    private function postDamage(array $line): array
    {
        $material = LibraryMaterial::with('materialCategory.parent')->findOrFail($line['material_id']);

        $this->refuseBoard($material, 'Scrap individual boards through the board lifecycle, with status Scrapped.');
        $line = $this->normaliseControlledMovement($line, $material, 'defective');

        // Sufficiency is checked inside adjustStock's row lock — see postIssue().
        $log = $this->inventory->adjustStock(
            (int) $line['material_id'],
            -(float) $line['quantity'],
            'defective',
            $line,
        );

        return ['log' => $log, 'boards' => []];
    }

    /* ── Shared rules ───────────────────────────────────────────────────── */

    /**
     * Serial and lot rules, applied identically to every movement type.
     *
     * Returns the line with the serial list cleaned, because the caller has to
     * post the same values that were counted here.
     */
    private function normaliseControlledMovement(array $line, LibraryMaterial $material, string $type): array
    {
        $quantity = (float) ($line['quantity'] ?? 0);

        if ($material->is_serialized) {
            if ($quantity !== (float) (int) $quantity) {
                $this->reject('quantity', "Serialized stock must move in whole units ('{$material->material_name}').");
            }
            $field = $type === 'check_in' ? 'serial_numbers' : 'serial_item_ids';
            $values = array_values(array_filter(
                (array) ($line[$field] ?? []),
                fn ($value) => $value !== null && $value !== '',
            ));
            if (count($values) !== (int) $quantity || count($values) !== count(array_unique($values))) {
                $this->reject($field, "Provide exactly {$quantity} unique serialized units for '{$material->material_name}'.");
            }
            $line[$field] = $values;
        }

        if ($type === 'return' && $material->is_batch_controlled && ! $material->is_serialized
            && ! filled($line['inventory_lot_id'] ?? null)) {
            $this->reject('inventory_lot_id', "Select the original lot for the return of '{$material->material_name}'.");
        }

        return $line;
    }

    /**
     * Boards are individually tracked records, not a quantity, so they never
     * move through the generic path — whichever screen the request came from.
     */
    private function refuseBoard(LibraryMaterial $material, string $instruction): void
    {
        if ($material->isBoardTrackable()) {
            $this->reject('material_id', "'{$material->material_name}' is a tracked board material. {$instruction}");
        }
    }

    /**
     * Convert an entered quantity into the material's stock unit.
     *
     * Only used for comparisons made before posting. adjustStock does its own
     * conversion, under the row lock, for the quantity it actually writes — and
     * it is the one that decides whether the unit is allowed at all.
     */
    private function inBaseUnit(LibraryMaterial $material, float $quantity, mixed $enteredUomId): float
    {
        if (! filled($enteredUomId) || (int) $enteredUomId === (int) $material->base_uom_id) {
            return $quantity;
        }

        $factor = (float) ($material->uomConversions->first(
            fn ($row) => (int) $row->from_uom_id === (int) $enteredUomId
                && (int) $row->to_uom_id === (int) $material->base_uom_id,
        )?->factor ?? 0);

        if ($factor <= 0) {
            $this->reject('entered_uom_id', "'{$material->material_name}' has no conversion from this unit to its stock unit. Finish its unit setup in the Material Catalogue first.");
        }

        return $quantity * $factor;
    }

    /** A project's materials must be approved before any of them can be issued. */
    private function assertMaterialsApproved(mixed $materialsData): void
    {
        if (! (bool) data_get($materialsData?->project_info, 'approval_status.all_approved', false)) {
            $this->reject(
                'project_material_id',
                'Project Officer and Production must sign off the material list before Stores can issue it.',
            );
        }
    }

    /**
     * @return never
     */
    private function reject(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
