<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;

final class LedgerEntry
{
    public string $reference_number;
    public string $type; // 'credit'|'debit'
    public string $amount; // decimal as string
    public array $metadata;
    public \DateTimeImmutable $posted_at;
    public ?string $sourceType = null;
    public ?int $sourceId = null;

    private function __construct(string $reference, string $type, string $amount, array $metadata = [], ?\DateTimeImmutable $postedAt = null)
    {
        $this->reference_number = $reference;
        $this->type = $type;
        $this->amount = $amount;
        $this->metadata = $metadata;
        $this->posted_at = $postedAt ?? new \DateTimeImmutable();
    }

    public static function creditForTopUp(PettyCashTopUp $topUp): self
    {
        $entry = new self('TOP-' . str_pad((string)$topUp->id, 6, '0', STR_PAD_LEFT), 'credit', number_format($topUp->amount, 2, '.', ''), [
            'payment_method' => $topUp->payment_method ?? 'cash',
            'transaction_code' => $topUp->transaction_code ?? null,
            'description' => $topUp->description ?? 'Top Up',
            'created_by' => $topUp->created_by ?? null,
        ], $topUp->date_topped_up ? new \DateTimeImmutable($topUp->date_topped_up) : null);

        $entry->sourceType = 'top_up';
        $entry->sourceId = $topUp->id;
        return $entry;
    }

    /**
     * Backs out the credit a top-up posted.
     *
     * Kept beside creditForTopUp deliberately: a reversal that does not mirror
     * the entry it undoes is how a balance drifts. Removing a top-up without one
     * leaves its credit in the ledger and the cached balance overstated forever.
     */
    public static function reversalForTopUp(PettyCashTopUp $topUp, ?int $reversedBy = null): self
    {
        $entry = new self(
            'REV-TOP-' . str_pad((string) $topUp->id, 6, '0', STR_PAD_LEFT),
            'debit',
            number_format((float) $topUp->amount, 2, '.', ''),
            [
                'reverses' => 'TOP-' . str_pad((string) $topUp->id, 6, '0', STR_PAD_LEFT),
                'reason' => 'Top-up deleted',
                'payment_method' => $topUp->payment_method ?? 'cash',
                'transaction_code' => $topUp->transaction_code ?? null,
                'original_created_by' => $topUp->created_by ?? null,
                'reversed_by' => $reversedBy,
            ],
        );

        // 'top_up', not 'top_up_reversal': sourceType feeds the balance
        // projection's last_transaction_type, which is an enum of top_up |
        // disbursement. A reversal IS top-up-sourced; that it reverses is
        // carried by the REV- reference and the metadata, which is the honest
        // place for it rather than widening the enum.
        $entry->sourceType = 'top_up';
        $entry->sourceId = $topUp->id;

        return $entry;
    }

    public static function debitForDisbursement(PettyCashDisbursement $d): self
    {
        $amount = bcadd((string)$d->amount, (string)($d->transaction_cost ?? '0'), 2);
        $entry = new self('PCR-' . str_pad((string)$d->id, 6, '0', STR_PAD_LEFT), 'debit', $amount, [
            'amount' => (float)$d->amount,
            'receiver' => $d->receiver,
            'account' => $d->account,
            'expense_code_id' => $d->expense_code_id,
            'description' => $d->description,
            'classification' => $d->classification,
            'payment_method' => $d->payment_method,
            'payment_source_id' => $d->payment_source_id,
            'transaction_code' => $d->transaction_code,
            'transaction_cost' => (float)($d->transaction_cost ?? 0),
            'receipt_type' => $d->receipt_type,
            'receipt_number' => $d->receipt_number,
            'tax_amount' => (float) ($d->tax_amount ?? 0),
            'budget_category' => $d->budget_category ?? null,
            'created_by' => $d->created_by ?? null,
            'project_name' => $d->project_name ?? null,
            'venue' => $d->venue ?? null,
            'job_number' => $d->job_number ?? null,
            'requisition_id' => $d->requisition_id ?? null,
            'status' => $d->status ?? 'active',
        ], $d->date_disbursed ? new \DateTimeImmutable($d->date_disbursed) : null);

        $entry->sourceType = 'disbursement';
        $entry->sourceId = $d->id;
        return $entry;
    }

    public function toRow(string $balanceSnapshot): array
    {
        return [
            'reference_number' => $this->reference_number,
            'type' => $this->type,
            'amount' => $this->amount,
            'balance_snapshot' => $balanceSnapshot,
            'metadata' => json_encode($this->metadata),
            'posted_at' => $this->posted_at->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public static function custom(string $reference, string $type, string $amount, array $metadata = [], ?\DateTimeImmutable $postedAt = null): self
    {
        $entry = new self($reference, $type, $amount, $metadata, $postedAt);
        return $entry;
    }
}
