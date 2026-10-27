<?php

namespace App\Modules\ProcurementStores\Services;

use App\Models\ElementMaterial;
use App\Models\Project;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Services\MaterialRegistrationService;
use App\Modules\MaterialsLibrary\Support\MaterialCompleteness;
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
        private readonly MaterialRegistrationService $registration,
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
    /** Decided here, never taken from the client: how an entered unit converts. */
    private const INTERNAL_LINE_KEYS = ['entered_conversion_factor', 'expected_entered_uom_id'];

    public function post(string $type, array $line): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => "Unknown movement type '{$type}'."]);
        }
        $line = array_diff_key($line, array_flip(self::INTERNAL_LINE_KEYS));

        try {
            return match ($type) {
                'receive' => $this->postReceipt($line),
                'issue'   => $this->postIssue($line),
                'return'  => $this->postReturn($line),
                'damage'  => $this->postDamage($line),
            };
        } catch (\InvalidArgumentException $exception) {
            // Report 76A-3: a movement whose accounting cannot be posted — no
            // approved account, a shut period — did not happen. The stock is
            // where it was; the person is told exactly what is missing.
            throw ValidationException::withMessages(['accounting' => $exception->getMessage()]);
        }
    }

    /* ── Receiving ──────────────────────────────────────────────────────── */

    private function postReceipt(array $line): array
    {
        $log = null;
        $boards = [];

        // Everything from resolving the material through posting the movement is
        // one transaction: a delivery of something that did not exist in the
        // catalogue a moment ago either posts in full — the new catalogue row
        // and its stock together — or neither exists. That is the same
        // all-or-nothing guarantee this class already gives a batch of lines;
        // registering the material inline is just one more thing that can fail.
        DB::transaction(function () use (&$line, &$log, &$boards) {
            $material = $this->resolveMaterial($line);
            $line['material_id'] = $material->id;

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
            $meta = $line;
            $grnItem = $this->lockGrnLine($line, $material, $meta);
            if (! $grnItem) {
                $line = $this->assertExceptionalReceipt($line, $material);
                $meta = array_merge($meta, ['movement_reason' => $line['receipt_reason']]);
            }
            if ($grnItem && (float) ($line['receipt_unit_cost'] ?? 0) <= 0) {
                $line['receipt_unit_cost'] = $grnItem->resolvedReceiptUnitCost();
                $meta['receipt_unit_cost'] = $line['receipt_unit_cost'];
            }

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

            $log = $this->inventory->adjustStock(
                (int) $line['material_id'],
                (string) $line['quantity'],
                'check_in',
                $meta,
            );

            if ($grnItem) {
                $this->assertGrnQuantityMatches($grnItem, $log);
            } else {
                // Stock with no purchase behind it reaches the ledger in the
                // same transaction it reaches the shelf, or not at all.
                try {
                    app(\App\Modules\Finance\Services\StockMovementPostingService::class)
                        ->postExceptionalReceipt($log->fresh('material'), auth()->id(), (string) $line['adjustment_unit_value']);
                } catch (\InvalidArgumentException $exception) {
                    $this->reject('receipt_reason', $exception->getMessage());
                }
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
                    'stock_quantity'    => $material->isConsumableUnit() ? ltrim($log->quantity, '-') : abs((float) $log->quantity),
                    'receipt_unit_cost' => $line['receipt_unit_cost'] ?? null,
                    'stock_status'      => 'posted',
                    'inventory_log_id'  => $log->id,
                    'unit_price'        => $line['receipt_unit_cost'] ?? null,
                    'store_status'      => 'confirmed',
                    'confirmed_by'      => auth()->id(),
                    'confirmed_at'      => now(),
                ]);

                // Without this, a note finished entirely through the receiving
                // queue never leaves 'pending_confirmation' — it sits in the
                // Stores confirmation queue and the Deliveries list forever,
                // with nothing left on it to actually confirm.
                $grnItem->goodsReceiptNote->closeOutIfFullyConfirmed();
            }
        });

        return ['log' => $log, 'boards' => $boards];
    }

    /**
     * Stock may enter Stores without a goods receipt note only for a reason
     * that is not a purchase (Report 76A Part 2, Decision C).
     *
     * Bought stock arrives through PO → GRN → Stores, which is what accrues it
     * and what the supplier's invoice is later matched against. A manual
     * receipt of bought stock skipped all of that: the shelf gained stock the
     * ledger never received and no invoice could be checked against it. So a
     * receipt with no GRN line must say why it is not a purchase — opening
     * balance, stock found, or a correction — carry a value and a written
     * explanation, and be made by someone who may approve stock adjustments
     * (`stores.review`, the same authority that approves a stock count).
     *
     * No GRN and no purchase order are ever created to make a receipt fit.
     */
    private function assertExceptionalReceipt(array $line, LibraryMaterial $material): array
    {
        $reason = $line['receipt_reason'] ?? null;

        if (! in_array($reason, \App\Modules\ProcurementStores\Models\InventoryLog::EXCEPTIONAL_RECEIPT_REASONS, true)) {
            $this->reject('receipt_reason', 'Purchased stock must be received through the approved GRN. '
                .'Stock that was not bought — an opening balance, stock found, or a correction — is recorded as a stock adjustment with its reason.');
        }
        if (! auth()->user()?->can(\App\Constants\Permissions::STORES_REVIEW)) {
            $this->reject('receipt_reason', 'Taking stock in without a purchase is a stock adjustment and needs a Stores reviewer (the person who approves stock counts).');
        }
        if (mb_strlen(trim((string) ($line['notes'] ?? ''))) < 10) {
            $this->reject('notes', 'Explain where this stock came from (at least a short sentence). It is the record behind the adjustment.');
        }

        $unitValue = (float) ($line['receipt_unit_cost'] ?? 0);
        if ($unitValue <= 0) {
            $unitValue = (float) $material->unit_cost > 0 ? (float) $material->unit_cost : (float) ($material->default_unit_cost ?? 0);
        }
        if ($unitValue <= 0) {
            $this->reject('receipt_unit_cost', "Enter what each unit of '{$material->material_name}' is worth. Stock cannot be taken in at no value.");
        }
        // The value the accounts take this stock in at. Kept apart from the
        // receipt price: a catalogue default is not money anyone paid, so it
        // must not enter the material's average cost as though it were.
        $line['adjustment_unit_value'] = $unitValue;

        return $line;
    }

    /**
     * Find the material this line names, or register it on the spot.
     *
     * A receive line either names an existing catalogue item or describes a
     * new one — StockMovementRequest requires exactly one. Registering here,
     * rather than sending the person to the Material Catalogue first, is the
     * whole point: someone receiving a delivery of something new should not
     * have to leave the receiving screen to name it.
     */
    private function resolveMaterial(array $line): LibraryMaterial
    {
        if (filled($line['material_id'] ?? null)) {
            return LibraryMaterial::with(['materialCategory.parent', 'workstation'])
                ->findOrFail($line['material_id']);
        }

        return $this->registerInlineMaterial((array) ($line['new_material'] ?? []));
    }

    /**
     * Register a catalogue row for a delivery nobody had named yet.
     *
     * Category is the only real decision required of the person receiving
     * stock — MaterialDefaultsService reads item type, issue disposition,
     * tracking mode and the stock unit from it, the same as a full
     * registration would. Everything below that is that same answer shown
     * back as an editable suggestion (see StockMovementRequest), so a typist
     * who already knows this delivery differs from its category default may
     * say so directly rather than fixing it up afterwards in the catalogue.
     * If the category still leaves something unresolved (a required
     * specification MaterialCompleteness would catch — rare, but boards need
     * a thickness), the whole receipt is refused rather than leaving a
     * stranded draft behind: this runs inside postReceipt's transaction, so
     * nothing is created either.
     */
    private function registerInlineMaterial(array $newMaterial): LibraryMaterial
    {
        $material = $this->registration->create([
            'material_name' => $newMaterial['material_name'] ?? null,
            'material_category_id' => $newMaterial['material_category_id'] ?? null,
            'material_code' => $newMaterial['material_code'] ?? null,
            'attributes' => $newMaterial['attributes'] ?? [],
            'issue_disposition' => $newMaterial['issue_disposition'] ?? null,
            'tracking_mode' => $newMaterial['tracking_mode'] ?? null,
            'is_serialized' => ($newMaterial['tracking_mode'] ?? '') === 'serialized_item',
            'base_uom_id' => $newMaterial['base_uom_id'] ?? null,
            'purchase_uom_id' => $newMaterial['purchase_uom_id'] ?? null,
            'issue_uom_id' => $newMaterial['issue_uom_id'] ?? null,
            'uom_conversions' => $newMaterial['uom_conversions'] ?? [],
            'default_unit_cost' => $newMaterial['default_unit_cost'] ?? null,
        ], (int) auth()->id(), [
            // Report 79C: receiving registers through the same duplicate gate.
            'acknowledged' => (array) ($newMaterial['duplicate_review']['acknowledged'] ?? []),
            'reason' => $newMaterial['duplicate_review']['reason'] ?? null,
            'field' => 'new_material.material_name',
        ]);

        if ($material->item_status !== 'Active') {
            $missing = implode(', ', array_values(MaterialCompleteness::missing($material)));
            $this->reject(
                'new_material.material_category_id',
                "\"{$material->materialCategory?->name}\" needs more detail before stock can be received against it — still missing: {$missing}. Register it fully from the Material Catalogue, or choose a simpler category.",
            );
        }

        return $material->loadMissing('workstation');
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

        $grnItem = GoodsReceiptNoteItem::with(['goodsReceiptNote', 'purchaseOrderItem.requisitionItem', 'purchaseOrderItem.material', 'inspection'])
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
        if (!$material->isConsumableUnit() && (int) ($line['entered_uom_id'] ?? $material->base_uom_id) !== $expectedUomId) {
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
        if ($log->material->isConsumableUnit()) {
            $approved = (string) ($grnItem->inspection?->accepted_quantity ?? $grnItem->received_quantity);
            $buyingId = $grnItem->purchaseOrderItem?->uom_id ?: $log->material->purchase_uom_id ?: $log->material->base_uom_id;
            $factor = (int) $buyingId === (int) $log->material->base_uom_id ? '1' : (string) ($log->material->uomConversions->firstWhere('from_uom_id', $buyingId)?->factor ?? '0');
            if (bccomp($log->quantity, bcmul($approved, $factor, 6), 6) !== 0) $this->reject('quantity', 'Actual roll quantities must equal the GRN accepted quantity in the stock UOM.');
            return;
        }
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

        $line = $this->classifyIssue($line);

        if (filled($line['project_material_id'] ?? null)) {
            // A job may ask in its own unit (4 pcs of timber at 2.4 m each):
            // that line's approved reading converts it, where the catalogue has none.
            $lineFactor = $this->lineReadingFactor($line, $material);
            if ($lineFactor !== null) {
                $line['entered_conversion_factor'] = $lineFactor;
            }
            $this->assertProjectLineCanTake(
                $line,
                $material,
                $material->isConsumableUnit() ? StoresDecimal::quantity($line['quantity'])
                    : ($lineFactor !== null ? (float) $line['quantity'] * (float) $lineFactor : $this->inBaseUnit($material, (float) $line['quantity'], $line['entered_uom_id'] ?? null)),
            );
        }

        // Sufficiency is checked inside adjustStock's row lock. Testing it here
        // with an unlocked read only produced a second, racier answer.
        $log = $this->inventory->adjustStock(
            (int) $line['material_id'],
            '-'.ltrim((string) $line['quantity'], '-'),
            'check_out',
            $line,
        );

        return ['log' => $log, 'boards' => []];
    }

    /**
     * Every issue is for a project or for the company — never for nobody
     * (Report 76A Part 2, Decision D).
     *
     * An issue with no project used to leave Stores with no cost anywhere:
     * not a project's, not a department's, not the ledger's. It is now either
     * a project issue (which becomes that project's cost) or company use,
     * which names the department it is for and why, is charged to the approved
     * operating-expense account, and is never a project cost. No project is
     * invented to make company use fit.
     */
    private function classifyIssue(array $line): array
    {
        if (filled($line['project_id'] ?? null)) {
            unset($line['movement_reason'], $line['cost_centre_id']);

            // Report 78C: stock goes only to a job that is being delivered.
            // A completed, closed or on-hold job needs an authorised manager's
            // recorded reason (D-4); a cancelled or not-yet-started job none.
            $override = app(ProjectMaterialWorkflowEligibility::class)->assertAllows(
                (int) Project::whereKey($line['project_id'])->value('enquiry_id') ?: null,
                ProjectMaterialWorkflowEligibility::CAP_ISSUE,
                $line['lifecycle_override_reason'] ?? null,
            );
            if ($override) {
                $line['notes'] = trim(($line['notes'] ?? '').' [Lifecycle override: '.$override['reason'].']');
            }
            unset($line['lifecycle_override_reason']);

            return $line;
        }

        if (($line['issue_purpose'] ?? null) !== \App\Modules\ProcurementStores\Models\InventoryLog::REASON_INTERNAL_CONSUMPTION) {
            $this->reject('project_id', 'Choose the project this stock is for, or record it as company use with the department it is for.');
        }
        if (! filled($line['cost_centre_id'] ?? null)) {
            $this->reject('cost_centre_id', 'Choose the department this stock is for.');
        }
        if (mb_strlen(trim((string) ($line['notes'] ?? ''))) < 5) {
            $this->reject('notes', 'Say what the stock is being used for.');
        }
        $line['movement_reason'] = \App\Modules\ProcurementStores\Models\InventoryLog::REASON_INTERNAL_CONSUMPTION;
        unset($line['project_material_id']);

        return $line;
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
     *  - the quantity is compared in the STOCK unit — both sides since Report
     *    78B, which reads the requirement through RequirementUnits. The single path compared
     *    the entered quantity against a base-unit requirement, so issuing in
     *    boxes against a requirement counted in metres passed a check it should
     *    have failed;
     *  - issues made before project_material_id existed are allocated FIFO
     *    across repeated approved lines for the same material, so an old
     *    unlinked issue is charged once rather than against every line.
     */
    private function assertProjectLineCanTake(array $line, LibraryMaterial $material, float|string $quantityInBase): void
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

        // Report 78B (F-03): the requirement in the material's STOCK unit. The
        // issue quantity is already in stock units; the BOM quantity is in the
        // BOM's unit (sqm, Packets…). Comparing the two raw numbers let 12 sheets
        // out against 12 sqm. A requirement whose unit cannot be read in stock
        // units is refused here rather than guessed at.
        $requiredBase = app(RequirementUnits::class)->requiredInBaseOrFail($planned, $material, 'quantity');

        if ($material->isConsumableUnit()) {
            $issued = (string) InventoryLog::where('project_material_id', $planned->id)->whereIn('type', ['check_out', 'issue', 'consumption'])->lockForUpdate()->sum(DB::raw('ABS(quantity)'));
            $returns = (string) InventoryLog::where('project_material_id', $planned->id)->fulfilmentReopeningReturns()->lockForUpdate()->sum('quantity');
            $remaining = bcsub(bcsub($requiredBase, bcsub($issued, $returns, 6), 6), number_format($this->retiredAgainst($planned, (float) $requiredBase), 6, '.', ''), 6);
            if (bccomp((string) $quantityInBase, $remaining, 6) > 0) $this->reject('quantity', "{$planned->description} has only {$remaining} remaining on the approved requirement.");
            return;
        }

        // Report 79E (P0-4): locking reads. The line lock above makes a second
        // issuer wait, but under REPEATABLE READ a plain SUM afterwards can still
        // read the snapshot from before the first issuer committed. It held only
        // because newBatchNumber() happened to serialise /movements first.
        $netIssued = (float) InventoryLog::where('project_material_id', $planned->id)
                ->whereIn('type', ['check_out', 'issue', 'consumption'])->lockForUpdate()->sum(DB::raw('ABS(quantity)'))
            - (float) InventoryLog::where('project_material_id', $planned->id)
                ->fulfilmentReopeningReturns()->lockForUpdate()->sum('quantity');

        $netIssued += $this->legacyIssuedAgainst($planned, $project, $materialsData, (float) $requiredBase);

        // Report 78C: quantity recorded as fulfilled outside the ERP is not
        // owed any more; revoking the retirement is how it becomes issuable.
        $retired = $this->retiredAgainst($planned, (float) $requiredBase);
        $remaining = max(0, (float) $requiredBase - $netIssued - $retired);
        if ($quantityInBase > $remaining + 0.00001) {
            $this->reject('quantity', "{$planned->description} has only {$remaining} remaining on the approved requirement"
                .($retired > 0 ? " ({$retired} was recorded as fulfilled outside the ERP)." : '.'));
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
    private function legacyIssuedAgainst(ElementMaterial $planned, Project $project, mixed $materialsData, float $requiredBase): float
    {
        $legacyIssued = (float) InventoryLog::where('project_id', $project->id)
                ->where('material_id', $planned->library_material_id)
                ->whereNull('project_material_id')
                ->whereIn('type', ['check_out', 'issue', 'consumption'])
                ->lockForUpdate()
                ->sum(DB::raw('ABS(quantity)'))
            - (float) InventoryLog::where('project_id', $project->id)
                ->where('material_id', $planned->library_material_id)
                ->whereNull('project_material_id')
                ->fulfilmentReopeningReturns()->lockForUpdate()->sum('quantity');

        if ($legacyIssued <= 0) {
            return 0.0;
        }

        // Earlier lines absorb legacy issues in stock units too (Report 78B).
        $units = app(RequirementUnits::class);
        $earlierRequirement = (float) ElementMaterial::query()
            ->with('libraryMaterial.uomConversions')
            ->where('library_material_id', $planned->library_material_id)
            ->where('is_included', true)
            ->where('id', '<', $planned->id)
            ->whereHas('element', fn ($query) => $query->where('task_materials_data_id', $materialsData->id))
            ->get()
            ->sum(fn (ElementMaterial $line) => (float) ($units->requiredInBase($line) ?? 0));

        return min($requiredBase, max(0, $legacyIssued - $earlierRequirement));
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
            LibraryMaterial::whereKey($line['material_id'])->lockForUpdate()->firstOrFail();
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
            if ($issue->usage_type !== 'reusable' && !$issue->consumable_unit_id) {
                $this->reject('original_issue_log_id', 'Consumable issues are final and cannot be returned to stock.');
            }

            // Locking read (Report 79E): two returns of the same issue must not
            // both read "nothing returned yet" from an old snapshot.
            $alreadyReturned = (float) InventoryLog::where('original_issue_log_id', $issue->id)
                ->where('type', 'return')->lockForUpdate()->sum('quantity');
            if (!$issue->consumable_unit_id && $alreadyReturned + $returnQuantityBase > abs((float) $issue->quantity) + 0.00001) {
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
                (string) $line['quantity'],
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
        // Damage and waste are different losses with different approved
        // accounts; neither is a project cost (Report 76A Part 2, Decision D).
        $line['movement_reason'] = ($line['write_off_reason'] ?? null) === \App\Modules\ProcurementStores\Models\InventoryLog::REASON_WASTE
            ? \App\Modules\ProcurementStores\Models\InventoryLog::REASON_WASTE
            : \App\Modules\ProcurementStores\Models\InventoryLog::REASON_DAMAGE;
        unset($line['project_id'], $line['project_material_id']);
        // Sufficiency is checked inside adjustStock's row lock — see postIssue().
        $log = $this->inventory->adjustStock(
            (int) $line['material_id'],
            '-'.ltrim((string) $line['quantity'], '-'),
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

    /**
     * The factor for a quantity entered in a project line's own BOM unit, when
     * the material itself has no conversion for that unit but the line has an
     * approved reading of it (RequirementUnits rule 4). Null otherwise.
     */
    private function lineReadingFactor(array $line, LibraryMaterial $material): ?string
    {
        $entered = (int) ($line['entered_uom_id'] ?? 0);
        if (! $entered || $entered === (int) $material->base_uom_id) {
            return null;
        }
        $catalogue = $material->uomConversions->first(fn ($row) => (int) $row->from_uom_id === $entered && (int) $row->to_uom_id === (int) $material->base_uom_id);
        if ($catalogue) {
            return null;
        }
        $planned = ElementMaterial::find($line['project_material_id']);
        if (! $planned || (int) $planned->requirement_uom_id !== $entered || $planned->requirement_base_factor === null
            || (int) $planned->requirement_base_uom_id !== (int) $material->base_uom_id) {
            return null;
        }

        return (string) $planned->requirement_base_factor;
    }

    /** Report 78C: the line's active legacy retirement, read in the requirement's stock unit. */
    private function retiredAgainst(ElementMaterial $planned, float $requiredBase): float
    {
        $retirements = app(MaterialRequirementRetirements::class);

        return $retirements->retiredIn($planned, $requiredBase, (float) ($retirements->retiredBomByLine([$planned->id])[$planned->id] ?? 0));
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
