<?php

namespace App\Modules\Printing\Services;

use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\Printing\Models\PrintMaterialRequest;
use App\Modules\ProcurementStores\Services\StockMovementPoster;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrintMaterialRequestFulfilmentService
{
    public function __construct(
        private readonly StockMovementPoster $movements,
        private readonly PrintRollService $rolls,
    ) {}

    public function issue(PrintMaterialRequest $request, array $rolls, ?string $notes = null): PrintMaterialRequest
    {
        return DB::transaction(function () use ($request, $rolls, $notes) {
            $request = PrintMaterialRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (in_array($request->status, ['fulfilled', 'received', 'rejected', 'cancelled'], true)) {
                throw ValidationException::withMessages(['status' => ['This request is no longer open for fulfilment.']]);
            }

            $alreadyIssued = (float) $request->fulfilments()->sum('issued_quantity_m');
            $remaining = max(0, (float) $request->requested_quantity_m - $alreadyIssued);
            $issueQuantity = round(collect($rolls)->sum(fn ($roll) => (float) $roll['received_length_m']), 3);
            if ($issueQuantity <= 0) {
                throw ValidationException::withMessages(['rolls' => ['Enter at least one physical roll to issue.']]);
            }
            if ($issueQuantity > $remaining + 0.0005) {
                throw ValidationException::withMessages(['rolls' => ["Only {$remaining} m remains on this request."]]);
            }

            $material = $request->material()->with(['baseUom', 'issueUom', 'uomConversions'])->firstOrFail();
            $metre = UnitOfMeasure::query()->whereIn('code', ['m', 'metre', 'meter'])->first();
            if (! $metre || ((int) $material->base_uom_id !== (int) $metre->id && (int) $material->issue_uom_id !== (int) $metre->id)) {
                throw ValidationException::withMessages([
                    'material_id' => ['Configure metre as this material’s Stores or issue unit before issuing it to Printing.'],
                ]);
            }

            $movement = $this->movements->post('issue', [
                'material_id' => $material->id,
                'quantity' => $issueQuantity,
                'entered_uom_id' => $metre->id,
                'recipient_name' => 'Printing Department',
                'notes' => trim("Printing material request #{$request->id}".($notes ? ": {$notes}" : '')),
            ]);
            $log = $movement['log'];

            $request->fulfilments()->create([
                'inventory_log_id' => $log->id,
                'issued_quantity_m' => $issueQuantity,
                'issued_by' => auth()->id(),
                'issued_at' => now(),
                'notes' => $notes,
            ]);

            foreach ($rolls as $roll) {
                $this->rolls->createRoll($roll + [
                    'material_id' => $request->material_id,
                    'print_material_request_id' => $request->id,
                    'source_inventory_log_id' => $log->id,
                ]);
            }

            $newIssued = $alreadyIssued + $issueQuantity;
            $request->update([
                'status' => $newIssued >= (float) $request->requested_quantity_m - 0.0005
                    ? 'fulfilled'
                    : 'partially_fulfilled',
                'stores_inventory_log_id' => $log->id,
                'received_by' => auth()->id(),
            ]);

            return $request->fresh();
        });
    }

    public function markAwaitingPurchase(PrintMaterialRequest $request): PrintMaterialRequest
    {
        return DB::transaction(function () use ($request) {
            $request = PrintMaterialRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (in_array($request->status, ['fulfilled', 'received', 'rejected', 'cancelled'], true)) {
                throw ValidationException::withMessages(['status' => ['This request is no longer open.']]);
            }
            $issued = (float) $request->fulfilments()->sum('issued_quantity_m');
            $request->update([
                'status' => $issued > 0 ? 'partially_fulfilled' : 'awaiting_purchase',
                'purchase_requested_at' => now(),
            ]);

            return $request->fresh();
        });
    }
}
