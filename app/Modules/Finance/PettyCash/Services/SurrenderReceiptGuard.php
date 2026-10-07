<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\Services\DuplicateDetectionService;
use Illuminate\Validation\ValidationException;

/**
 * W3-5: the duplicate columns for one surrender line, or a refusal.
 *
 * A repeat of the same supplier + receipt + amount within one submission is
 * refused outright — one receipt cannot be two lines of one claim. A match
 * against another live claim needs an explicit reason AND the override
 * permission; W2-5's bill override follows the same rule.
 *
 * One rule for every surrender, whether it is made for the whole requisition
 * or by one receiver (Report 75R-B).
 */
final class SurrenderReceiptGuard
{
    public function __construct(private readonly DuplicateDetectionService $duplicates)
    {
    }

    /**
     * @param  array<string, true>  $seen  receipts already met in this submission
     * @return array<string, mixed>
     */
    public function fields(array $itemData, string $gross, PettyCashRequisition $requisition, array &$seen, ?User $actor): array
    {
        $none = ['duplicate_of_surrender_item_id' => null, 'duplicate_of_payment_id' => null,
            'duplicate_override_reason' => null, 'duplicate_overridden_by' => null, 'duplicate_overridden_at' => null];

        if (blank($itemData['supplier_name'] ?? null) || blank($itemData['receipt_number'] ?? null)) {
            return $none;
        }

        $key = strtolower(trim($itemData['supplier_name'])).'|'.strtolower(trim($itemData['receipt_number'])).'|'.$gross;
        if (isset($seen[$key])) {
            throw ValidationException::withMessages(['duplicate_receipt' => "Receipt {$itemData['receipt_number']} from {$itemData['supplier_name']} appears twice in this surrender."]);
        }
        $seen[$key] = true;

        $match = $this->duplicates->checkExpenseReceipt(
            $itemData['supplier_name'], $itemData['receipt_number'], $gross, $requisition->id
        );
        if ($match['status'] !== 'confirmed') {
            return $none;
        }

        $where = $match['matched_type'] === 'payment'
            ? "petty cash payment #{$match['matched_id']}"
            : "surrender item #{$match['matched_id']}";
        if (blank($itemData['duplicate_override_reason'] ?? null)) {
            throw ValidationException::withMessages(['duplicate_receipt' => "Receipt {$itemData['receipt_number']} from {$itemData['supplier_name']} for KES {$gross} is already claimed as {$where}. An authorised override with a reason is required."]);
        }
        if (! $actor?->can(Permissions::FINANCE_EXPENSE_DUPLICATE_OVERRIDE)) {
            throw ValidationException::withMessages(['duplicate_receipt' => "Receipt {$itemData['receipt_number']} is already claimed as {$where}, and you are not authorised to override a duplicate expense receipt."]);
        }

        return [
            'duplicate_of_surrender_item_id' => $match['matched_type'] === 'surrender_item' ? $match['matched_id'] : null,
            'duplicate_of_payment_id' => $match['matched_type'] === 'payment' ? $match['matched_id'] : null,
            'duplicate_override_reason' => $itemData['duplicate_override_reason'],
            'duplicate_overridden_by' => $actor->id,
            'duplicate_overridden_at' => now(),
        ];
    }
}
