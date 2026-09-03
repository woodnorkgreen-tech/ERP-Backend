<?php

namespace App\Modules\Finance\CostCollector\Contracts;

/**
 * One line of an approved budget, on its way into the cost account.
 *
 * Deliberately NOT a CostContext. A budget line has no payee, no evidence and no
 * tax position — it is a forecast, not a transaction — but it does carry
 * quantity and rate. Sharing one DTO between the two would mean half its fields
 * were meaningless in either direction.
 */
final class PlannedLine
{
    public function __construct(
        /** materials | labour | expenses | logistics */
        public readonly string $category,
        public readonly string $amount,
        public readonly ?string $description = null,

        public readonly ?int $projectId = null,
        public readonly ?int $enquiryId = null,
        public readonly ?string $jobNumber = null,
        public readonly ?int $taskId = null,

        public readonly ?string $unit = null,
        public readonly ?string $quantity = null,
        public readonly ?string $unitRate = null,

        /** Identifies this line for re-projection: (type, parent id, json key). */
        public readonly string $sourceType = 'BudgetLine',
        public readonly ?int $sourceId = null,
        public readonly string $sourceRef = '',

        /**
         * The line came from an approved budget addition rather than the original
         * budget, so its cause is a client change rather than plan.
         */
        public readonly bool $isAddition = false,

        /** Stable operational identifiers used to match actuals exactly. */
        public readonly array $details = [],
    ) {}
}
