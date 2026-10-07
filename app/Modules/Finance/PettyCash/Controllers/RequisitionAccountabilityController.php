<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Services\RequisitionAccountabilityService;
use App\Modules\Finance\PettyCash\Services\RequisitionClosureService;
use App\Modules\Finance\PettyCash\Services\RequisitionControlProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Report 75R-B: what happens to a requisition paid by receiver after payment —
 * receipt confirmation, surrender by receiver, reconciliation, release of an
 * unused balance, and closure.
 *
 * Each action re-derives everything it needs under the parent's lock in its
 * service. Every response carries the refreshed control projection, so the
 * screen never works a figure out for itself.
 */
class RequisitionAccountabilityController extends Controller
{
    public function __construct(
        private readonly RequisitionAccountabilityService $accountability,
        private readonly RequisitionClosureService $closure,
    ) {
    }

    public function confirmReceipt(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'receiver_key' => ['required', 'string', 'max:200'],
            'payment_ids' => ['nullable', 'array'], 'payment_ids.*' => ['integer', 'distinct'],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $confirmed = $this->accountability->confirmReceipt($id, $request->user(), $data);

        return $this->respond($id, count($confirmed) === 1 ? 'Receipt confirmed.' : count($confirmed).' payments confirmed as received.');
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'receiver_key' => ['required', 'string', 'max:200'],
            'idempotency_key' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array'],
            'items.*.requisition_item_id' => ['required', 'integer'],
            'items.*.expense_code_id' => ['required', 'integer', 'exists:expense_codes,id'],
            'items.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'lte:items.*.amount'],
            'items.*.receipt_type' => ['required', Rule::in(['etr', 'non_etr', 'none'])],
            'items.*.receipt_number' => ['nullable', 'string', 'max:100'],
            'items.*.supplier_kra_pin' => ['nullable', 'string', 'max:32'],
            'items.*.supplier_name' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.receipt_path' => ['nullable', 'string', 'max:500'],
            'items.*.duplicate_override_reason' => ['nullable', 'string', 'min:10', 'max:1000'],
            'returns' => ['nullable', 'array'],
            'returns.*.requisition_item_id' => ['required', 'integer'],
            'returns.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'returns.*.payment_id' => ['nullable', 'integer'],
            'returns.*.reference' => ['nullable', 'string', 'max:255'],
        ]);
        $surrender = $this->accountability->submit($id, $request->user(), $data);

        return $this->respond($id, "{$surrender->reference} submitted. Awaiting Finance reconciliation.", ['surrender_id' => $surrender->id]);
    }

    public function returnForCorrection(Request $request, int $id, int $surrender): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $returned = $this->accountability->returnForCorrection($this->owned($id, $surrender), $request->user(), $data['reason']);

        return $this->respond($id, "{$returned->reference} returned for correction.");
    }

    public function reconcile(Request $request, int $id, int $surrender): JsonResponse
    {
        $reconciled = $this->accountability->reconcile($this->owned($id, $surrender), $request->user());

        return $this->respond($id, "{$reconciled->reference} reconciled. Accepted spend and returns are posted.");
    }

    public function reverse(Request $request, int $id, int $surrender): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $reversed = $this->accountability->reverse($this->owned($id, $surrender), $request->user(), $data['reason']);

        return $this->respond($id, "{$reversed->reference} reversed. Compensating entries were posted; nothing was deleted.");
    }

    public function releaseUnused(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'receiver_key' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $this->closure->release($id, $request->user(), $data);

        return $this->respond($id, "KES {$data['amount']} released as unused. The approved amount is unchanged.");
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $closed = $this->closure->close($id, $request->user());

        return $this->respond($id, "{$closed->requisition_number} closed.");
    }

    /** The surrender must belong to the requisition in the URL. */
    private function owned(int $requisitionId, int $surrenderId): int
    {
        abort_unless(
            \App\Modules\Finance\PettyCash\Models\PettyCashSurrender::query()->whereKey($surrenderId)->where('requisition_id', $requisitionId)->exists(),
            404, 'Surrender not found on this requisition.'
        );

        return $surrenderId;
    }

    private function respond(int $id, string $message, array $extra = []): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message,
            'controls' => app(RequisitionControlProjection::class)->forRequisition(PettyCashRequisition::findOrFail($id))] + $extra);
    }
}
