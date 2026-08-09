<?php

namespace App\Modules\Finance\CostCollector\Contracts;

/**
 * What a calling module knows about a cost it is reporting.
 *
 * Every field except the expense code and the amount is optional: a caller
 * supplies whatever it happens to have, and CostContextResolver fills the rest
 * (project identity via ProjectIdentityResolver, activity from the task's
 * workflow stage, cost centre / GL / VAT / WHT from the expense catalogue).
 *
 * This is the whole integration surface. Stores, Procurement, HR, Logistics and
 * the mobile capture screen all build one of these and hand it to CollectsCost.
 */
final class CostContext
{
    public function __construct(
        /** Catalogue code — the only classification a human ever chooses. */
        public readonly string $expenseCode,

        /** Gross amount as it appears on the receipt, as a decimal string. */
        public readonly string $amount,

        // ── Cost object: supply any one, the resolver derives the others ──
        public readonly ?int $projectId = null,
        public readonly ?int $enquiryId = null,
        public readonly ?string $jobNumber = null,

        /** Enquiry task, when known — activity/stage is derived from it. */
        public readonly ?int $taskId = null,

        // ── Provenance: set by automated producers, null for manual capture.
        // Together these are the idempotency key, so a producer that retries
        // cannot post the same cost twice.
        public readonly ?string $sourceType = null,
        public readonly ?int $sourceId = null,

        // ── Money ──
        /** Left null at capture: recoverability depends on eTIMS validity and
         *  the claim window, which Finance decides at verification. */
        public readonly ?string $taxAmount = null,
        public readonly ?string $incurredAt = null,
        public readonly string $currency = 'KES',

        // ── Counterparty ──
        public readonly ?string $payeeType = null,   // supplier|employee|casual|authority
        public readonly ?int $payeeId = null,
        public readonly ?string $payeeName = null,

        /** The planned budget line this fulfils. Null means unbudgeted spend,
         *  which is surfaced immediately rather than discovered at close-out. */
        public readonly ?int $consumesLineId = null,

        /** Per-code fields declared by expense_codes.extra_operational_data. */
        public readonly array $details = [],

        /** Uploaded paths, validated against expense_codes.minimum_evidence. */
        public readonly array $evidence = [],

        public readonly ?string $description = null,
        public readonly ?string $costCause = null,   // defaults to 'planned'
    ) {}
}
