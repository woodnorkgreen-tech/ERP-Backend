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

        /**
         * planned | committed | accrued | actual.
         *
         * Manual capture is always an actual. Producers post commitments (an
         * approved PO) and accruals (goods received, not yet invoiced); the
         * budget projector posts planned lines.
         */
        public readonly string $nature = 'actual',

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

        /**
         * Identifies a line WITHIN the source document, for sources whose parts
         * are keyed by string rather than by row id — a budget line inside a
         * task_budget_data JSON array, for instance. Empty for whole-document
         * sources, never null, so the idempotency index cannot be defeated by
         * MySQL treating NULLs as distinct.
         */
        public readonly string $sourceRef = '',

        // ── Money ──
        /** Left null at capture: recoverability depends on eTIMS validity and
         *  the claim window, which Finance decides at verification. */
        public readonly ?string $taxAmount = null,
        public readonly ?string $incurredAt = null,
        public readonly string $currency = 'KES',
        /** Rate to base currency. Only meaningful when currency is not KES. */
        public readonly ?string $fxRate = null,

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

        /**
         * The source document already carried its own approval — an approved GRN,
         * a completed payroll run — so the line lands verified rather than queuing
         * for a human to approve what procurement or HR already did.
         *
         * Only server-side producers may set this. It is never accepted from an
         * HTTP request, because that would let a submitter approve their own cost
         * and defeat the separation of duties the whole design rests on.
         */
        public readonly bool $sourceApproved = false,
    ) {}
}
