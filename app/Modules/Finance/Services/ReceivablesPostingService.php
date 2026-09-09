<?php

namespace App\Modules\Finance\Services;

use App\Models\EnquiryPayment;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\ProjectInvoice;
use App\Modules\Finance\Support\ChartAccountMap;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The income side of the ledger: what WNG earns, and the money clients pay.
 *
 * Nothing here existed before 2026-09-08. Issuing an invoice changed a status
 * field; receiving a client's money wrote a payment row. Neither reached the
 * ledger, so the chart's revenue accounts had never been touched, `Accounts
 * Receivable` had never been touched, and `Output Value Added Tax Payable` had
 * never been touched. The system claimed the tax it could reclaim from
 * suppliers and recorded none of the tax it charged clients.
 *
 * ## The three events, and why they are three
 *
 * Money from a client arrives, is earned, and is matched — and those are
 * genuinely separate facts that happen in any order and on different days. WNG
 * takes deposits before work starts, so cash routinely arrives months before
 * there is an invoice for it to settle.
 *
 *   1. **Cash arrives** (a verified receipt)
 *        Debit  the bank or mobile-money account it landed in
 *        Credit Client Deposits
 *      Client Deposits is a LIABILITY: money received but not yet earned is
 *      money WNG would have to give back. Crediting revenue here instead — the
 *      tempting shortcut — would book profit on a job nobody has started.
 *
 *   2. **An invoice is issued** (the work is billed)
 *        Debit  Accounts Receivable   the whole amount, tax included
 *        Credit Project Revenue       the amount before tax
 *        Credit Output VAT Payable    the tax, which belongs to the Kenya Revenue Authority
 *      Revenue is recognised here and only here. The tax is split out on its own
 *      leg because WNG is collecting it on the Authority's behalf; it was never
 *      WNG's income.
 *
 *   3. **A receipt is matched to an invoice**
 *        Debit  Client Deposits       the liability is discharged
 *        Credit Accounts Receivable   the debt is settled
 *      No cash moves. This step only says which earning the money already held
 *      belongs to.
 *
 * Across all three, cash goes up once, revenue is recognised once, and the two
 * are connected without either being asserted twice.
 *
 * ## Accounts are resolved through the map, never hardcoded
 *
 * WNG's production chart is mnemonic (AR-001, KCB-001, COS-*) rather than
 * numeric, so every account below is a REFERENCE code passed through
 * `ChartAccountMap`. Two of them — Client Deposits and Output Value Added Tax
 * Payable — have no counterpart in WNG's live chart at the time of writing and
 * must be created before this can post there. `FinanceReadinessController`
 * reports that rather than letting a posting fail at the moment somebody issues
 * an invoice.
 */
class ReceivablesPostingService
{
    /** Reference chart codes. Resolved through ChartAccountMap at use. */
    private const RECEIVABLE_CODE = '1100';       // money clients owe us
    private const REVENUE_CODE = '4100';          // what we earned, before tax
    private const OUTPUT_VAT_CODE = '2110';       // tax charged, owed to the Authority
    private const CLIENT_DEPOSIT_CODE = '2200';   // money held but not yet earned

    public function __construct(private JournalPostingService $posting)
    {
    }

    /**
     * Recognise revenue: the invoice is issued and the client now owes us.
     *
     * Returns null rather than throwing when the invoice has no lines, because
     * every invoice raised before Stage 1 has none and reading such an invoice
     * must not break. An invoice created since then cannot reach here without
     * lines — the controller requires at least one.
     */
    public function postInvoiceIssued(ProjectInvoice $invoice, ?int $actorId = null): ?JournalEntry
    {
        if ($invoice->journal_entry_id) {
            return JournalEntry::find($invoice->journal_entry_id);
        }

        $lines = $invoice->lines()->with('vatTreatment')->get();

        if ($lines->isEmpty()) {
            return null;
        }

        $postingDate = $invoice->invoice_date->toDateString();
        $period = AccountingPeriod::forDate($invoice->invoice_date);

        if (! $period || ! $period->isOpen()) {
            throw new InvalidArgumentException(
                'The accounting month containing ' . $postingDate . ' is not open, '
                . 'so this invoice cannot be issued into it.'
            );
        }

        $receivable = $this->account(self::RECEIVABLE_CODE, 'Accounts Receivable');
        $outputVat = $this->account(self::OUTPUT_VAT_CODE, 'Output Value Added Tax Payable');
        $defaultRevenue = $this->account(self::REVENUE_CODE, 'Project Revenue');

        $legs = [[
            'account_id' => $receivable,
            'entry_type' => 'debit',
            'amount' => $this->money($invoice->total_amount),
            'description' => 'Invoice ' . $invoice->invoice_number . ' issued',
            'project_enquiry_id' => $invoice->project_enquiry_id,
        ]];

        /*
         * Revenue is grouped by account, not written one leg per line.
         *
         * A fourteen-line invoice that all earns into Project Revenue should
         * produce one revenue leg, not fourteen identical ones — the line detail
         * already lives on the invoice, and repeating it in the ledger makes
         * every account statement unreadable without adding a fact. Grouping
         * still separates project revenue from hire revenue, which is the
         * distinction the chart actually draws.
         */
        $revenueByAccount = [];
        $taxTotal = '0.00';

        foreach ($lines as $line) {
            $accountId = $line->revenue_account_id ?: $defaultRevenue;
            $revenueByAccount[$accountId] = bcadd(
                $revenueByAccount[$accountId] ?? '0.00',
                $this->money($line->net_amount),
                2,
            );
            $taxTotal = bcadd($taxTotal, $this->money($line->tax_amount), 2);
        }

        foreach ($revenueByAccount as $accountId => $amount) {
            if (bccomp($amount, '0.00', 2) === 0) {
                continue;
            }

            $legs[] = [
                'account_id' => (int) $accountId,
                'entry_type' => 'credit',
                'amount' => $amount,
                'description' => 'Revenue on ' . $invoice->invoice_number,
                'project_enquiry_id' => $invoice->project_enquiry_id,
            ];
        }

        // Zero-rated, exempt and out-of-scope invoices produce no tax leg at
        // all, rather than a leg of nothing — an account statement should not
        // carry rows that say a tax of zero was charged.
        if (bccomp($taxTotal, '0.00', 2) > 0) {
            $legs[] = [
                'account_id' => $outputVat,
                'entry_type' => 'credit',
                'amount' => $taxTotal,
                'description' => 'Output Value Added Tax on ' . $invoice->invoice_number,
                'project_enquiry_id' => $invoice->project_enquiry_id,
            ];
        }

        return DB::transaction(function () use ($invoice, $legs, $postingDate, $period, $actorId) {
            $entry = $this->posting->postBalancedEntry(
                entryNo: 'JE-INV-' . str_pad((string) $invoice->id, 7, '0', STR_PAD_LEFT),
                postingDate: $postingDate,
                sourceType: ProjectInvoice::class,
                sourceId: $invoice->id,
                sourceRef: $invoice->invoice_number,
                description: 'Client invoice ' . $invoice->invoice_number,
                legs: $legs,
                createdBy: $actorId,
            );

            $invoice->forceFill([
                'journal_entry_id' => $entry->id,
                'accounting_period_id' => $period->id,
            ])->save();

            return $entry;
        });
    }

    /**
     * Cash has arrived from a client and been confirmed.
     *
     * Posted on VERIFICATION rather than on capture, deliberately. Recording it
     * when somebody first types it in would put unconfirmed money in the bank
     * account, and the verification step exists precisely because a payment
     * claim and a payment are not the same thing.
     *
     * One entry per allocation rather than per receipt: a single bank transfer
     * covering three jobs is verified three times, by three different people
     * looking at three different projects, and each of those is independently
     * reversible. The bank reconciliation in a later stage matches on the
     * receipt reference, which all three carry.
     */
    public function postClientReceipt(EnquiryPayment $payment, ?int $actorId = null): ?JournalEntry
    {
        if ($payment->journal_entry_id) {
            return JournalEntry::find($payment->journal_entry_id);
        }

        $amount = $this->money($payment->amount);

        if (bccomp($amount, '0.00', 2) <= 0) {
            return null;
        }

        $cashAccount = $this->cashAccountFor($payment);
        $clientDeposits = $this->account(self::CLIENT_DEPOSIT_CODE, 'Client Deposits');

        $postingDate = $payment->payment_date instanceof \DateTimeInterface
            ? $payment->payment_date->format('Y-m-d')
            : (string) $payment->payment_date;

        $reference = $payment->transaction_reference ?: ('receipt ' . $payment->id);

        return DB::transaction(function () use ($payment, $amount, $cashAccount, $clientDeposits, $postingDate, $reference, $actorId) {
            $entry = $this->posting->postBalancedEntry(
                entryNo: 'JE-RCPT-' . str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
                postingDate: $postingDate,
                sourceType: EnquiryPayment::class,
                sourceId: $payment->id,
                sourceRef: $reference,
                description: 'Client receipt ' . $reference,
                legs: [
                    [
                        'account_id' => $cashAccount,
                        'entry_type' => 'debit',
                        'amount' => $amount,
                        'description' => 'Client funds received',
                        'project_enquiry_id' => $payment->project_enquiry_id,
                    ],
                    [
                        'account_id' => $clientDeposits,
                        'entry_type' => 'credit',
                        'amount' => $amount,
                        'description' => 'Held on account until invoiced',
                        'project_enquiry_id' => $payment->project_enquiry_id,
                    ],
                ],
                createdBy: $actorId,
            );

            $payment->forceFill(['journal_entry_id' => $entry->id])->save();

            return $entry;
        });
    }

    /**
     * Money already held is matched to an invoice it settles.
     *
     * No cash moves here — that happened when the receipt was verified. This
     * discharges the liability created then, and settles the debt created when
     * the invoice was issued.
     *
     * `$allocationId` keys the entry number, so the same allocation can never
     * post twice and a partial allocation of one receipt across two invoices
     * produces two distinct, separately reversible entries.
     */
    public function postInvoiceAllocation(
        int $allocationId,
        ProjectInvoice $invoice,
        EnquiryPayment $payment,
        string|float $amount,
        ?int $actorId = null,
    ): ?JournalEntry {
        $money = $this->money($amount);

        if (bccomp($money, '0.00', 2) <= 0) {
            return null;
        }

        /*
         * An allocation against an invoice that never posted would debit Client
         * Deposits and credit a receivable the ledger never raised, driving the
         * control account negative by exactly the value of the invoices that
         * predate Stage 1. Those invoices are settled outside the ledger, as
         * they always were.
         */
        if (! $invoice->journal_entry_id) {
            return null;
        }

        $receivable = $this->account(self::RECEIVABLE_CODE, 'Accounts Receivable');
        $clientDeposits = $this->account(self::CLIENT_DEPOSIT_CODE, 'Client Deposits');

        return $this->posting->postBalancedEntry(
            entryNo: 'JE-ALLOC-' . str_pad((string) $allocationId, 5, '0', STR_PAD_LEFT),
            postingDate: now()->toDateString(),
            sourceType: ProjectInvoice::class,
            sourceId: $invoice->id,
            sourceRef: $invoice->invoice_number,
            description: 'Receipt ' . ($payment->transaction_reference ?: $payment->id)
                . ' applied to invoice ' . $invoice->invoice_number,
            legs: [
                [
                    'account_id' => $clientDeposits,
                    'entry_type' => 'debit',
                    'amount' => $money,
                    'description' => 'Deposit applied to ' . $invoice->invoice_number,
                    'project_enquiry_id' => $invoice->project_enquiry_id,
                ],
                [
                    'account_id' => $receivable,
                    'entry_type' => 'credit',
                    'amount' => $money,
                    'description' => 'Invoice ' . $invoice->invoice_number . ' settled',
                    'project_enquiry_id' => $invoice->project_enquiry_id,
                ],
            ],
            createdBy: $actorId,
        );
    }

    /**
     * Which real account the money landed in.
     *
     * `payment_sources` exists so that petty cash, each bank and each mobile
     * money float each carry their own ledger account — that is what lets one
     * posting engine handle all of them. A receipt naming no source cannot be
     * placed in any particular account, and guessing the main bank would put
     * money somewhere it never arrived, so it refuses.
     */
    private function cashAccountFor(EnquiryPayment $payment): int
    {
        $sourceId = $payment->payment_source_id
            ?: $payment->clientReceipt?->payment_source_id;

        if (! $sourceId) {
            throw new InvalidArgumentException(
                'This receipt does not say which account the money arrived in, so it cannot be posted. '
                . 'Record the payment source on the receipt first.'
            );
        }

        $accountId = PaymentSource::whereKey($sourceId)->value('gl_account_id');

        if (! $accountId) {
            throw new InvalidArgumentException(
                'The payment source on this receipt has no ledger account configured. '
                . 'Finance must map it before client money can be recorded.'
            );
        }

        return (int) $accountId;
    }

    /**
     * A required control account, by reference code, named in plain words when
     * it is missing.
     *
     * The plain name matters: "chart account 2200 is not postable" is a message
     * for whoever wrote this, while "Client Deposits" is a message for the
     * person who has to go and create it.
     */
    private function account(string $referenceCode, string $plainName): int
    {
        $localCode = ChartAccountMap::local($referenceCode);
        $id = ChartOfAccount::postable()->where('code', $localCode)->value('id');

        if (! $id) {
            throw new InvalidArgumentException(
                "This installation has no active, postable \"{$plainName}\" account "
                . "(expected chart code {$localCode}). Finance must create it, or map "
                . "reference code {$referenceCode} to the local account in config/finance_accounts.php, "
                . 'before client invoices and receipts can reach the ledger.'
            );
        }

        return (int) $id;
    }

    private function money(string|float|null $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
