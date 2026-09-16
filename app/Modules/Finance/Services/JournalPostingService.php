<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\PostingRule;
use App\Modules\Finance\Models\SpendVoucher;
use App\Modules\Finance\Models\SpendVoucherAllocation;
use App\Modules\Finance\Models\VatTreatment;
use App\Modules\Finance\Models\WhtCategory;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillPayment;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class JournalPostingService
{
    /**
     * Last-resort chart codes for the two tax accounts, used when the treatment
     * or category on the line carries no account of its own. Named by code
     * rather than id because the chart is reseeded independently and its
     * primary keys are not stable — the codes are what the expense catalogue
     * itself references (`1330 Input VAT Recoverable`, `2120 Withholding Tax
     * Payable`).
     */
    private const VAT_INPUT_CODE = '1330';

    private const WHT_PAYABLE_CODE = '2120';

    /**
     * Settlement accounts, by chart code.
     *
     * A cost's DEBIT says what the money was for — that comes from the expense
     * code, and 94 of the 100 codes already carry it. Its CREDIT says what paid
     * for it, and that is a property of how the cost arose, not of what was
     * bought. Getting the two confused is what put every journal in this system
     * against the bank.
     */
    private const INVENTORY_CODE = '1200';      // material relieved from the shelf

    private const ACCRUED_CODE = '2150';        // goods received, not yet invoiced

    private const PAYABLE_CODE = '2100';        // incurred, still owed to someone

    private const STAFF_ADVANCE_CODE = '1300';  // staff float/advance imprest asset

    /**
     * What a bank, card or mobile-money operator charges us to move the money.
     *
     * The account the expense catalogue already names as the debit for
     * `OE-FIN-001`, which is why it is referenced by code here too.
     */
    private const BANK_CHARGES_CODE = '7800';

    /**
     * Create a balanced GL journal entry for a verified CostLine.
     *
     * Up to four legs, because a cost is rarely just an expense and a payment:
     *
     *   Dr  Expense / WIP          net_amount
     *   Dr  Input VAT recoverable  tax_amount        (recoverable VAT only)
     *   Cr  WHT payable            wht_amount        (retained, owed to KRA)
     *   Cr  Cash / Payable         net + tax − wht   (what actually leaves)
     *
     * This used to be two legs both carrying `net_amount`, which balanced
     * internally and was wrong against the world: verification had already
     * priced the VAT and the withholding onto the line, and neither reached the
     * ledger. Cash was credited short by the tax, so the bank could never
     * reconcile; input VAT reached no receivable, so there was no VAT return in
     * the data; and WHT reached no liability, so nothing was owed to KRA and the
     * supplier's payable was overstated by the amount retained from them.
     *
     * The credit to Cash/Payable is the balancing figure rather than an
     * independently computed one, so the entry cannot be made to unbalance by a
     * rounding difference between the tax legs.
     */
    public function postCostLine(CostLine $line): ?JournalEntry
    {
        if ($line->journal_entry_id && $line->posted_at) {
            return JournalEntry::find($line->journal_entry_id);
        }

        $this->assertOpenPeriod($line->accounting_period_id, "cost line {$line->ref}");

        return DB::transaction(function () use ($line) {
            $rule = $this->resolveRuleForCostLine($line);
            [$debitAccountId, $creditAccountId] = $this->resolveAccountsForCostLine($line, $rule);

            if (! $debitAccountId || ! $creditAccountId) {
                throw new InvalidArgumentException("No complete posting rule could be resolved for cost line {$line->ref}.");
            }

            $legs = $this->costLineLegs($line, $debitAccountId, $creditAccountId);

            if (! $legs) {
                return null;
            }

            $accountIds = array_unique(array_column($legs, 'account_id'));
            if (ChartOfAccount::postable()->whereIn('id', $accountIds)->count() !== count($accountIds)) {
                throw new InvalidArgumentException(
                    "Cost line {$line->ref} resolves to an inactive or non-postable account. Finance must correct the account mapping before posting."
                );
            }

            $total = array_reduce(
                array_filter($legs, fn (array $leg) => $leg['entry_type'] === 'debit'),
                fn (string $carry, array $leg) => bcadd($carry, $leg['amount'], 2),
                '0.00',
            );

            $entryNo = 'JE-CL-'.str_pad((string) $line->id, 7, '0', STR_PAD_LEFT);

            $entry = JournalEntry::create([
                'entry_no' => $entryNo,
                'posting_date' => substr((string) ($line->incurred_at ?? now()->toDateString()), 0, 10),
                'accounting_period_id' => $line->accounting_period_id,
                'cost_line_id' => $line->id,
                'source_type' => CostLine::class,
                'source_id' => $line->id,
                'source_ref' => $line->ref,
                'description' => $line->description ?? 'Cost line posting: '.$line->ref,
                'total_debit' => $total,
                'total_credit' => $total,
                'status' => 'posted',
                'created_by' => $line->verified_by ?? auth()->id(),
                'posted_at' => now(),
            ]);

            foreach ($legs as $leg) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'currency' => $line->currency ?? 'KES',
                    'fx_rate' => $line->fx_rate ?? 1,
                    'cost_centre_id' => $line->cost_centre_id,
                    'activity_id' => $line->activity_id,
                    'project_id' => $line->project_id,
                    'project_enquiry_id' => $line->project_enquiry_id,
                    ...$leg,
                ]);
            }

            $line->forceFill([
                'journal_entry_id' => $entry->id,
                'posted_at' => now(),
            ])->save();

            return $entry;
        });
    }

    /**
     * The debit and credit legs for a cost line, in posting order.
     *
     * Returns an empty array when there is nothing to post — a zero-value line,
     * which producers do generate for placeholder rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function costLineLegs(CostLine $line, int $debitAccountId, int $creditAccountId): array
    {
        $net = $this->money($line->net_amount);
        $tax = $this->money($line->tax_amount);
        $wht = $this->money($line->wht_amount);

        // Signed cost credits (for example a partial Stores return) use the same
        // accounts as the original cost with every leg reversed. Keeping the
        // amount signed on the CostLine makes project actuals and budget variance
        // mathematically correct; journal legs themselves remain positive.
        if (bccomp($net, '0.00', 2) === -1) {
            $amount = ltrim($net, '-');

            return [
                [
                    'account_id' => $creditAccountId,
                    'entry_type' => 'debit',
                    'amount' => $amount,
                    'base_amount' => ltrim($this->money($line->base_net_amount), '-'),
                    'description' => $line->description,
                ],
                [
                    'account_id' => $debitAccountId,
                    'entry_type' => 'credit',
                    'amount' => $amount,
                    'base_amount' => ltrim($this->money($line->base_net_amount), '-'),
                    'description' => $line->description,
                ],
            ];
        }

        // Gross is derived rather than read from `amount`, so that a legacy row
        // whose amount drifted from net + tax still produces a balanced entry.
        $gross = bcadd($net, $tax, 2);

        if (bccomp($gross, '0.00', 2) <= 0) {
            return [];
        }

        $settled = bcsub($gross, $wht, 2);

        if (bccomp($settled, '0.00', 2) === -1) {
            throw new InvalidArgumentException(
                "Withholding of {$wht} exceeds the gross amount on cost line {$line->ref}."
            );
        }

        $legs = [];

        if (bccomp($net, '0.00', 2) === 1) {
            $legs[] = [
                'account_id' => $debitAccountId,
                'entry_type' => 'debit',
                'amount' => $net,
                'base_amount' => $line->base_net_amount ?? $this->base($line, $net),
                'description' => $line->description,
            ];
        }

        if (bccomp($tax, '0.00', 2) === 1) {
            $legs[] = [
                'account_id' => $this->vatInputAccountId($line),
                'entry_type' => 'debit',
                'amount' => $tax,
                'base_amount' => $this->base($line, $tax),
                'description' => 'Recoverable input VAT on '.$line->ref,
            ];
        }

        if (bccomp($wht, '0.00', 2) === 1) {
            $legs[] = [
                'account_id' => $this->whtPayableAccountId($line),
                'entry_type' => 'credit',
                'amount' => $wht,
                'base_amount' => $this->base($line, $wht),
                'description' => 'Withholding tax retained on '.$line->ref,
            ];
        }

        $creditDesc = 'Payable/Clearing for '.$line->ref;
        $sourceId = $line->details['payment_source_id'] ?? null;
        if ($sourceId && $source = PaymentSource::find($sourceId)) {
            $creditDesc = "Direct settlement via {$source->name} for {$line->ref}";
        } elseif (($line->details['funding_mode'] ?? null) === 'out_of_pocket') {
            $claimant = $line->details['claimant_name'] ?? $line->submitted_by_name ?? 'Staff';
            $merchant = $line->payee_name ?: 'merchant not recorded';
            $creditDesc = "Staff reimbursement payable to {$claimant} (Receipt from {$merchant}) for {$line->ref}";
        }

        $legs[] = [
            'account_id' => $creditAccountId,
            'entry_type' => 'credit',
            'amount' => $settled,
            'base_amount' => $this->base($line, $settled),
            'description' => $creditDesc,
        ];

        return $legs;
    }

    /**
     * Where recoverable VAT is claimed.
     *
     * Read from the treatment on the line first, because which account applies
     * is a property of the treatment — a future reduced-rate or import-VAT code
     * may well claim somewhere else. The chart-code fallback covers lines
     * verified before treatments carried an account, and the throw covers the
     * only remaining case: someone entered recoverable VAT against a treatment
     * that has nowhere to put it, which is a configuration answer rather than
     * something to guess at.
     */
    private function vatInputAccountId(CostLine $line): int
    {
        $account = $line->vat_treatment_id
            ? VatTreatment::whereKey($line->vat_treatment_id)->value('gl_account_id')
            : null;

        $account ??= ChartOfAccount::postable()->where('code', self::VAT_INPUT_CODE)->value('id');

        if (! $account) {
            throw new InvalidArgumentException(
                "Cost line {$line->ref} carries recoverable VAT but no input-VAT account is configured. "
                .'Set a GL account on the VAT treatment, or add account '.self::VAT_INPUT_CODE.' to the chart.'
            );
        }

        return (int) $account;
    }

    private function whtPayableAccountId(CostLine $line): int
    {
        $account = $line->wht_category_id
            ? WhtCategory::whereKey($line->wht_category_id)->value('gl_account_id')
            : null;

        $account ??= ChartOfAccount::postable()->where('code', self::WHT_PAYABLE_CODE)->value('id');

        if (! $account) {
            throw new InvalidArgumentException(
                "Cost line {$line->ref} withholds tax but no WHT payable account is configured. "
                .'Set a GL account on the WHT category, or add account '.self::WHT_PAYABLE_CODE.' to the chart.'
            );
        }

        return (int) $account;
    }

    /** Transaction amount restated in the reporting currency. */
    private function base(CostLine $line, string $amount): string
    {
        return bcmul($amount, (string) ($line->fx_rate ?: 1), 2);
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    /**
     * Create a balanced GL journal entry for a SpendVoucher.
     *
     * @deprecated Use postPayment() with the voucher's payment instead.
     *             This method will be removed in a future version after
     *             all callers migrate to the unified payment architecture.
     */
    public function postSpendVoucher(SpendVoucher $voucher): ?JournalEntry
    {
        // Reversal vouchers: delegate to reverseEntry to ensure proper double-entry reversal
        if ($voucher->type === 'reversal' && $voucher->reversal_of_id) {
            $originalEntry = JournalEntry::where('spend_voucher_id', $voucher->reversal_of_id)->first();
            if ($originalEntry) {
                return $this->reverseEntry($originalEntry, $voucher->posted_by ?? auth()->id(), 'Reversal of voucher '.$voucher->voucher_no);
            }
        }

        $existing = JournalEntry::where('spend_voucher_id', $voucher->id)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($voucher) {
            $amount = (string) ($voucher->net_cash_paid ?? $voucher->total_amount);
            if (bccomp($amount, '0.00', 2) <= 0) {
                return null;
            }

            $debitLegs = $this->voucherDebitLegs($voucher, $amount);
            if (! $debitLegs) {
                throw new InvalidArgumentException("No complete posting rule could be resolved for spend voucher {$voucher->voucher_no}.");
            }

            $entryNo = 'JE-SV-'.str_pad((string) $voucher->id, 7, '0', STR_PAD_LEFT);

            if (! in_array($voucher->type, ['payment', 'reimbursement'], true)) {
                $creditAccountId = $this->voucherPaymentSourceAccount($voucher);
                if (! $creditAccountId) {
                    throw new InvalidArgumentException("No credit account could be resolved for spend voucher {$voucher->voucher_no}.");
                }

                $legs = array_map(fn (array $leg): array => [
                    ...$leg,
                    'entry_type' => 'debit',
                    'currency' => $voucher->currency ?? 'KES',
                    'fx_rate' => $voucher->fx_rate ?? 1,
                ], $debitLegs);
                $legs[] = [
                    'account_id' => $creditAccountId,
                    'entry_type' => 'credit',
                    'amount' => $amount,
                    'currency' => $voucher->currency ?? 'KES',
                    'fx_rate' => $voucher->fx_rate ?? 1,
                    'base_amount' => $voucher->base_total_amount ?? $amount,
                    'description' => 'Voucher credit',
                ];

                $entry = $this->postBalancedEntry(
                    entryNo: $entryNo,
                    postingDate: (string) ($voucher->posting_date?->toDateString() ?? now()->toDateString()),
                    sourceType: SpendVoucher::class,
                    sourceId: $voucher->id,
                    sourceRef: $voucher->voucher_no,
                    description: 'Voucher posting: '.$voucher->voucher_no,
                    legs: $legs,
                    createdBy: $voucher->posted_by ?? auth()->id(),
                    accountingPeriodId: $voucher->accounting_period_id,
                    spendVoucherId: $voucher->id,
                );
            } else {
                $entry = $this->postCashSettlement(
                    entryNo: $entryNo,
                    postingDate: (string) ($voucher->posting_date?->toDateString() ?? now()->toDateString()),
                    sourceType: SpendVoucher::class,
                    sourceId: $voucher->id,
                    sourceRef: $voucher->voucher_no,
                    description: 'Voucher payment: '.$voucher->voucher_no,
                    debitLegs: $debitLegs,
                    paymentSource: $voucher->paymentSource,
                    creditDescription: 'Cash/Bank Outflow',
                    createdBy: $voucher->posted_by ?? auth()->id(),
                    accountingPeriodId: $voucher->accounting_period_id,
                    spendVoucherId: $voucher->id,
                    currency: $voucher->currency ?? 'KES',
                    fxRate: (string) ($voucher->fx_rate ?? 1),
                    creditBaseAmount: (string) ($voucher->base_total_amount ?? $amount),
                );
            }

            $voucher->forceFill([
                'posted_at' => now(),
                'posted_by' => auth()->id() ?? $voucher->posted_by,
            ])->save();

            return $entry;
        });
    }

    /**
     * Compensate a posted cost without rewriting its original journal.
     * The reversing entry swaps every debit and credit leg and posts in today's
     * open period, so a correction never mutates a locked historical period.
     */
    public function reverseCostLine(CostLine $line, ?int $actorId, string $reason): JournalEntry
    {
        if (! $line->journal_entry_id || ! $line->posted_at) {
            throw new InvalidArgumentException("Cost line {$line->ref} has no posted journal to reverse.");
        }

        return $this->reverseEntry(
            JournalEntry::with('lines')->findOrFail($line->journal_entry_id),
            $actorId,
            $reason,
        );
    }

    /**
     * Compensate ANY posted journal entry, whatever document produced it.
     *
     * This was `reverseCostLine`'s private body until 2026-09-08. Cost lines
     * were the only document that could be corrected: a mis-posted spend
     * voucher, supplier invoice, supplier payment or payroll run could only be
     * put right by editing the database by hand, which is precisely the thing an
     * immutable ledger exists to make impossible. Generalising it costs nothing,
     * because none of the logic here was ever cost-line specific — the legs are
     * copied and flipped, and the identity fields are copied from the original.
     *
     * Three properties this must keep:
     *
     * - **The original is never touched.** Its status becomes `reversed` so a
     *   reader can see it was corrected, but not one figure changes. Reports and
     *   exports deliberately include both, so a reversed month still nets to what
     *   it always did.
     * - **The reversal is dated today, not when the original was posted.** You
     *   correct an error on the day you find it. Back-dating the compensation
     *   into the original's month would silently restate a period that has
     *   already been reported on, and would fail outright once that month is
     *   closed.
     * - **Exactly one reversal per entry.** `reversal_of_id` carries a unique
     *   key; the read below makes the common case a clean no-op rather than an
     *   integrity error, and the key is what settles a genuine race.
     */
    public function reverseEntry(JournalEntry $original, ?int $actorId, string $reason): JournalEntry
    {
        if ($original->status === 'draft') {
            throw new InvalidArgumentException(
                "Journal entry {$original->entry_no} was never posted, so there is nothing to reverse."
            );
        }

        // A reversal reverses a document, not another reversal. Allowing it
        // would produce a chain nobody can read and would restate the original
        // a second time; correcting a wrong reversal means posting the document
        // again, which is a decision for the person who owns the document.
        if ($original->reversal_of_id) {
            throw new InvalidArgumentException(
                "Journal entry {$original->entry_no} is itself a reversal and cannot be reversed. "
                .'Post the corrected document instead.'
            );
        }

        if ($existing = JournalEntry::where('reversal_of_id', $original->id)->first()) {
            return $existing;
        }

        $period = AccountingPeriod::forDate(now());
        if (! $period || ! $period->isOpen()) {
            throw new InvalidArgumentException('No open accounting period is available for the reversal.');
        }

        $original->loadMissing('lines');

        if ($original->lines->isEmpty()) {
            throw new InvalidArgumentException(
                "Journal entry {$original->entry_no} has no lines to reverse."
            );
        }

        return DB::transaction(function () use ($original, $period, $actorId, $reason) {
            $entry = JournalEntry::create([
                'entry_no' => $this->reversalEntryNo($original),
                'posting_date' => now()->toDateString(),
                'accounting_period_id' => $period->id,
                // Identity travels with the reversal so the compensating entry
                // is reachable from the same document the original was.
                'cost_line_id' => $original->cost_line_id,
                'spend_voucher_id' => $original->spend_voucher_id,
                'source_type' => $original->source_type,
                'source_id' => $original->source_id,
                'source_ref' => $original->source_ref,
                'description' => 'Reversal of '.$original->entry_no.': '.$reason,
                'total_debit' => $original->total_credit,
                'total_credit' => $original->total_debit,
                'status' => 'posted',
                'reversal_of_id' => $original->id,
                'created_by' => $actorId,
                'posted_at' => now(),
            ]);

            foreach ($original->lines as $originalLine) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $originalLine->account_id,
                    'entry_type' => $originalLine->entry_type === 'debit' ? 'credit' : 'debit',
                    'amount' => $originalLine->amount,
                    'currency' => $originalLine->currency,
                    'fx_rate' => $originalLine->fx_rate,
                    'base_amount' => $originalLine->base_amount,
                    'description' => 'Reversal: '.($originalLine->description ?? $original->source_ref),
                    'cost_centre_id' => $originalLine->cost_centre_id,
                    'activity_id' => $originalLine->activity_id,
                    'project_id' => $originalLine->project_id,
                    'project_enquiry_id' => $originalLine->project_enquiry_id,
                ]);
            }

            $original->forceFill(['status' => 'reversed'])->save();

            return $entry;
        });
    }

    /**
     * `entry_no` is unique and 32 characters wide, so the suffix has to fit
     * rather than be assumed to. Every generated number today is well inside
     * that (`JE-BILL-0000001` is the longest at 15), but a future document
     * prefix should not silently collide with another entry's truncation.
     */
    private function reversalEntryNo(JournalEntry $original): string
    {
        $suffix = '-REV';
        $room = 32 - strlen($suffix);

        return substr($original->entry_no, 0, $room).$suffix;
    }

    private function resolveRuleForCostLine(CostLine $line): ?PostingRule
    {
        if ($line->expense_code_id) {
            $rule = PostingRule::active()
                ->where('expense_code_id', $line->expense_code_id)
                ->orderBy('priority', 'desc')
                ->first();
            if ($rule) {
                return $rule;
            }
        }

        return PostingRule::active()
            ->whereNull('expense_code_id')
            ->orderBy('priority', 'desc')
            ->first();
    }

    /** @return array{0: int|null, 1: int|null} */
    private function resolveAccountsForCostLine(CostLine $line, ?PostingRule $rule): array
    {
        $debitId = $rule?->debit_account_id;
        $creditId = $rule?->credit_account_id;

        // Goods received into the store are a stock asset, not project work in
        // progress — regardless of which job triggered the purchase, because
        // material bought for a job still routes through Stores and is only
        // consumed when it is issued. Debiting WIP here and again at issue
        // charged the project twice for one delivery.
        if (! $debitId && ($line->source_ref === 'accrual' || $line->nature === CostLine::NATURE_ACCRUED)) {
            $debitId = $this->accountByCode(self::INVENTORY_CODE);
        }

        if (! $debitId && $line->expense_code_id) {
            // `default_debit_account_id` is the column expense_codes actually
            // carries; `gl_account_id` belongs to payment_sources. Reading the
            // wrong name here returned null for every code ever posted, so the
            // catalogue's GL mapping was dead and everything fell through to
            // the guesswork below.
            $debitId = $line->expenseCode?->default_debit_account_id;
        }

        // A named expense code must never fall through to a guessed account.
        // Reference rows whose accounting answer depends on the transaction
        // (bank transfers, asset purchases, tax settlement) stay inactive until
        // their dedicated workflow supplies that answer.
        if (! $debitId && $line->expense_code_id) {
            throw new InvalidArgumentException(
                "Expense code {$line->expenseCode?->code} has no debit account configured. Finance must map or retire the code before posting."
            );
        }

        // Fallback debit only for historical/source-produced lines that carry
        // no expense-code identity at all.
        //
        // `12%` is the Project WIP band (1211–1219) — but it also matches 1200
        // Raw-material Inventory, which sorts first. An unmapped cost therefore
        // debited Inventory, and since a stores issue credits Inventory too, the
        // entry hit the same account on both sides: balanced, and meaningless.
        // Inventory is a stock account, never a destination for cost, so it is
        // excluded explicitly.
        if (! $debitId) {
            $debitId = ChartOfAccount::postable()
                ->where('code', '!=', self::INVENTORY_CODE)
                ->where(function ($q) {
                    $q->where('code', 'COS-001')->orWhere('code', 'like', '121%')->orWhere('category', 'expense');
                })
                ->orderBy('code')
                ->value('id');
        }

        $creditId ??= $this->settlementAccountFor($line);

        return [$debitId, $creditId];
    }

    /**
     * What settled this cost — the credit leg.
     *
     * This replaces a fallback that read "the first postable account whose
     * category is asset", which in a seeded chart is the bank. Every cost line
     * in the system was therefore crediting Bank, which asserts that cash left
     * the account. For a stores issue no cash moves at all — it moved when the
     * material was bought — so the bank was being relieved twice for the same
     * material while Raw-material Inventory never moved at all.
     *
     * Resolution is by how the cost arose, in decreasing order of certainty.
     * The final fallback is deliberately Accounts Payable rather than a cash
     * account: a cost we cannot trace to a settlement is one we still owe, and
     * claiming we paid it from the bank is the more damaging of the two guesses.
     */
    private function settlementAccountFor(CostLine $line): ?int
    {
        // Material off the shelf, or back onto it. No cash is involved either
        // way; the inventory asset is relieved or restored. A negative net on a
        // return swaps the legs, so one account serves both directions.
        if (in_array($line->source_ref, ['stock-issue', 'stock-return'], true)) {
            return $this->accountByCode(self::INVENTORY_CODE);
        }

        // Goods received against an order but not yet invoiced: the company owes
        // the supplier from the moment it accepts delivery.
        if ($line->source_ref === 'accrual' || $line->nature === CostLine::NATURE_ACCRUED) {
            return $this->accountByCode(self::ACCRUED_CODE);
        }

        // Spend that names the float or account it came out of — petty cash
        // disbursements and their transaction fees carry this.
        $sourceId = $line->details['payment_source_id'] ?? null;
        if ($sourceId && $account = PaymentSource::whereKey($sourceId)->value('gl_account_id')) {
            return (int) $account;
        }

        // Settled through a spend voucher: use whatever that voucher was paid from.
        if ($line->funding_voucher_id) {
            $viaVoucher = SpendVoucher::whereKey($line->funding_voucher_id)
                ->value('payment_source_id');
            if ($viaVoucher && $account = PaymentSource::whereKey($viaVoucher)->value('gl_account_id')) {
                return (int) $account;
            }
        }

        return $this->accountByCode(self::PAYABLE_CODE);
    }

    private function accountByCode(string $code): ?int
    {
        return ChartOfAccount::postable()->where('code', $code)->value('id');
    }

    /** No financial fact may enter an unassigned or closed reporting month. */
    private function assertOpenPeriod(?int $periodId, string $source): void
    {
        $period = $periodId ? AccountingPeriod::find($periodId) : null;

        if (! $period) {
            throw new InvalidArgumentException("No accounting period is assigned to {$source}.");
        }

        if (! $period->isOpen()) {
            throw new InvalidArgumentException(sprintf(
                'The accounting period %04d-%02d is %s, so %s cannot be posted.',
                $period->year,
                $period->month,
                $period->status,
                $source,
            ));
        }
    }

    private function voucherPaymentSourceAccount(SpendVoucher $voucher): ?int
    {
        if ($voucher->type === 'retirement') {
            return $this->accountByCode('1300');
        }

        $creditId = $voucher->paymentSource?->gl_account_id;

        return $creditId && ChartOfAccount::postable()->whereKey($creditId)->exists()
            ? (int) $creditId
            : null;
    }

    /**
     * Payment and reimbursement are the only voucher types a voucher can be
     * created with (see SpendVoucherController::store()), and both settle
     * verified liabilities via allocations — nothing else reaches this method.
     * advance/refund/top_up/retirement/reversal treatments used to live here
     * but were never reachable: advance had no reconciliation of its own
     * (Cash Requisition owns "cash before spending" now), and the rest were
     * already excluded from voucher creation with no other caller in the
     * codebase. Removed rather than left as dead branches.
     *
     * @return array<int, array{account_id:int, amount:string, description:string}>
     */
    private function voucherDebitLegs(SpendVoucher $voucher, string $voucherAmount): array
    {
        if (! in_array($voucher->type, ['payment', 'reimbursement'], true)) {
            throw new InvalidArgumentException(
                "Spend voucher {$voucher->voucher_no} has no supported ledger treatment for type {$voucher->type}."
            );
        }

        $allocations = SpendVoucherAllocation::query()->where('spend_voucher_id', $voucher->id)
            ->lockForUpdate()->with('costLine.journalEntry.lines')->get();

        if ($allocations->isEmpty()) {
            throw new InvalidArgumentException("Spend voucher {$voucher->voucher_no} has no verified liabilities allocated to it.");
        }

        $byAccount = [];
        foreach ($allocations as $allocation) {
            $amount = (string) $allocation->amount;
            $accountId = $this->resolveVerifiedLiabilityAccount(
                $allocation->costLine,
                $amount,
                "Spend voucher {$voucher->voucher_no}"
            );
            $byAccount[$accountId] = bcadd($byAccount[$accountId] ?? '0.00', $amount, 2);
        }

        $allocated = array_reduce($byAccount, fn (string $sum, string $value) => bcadd($sum, $value, 2), '0.00');
        if (bccomp($allocated, $voucherAmount, 2) !== 0) {
            throw new InvalidArgumentException(
                "Spend voucher {$voucher->voucher_no} amount {$voucherAmount} does not equal allocated liabilities {$allocated}."
            );
        }

        return collect($byAccount)->map(fn (string $value, int $accountId) => [
            'account_id' => $accountId,
            'amount' => $value,
            'description' => 'Liability settlement for '.$voucher->voucher_no,
        ])->values()->all();
    }

    /**
     * Resolve and validate the single control account a verified cost line's
     * liability must be debited from, to settle it for the given amount.
     *
     * Reads the credit leg(s) the cost line's own posted journal entry
     * recorded when its liability was recognized, rather than guessing from
     * the cost line's `nature` — that is the only way to be sure the
     * settlement debits the exact account that was originally credited.
     *
     * Two guarantees this must keep, shared by every caller that settles a
     * cost line's liability (spend vouchers and direct payment allocations
     * alike), because a control account picked without them can silently
     * post to the wrong side of the ledger:
     *
     * - The liability legs found must reconcile to
     *   `net_amount + tax_amount - wht_amount`. VAT-payable and WHT-payable
     *   are liability-typed accounts too, so a cost line with a split
     *   liability posting (AP + VAT + WHT legs) that isn't reconciled first
     *   could have any one of those picked instead of the AP/Accrued leg.
     * - Exactly one control account may hold that liability. A cost line
     *   whose liability spans more than one needs an explicit allocation
     *   policy, not a guess at which leg to debit.
     */
    private function resolveVerifiedLiabilityAccount(CostLine $costLine, string $allocationAmount, string $context): int
    {
        $controlAccounts = ChartOfAccount::postable()
            ->whereIn('code', ChartAccountMap::localMany([self::PAYABLE_CODE, self::ACCRUED_CODE]))
            ->pluck('id')->map(fn ($id) => (int) $id);

        if ($costLine->status !== CostLine::STATUS_VERIFIED || $costLine->journalEntry?->status !== 'posted') {
            throw new InvalidArgumentException("{$context}: cost line {$costLine->ref} is no longer a posted, verified liability.");
        }

        // Last line of defense: even if something upstream let a Bill-cleared
        // GRN accrual reach here (a stale allocation created before this guard
        // existed, a future caller that skips assertEligibleLiabilities), the
        // actual debit must never happen. This is the one method every
        // liability-settling path shares — voucherDebitLegs() today,
        // UnifiedPaymentService::postPayment() when it's ever wired up.
        if ($costLine->settled_by_bill_id !== null) {
            throw new InvalidArgumentException(
                "{$context}: cost line {$costLine->ref} was already settled when bill #{$costLine->settled_by_bill_id} "
                .'was verified against the goods receipt — it is no longer a payable liability.'
            );
        }

        $liabilityLines = $costLine->journalEntry->lines->filter(
            fn (JournalLine $line) => $line->entry_type === 'credit' && $controlAccounts->contains((int) $line->account_id)
        );
        $journalLiability = $liabilityLines->reduce(
            fn (string $sum, JournalLine $line) => bcadd($sum, (string) $line->amount, 2),
            '0.00'
        );
        $expected = bcsub(
            bcadd((string) ($costLine->net_amount ?? 0), (string) ($costLine->tax_amount ?? 0), 2),
            (string) ($costLine->wht_amount ?? 0),
            2
        );
        if (bccomp($journalLiability, $expected, 2) !== 0) {
            throw new InvalidArgumentException("{$context}: cost line {$costLine->ref} does not reconcile to its payable journal.");
        }
        if ($liabilityLines->pluck('account_id')->unique()->count() !== 1) {
            throw new InvalidArgumentException("{$context}: cost line {$costLine->ref} spans multiple payable control accounts and needs an explicit allocation policy.");
        }

        if (bccomp($allocationAmount, '0.00', 2) !== 1 || bccomp($allocationAmount, $expected, 2) === 1) {
            throw new InvalidArgumentException("{$context}: allocation on {$costLine->ref} is outside its payable balance.");
        }

        return (int) $liabilityLines->first()->account_id;
    }

    /**
     * The supplier rail: what an invoice does to the books, and what paying it does.
     *
     * Goods receipt already posted Dr Raw-material Inventory / Cr Accrued
     * Expenses — the company holds the stock and owes for it. Nothing then
     * moved that liability from "accrued" to "owed to a named supplier on an
     * invoice", and nothing relieved it when the supplier was paid. So 2150
     * Accrued Expenses only ever grew, 2100 Accounts Payable was never credited
     * by any workflow despite being seeded and referenced, and no supplier
     * payment ever credited a bank or a float. There was no creditors ledger to
     * age and no cash movement to reconcile.
     *
     * Two entries close it:
     *
     *   On verification   Dr 2150 Accrued Expenses  /  Cr 2100 Accounts Payable
     *   On payment        Dr 2100 Accounts Payable  /  Cr <payment source>
     *
     * The invoice entry carries the tax, because the invoice is where tax
     * becomes claimable. Up to four legs, the same shape a cost line posts:
     *
     *   Dr  Accrued Expenses      net          (the receipt's liability, cleared)
     *   Dr  Input VAT recoverable vat          (recoverable treatments only)
     *   Cr  WHT payable           wht          (retained, owed to KRA)
     *   Cr  Accounts Payable      net+vat−wht  (what the supplier is actually owed)
     *
     * Invoices recorded before `bills` could state tax carry net = amount and
     * zero for both taxes, so they still post as the two-leg entry they always
     * did rather than being retrospectively reinterpreted.
     *
     * A direct bill (`verification_basis=direct`, no purchase order — a
     * credit purchase that never went through Requisition→PO→GRN) has no
     * receipt accrual to clear, so the first leg debits its own expense
     * classification instead of 2150:
     *
     *   Dr  <bill's expense code account>  net
     *   Dr  Input VAT recoverable          vat  (recoverable treatments only)
     *   Cr  WHT payable                    wht  (retained, owed to KRA)
     *   Cr  Accounts Payable               net+vat−wht
     */
    public function postSupplierInvoice(Bill $bill): ?JournalEntry
    {
        $entryNo = 'JE-BILL-'.str_pad((string) $bill->id, 7, '0', STR_PAD_LEFT);

        if ($existing = JournalEntry::where('entry_no', $entryNo)->first()) {
            return $existing;
        }

        /*
         * Only a matched invoice clears an accrual, because only a matched
         * invoice is guaranteed to have one. The three-way match caps the
         * invoice at the value Stores accepted, and that acceptance is what
         * credited 2150 — so the debit can never exceed what the receipt put
         * there. A `legacy` invoice predates the match and was never accrued;
         * debiting 2150 for it would relieve a liability no receipt ever
         * recorded and drive the control account negative. A `direct` invoice
         * (no purchase order at all) never had a receipt to accrue either, so
         * it debits its own expense classification instead of 2150 — see
         * below.
         */
        if (! in_array($bill->verification_basis, ['three_way_match', 'direct'], true) || ! $bill->verified_at) {
            return null;
        }

        $gross = $this->money($bill->amount);
        if (bccomp($gross, '0.00', 2) <= 0) {
            return null;
        }

        $period = AccountingPeriod::forDate($bill->bill_date ?? now());
        $this->assertOpenPeriod($period?->id, "supplier invoice {$bill->bill_number}");

        if ($bill->isDirect()) {
            $debitAccountId = $bill->expenseCode?->default_debit_account_id;
            if (! $debitAccountId) {
                throw new InvalidArgumentException(
                    "Supplier invoice {$bill->bill_number} cannot post: its expense code has no default debit account mapped."
                );
            }
        } else {
            $debitAccountId = $this->accountByCode(self::ACCRUED_CODE);
            if (! $debitAccountId) {
                throw new InvalidArgumentException(
                    "Supplier invoice {$bill->bill_number} cannot post: chart account ".self::ACCRUED_CODE.' must be active and postable.'
                );
            }
        }

        $payable = $this->accountByCode(self::PAYABLE_CODE);
        if (! $payable) {
            throw new InvalidArgumentException(
                "Supplier invoice {$bill->bill_number} cannot post: chart account ".self::PAYABLE_CODE.' must be active and postable.'
            );
        }

        $legs = $this->supplierInvoiceLegs($bill, $gross, $debitAccountId, $payable);

        $accountIds = array_unique(array_column($legs, 'account_id'));
        if (ChartOfAccount::postable()->whereIn('id', $accountIds)->count() !== count($accountIds)) {
            throw new InvalidArgumentException(
                "Supplier invoice {$bill->bill_number} resolves to an inactive or non-postable account. "
                .'Finance must correct the account mapping before posting.'
            );
        }

        $requisition = $bill->purchaseOrder?->requisition;
        $projectId = $requisition?->project_id ?? $bill->project_id;
        $projectEnquiryId = $requisition?->project_enquiry_id ?? $bill->project_enquiry_id;
        $total = array_reduce(
            array_filter($legs, fn (array $leg) => $leg['entry_type'] === 'debit'),
            fn (string $carry, array $leg) => bcadd($carry, $leg['amount'], 2),
            '0.00',
        );

        return DB::transaction(function () use ($bill, $entryNo, $period, $projectId, $projectEnquiryId, $legs, $total) {
            $entry = JournalEntry::create([
                'entry_no' => $entryNo,
                'posting_date' => (string) ($bill->bill_date?->toDateString() ?? now()->toDateString()),
                'accounting_period_id' => $period->id,
                'source_type' => Bill::class,
                'source_id' => $bill->id,
                'source_ref' => $bill->bill_number,
                'description' => $bill->isDirect()
                    ? 'Direct supplier invoice '.($bill->supplier_invoice_number ?: $bill->bill_number)
                    : 'Supplier invoice '.($bill->supplier_invoice_number ?: $bill->bill_number)
                        .' accepted against '.($bill->purchaseOrder?->po_number ?? 'order'),
                'total_debit' => $total,
                'total_credit' => $total,
                'status' => 'posted',
                'created_by' => $bill->verified_by ?? auth()->id(),
                'posted_at' => now(),
            ]);

            foreach ($legs as $leg) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'currency' => 'KES',
                    'fx_rate' => 1,
                    'base_amount' => $leg['amount'],
                    'project_id' => $projectId,
                    'project_enquiry_id' => $projectEnquiryId,
                    ...$leg,
                ]);
            }

            $this->markGrnAccrualsSettledByBill($bill);

            return $entry;
        });
    }

    /**
     * Close the GRN accrual(s) this bill's three-way match just cleared.
     *
     * The debit above relieves 2150 in aggregate, but nothing else ties it
     * back to the specific cost line(s) that credited it at goods receipt —
     * without this, those lines stayed "verified, posted, unsettled" forever
     * and kept showing up as payable liabilities in eligibleLiabilities()
     * long after this bill had paid them. Confirmed to happen once already:
     * CL-0000022 / BILL-2026-0003 was paid in full, then paid an extra
     * KES 2,000 the next day via a Payment Voucher that had no way to know.
     */
    private function markGrnAccrualsSettledByBill(Bill $bill): void
    {
        $poItemIds = PurchaseOrderItem::where('purchase_order_id', $bill->purchase_order_id)->pluck('id');

        if ($poItemIds->isEmpty()) {
            return;
        }

        CostLine::where('source_type', GoodsReceiptNoteItem::class)
            ->where('source_ref', 'accrual')
            ->whereIn('details->purchase_order_item_id', $poItemIds)
            ->whereNull('settled_by_bill_id')
            ->update(['settled_by_bill_id' => $bill->id]);
    }

    /**
     * The legs of one supplier invoice, in posting order.
     *
     * The credit to Accounts Payable is the balancing figure rather than an
     * independently computed one, for the same reason a cost line's is: an
     * invoice whose stated net and VAT do not quite add to its gross still
     * produces a balanced entry, and the discrepancy shows up as a payable that
     * disagrees with the document rather than as a journal that will not post.
     *
     * @return array<int, array<string, mixed>>
     */
    private function supplierInvoiceLegs(Bill $bill, string $gross, int $debitAccountId, int $payable): array
    {
        $vat = $this->money($bill->vat_amount);
        $wht = $this->money($bill->wht_amount);
        $net = bcsub($gross, $vat, 2);

        // Only a recoverable treatment reaches the VAT account. Exempt,
        // out-of-scope and explicitly non-recoverable tax stays in the cost of
        // the goods, which is where the accrual already put it.
        $recoverable = $bill->vatTreatment?->is_recoverable
            && bccomp($vat, '0.00', 2) > 0;

        if (! $recoverable) {
            $net = $gross;
            $vat = '0.00';
        }

        if (bccomp($wht, $gross, 2) > 0) {
            throw new InvalidArgumentException(
                "Withholding of {$wht} exceeds the value of invoice {$bill->bill_number}."
            );
        }

        $legs = [[
            'account_id' => $debitAccountId,
            'entry_type' => 'debit',
            'amount' => $net,
            'description' => $bill->isDirect()
                ? 'Expense recognized on direct supplier invoice '.$bill->bill_number
                : 'Accrual cleared by supplier invoice '.$bill->bill_number,
        ]];

        if (bccomp($vat, '0.00', 2) > 0) {
            $legs[] = [
                'account_id' => $bill->vatTreatment?->gl_account_id
                    ?: $this->accountByCode(self::VAT_INPUT_CODE),
                'entry_type' => 'debit',
                'amount' => $vat,
                'description' => 'Recoverable input VAT on '.$bill->bill_number,
            ];
        }

        if (bccomp($wht, '0.00', 2) > 0) {
            $legs[] = [
                'account_id' => $bill->whtCategory?->gl_account_id
                    ?: $this->accountByCode(self::WHT_PAYABLE_CODE),
                'entry_type' => 'credit',
                'amount' => $wht,
                'description' => 'Withholding tax retained on '.$bill->bill_number,
            ];
        }

        $legs[] = [
            'account_id' => $payable,
            'entry_type' => 'credit',
            'amount' => bcsub(bcadd($net, $vat, 2), $wht, 2),
            'description' => 'Owed to '.($bill->supplier?->supplier_name ?? 'supplier'),
        ];

        return $legs;
    }

    /**
     * Cash (or float) leaving against a supplier invoice.
     *
     * Conditioned on the invoice having posted, so 2100 is only ever debited by
     * a payment against an invoice that credited it. Without that condition a
     * legacy invoice — payable, but never posted — would relieve a payable it
     * never raised, and the control account would drift by exactly the value of
     * the grandfathered balances the legacy basis exists to let through.
     *
     * @deprecated Use postPayment() with the BillPayment's unified Payment instead.
     *             This method will be removed in a future version after
     *             all bill payments migrate to the unified payment architecture.
     */
    public function postSupplierPayment(BillPayment $payment): ?JournalEntry
    {
        $entryNo = 'JE-BPAY-'.str_pad((string) $payment->id, 7, '0', STR_PAD_LEFT);

        if ($existing = JournalEntry::where('entry_no', $entryNo)->first()) {
            return $existing;
        }

        $bill = $payment->bill ?: Bill::find($payment->bill_id);
        if (! $bill) {
            return null;
        }

        $invoiceEntry = JournalEntry::where('entry_no', 'JE-BILL-'.str_pad((string) $bill->id, 7, '0', STR_PAD_LEFT))->first();
        if (! $invoiceEntry) {
            return null;
        }

        $amount = $this->money($payment->amount_paid);
        if (bccomp($amount, '0.00', 2) <= 0) {
            return null;
        }

        $period = AccountingPeriod::forDate($payment->payment_date ?? now());
        $this->assertOpenPeriod($period?->id, "supplier payment {$payment->payment_code}");

        $source = $payment->payment_source_id ? PaymentSource::find($payment->payment_source_id) : null;
        $payable = $this->accountByCode(self::PAYABLE_CODE);
        if (! $payable) {
            throw new InvalidArgumentException(
                'Supplier payment cannot post: chart account '.self::PAYABLE_CODE.' must be postable.'
            );
        }

        $requisition = $bill->purchaseOrder?->requisition;

        return $this->postCashSettlement(
            entryNo: $entryNo,
            postingDate: (string) ($payment->payment_date?->toDateString() ?? now()->toDateString()),
            sourceType: BillPayment::class,
            sourceId: $payment->id,
            sourceRef: $payment->payment_code,
            description: 'Payment '.$payment->payment_code.' against invoice '.$bill->bill_number,
            debitLegs: [[
                'account_id' => $payable,
                'amount' => $amount,
                'description' => 'Settled '.$bill->bill_number.' for '
                    .($bill->supplier?->supplier_name ?? 'supplier'),
                'project_id' => $requisition?->project_id,
                'project_enquiry_id' => $requisition?->project_enquiry_id,
            ]],
            paymentSource: $source,
            creditDescription: 'Cash/float outflow for '.$bill->bill_number,
            createdBy: $payment->user_id,
            accountingPeriodId: $period->id,
            creditProjectId: $requisition?->project_id,
            creditProjectEnquiryId: $requisition?->project_enquiry_id,
        );
    }

    /**
     * One accounting policy for cash leaving against an approved obligation.
     *
     * @param  array<int, array<string, mixed>>  $debitLegs
     */
    public function postCashSettlement(
        string $entryNo,
        string $postingDate,
        string $sourceType,
        int $sourceId,
        ?string $sourceRef,
        string $description,
        array $debitLegs,
        ?PaymentSource $paymentSource,
        string $creditDescription,
        ?int $createdBy = null,
        ?int $accountingPeriodId = null,
        ?int $spendVoucherId = null,
        string $currency = 'KES',
        string $fxRate = '1',
        ?string $creditBaseAmount = null,
        mixed $creditProjectId = null,
        mixed $creditProjectEnquiryId = null,
    ): JournalEntry {
        if (! $paymentSource || $paymentSource->type === 'payable' || ! $paymentSource->is_active) {
            throw new InvalidArgumentException(
                "{$sourceRef} cannot post: Supplier Credit and inactive sources are not paying accounts."
            );
        }

        $creditAccountId = $paymentSource->gl_account_id;
        if (! $creditAccountId || ! ChartOfAccount::postable()->whereKey($creditAccountId)->exists()) {
            throw new InvalidArgumentException("{$sourceRef} cannot post: its paying account needs an active, postable GL account.");
        }

        $total = array_reduce(
            $debitLegs,
            fn (string $sum, array $leg): string => bcadd($sum, $this->money($leg['amount'] ?? 0), 2),
            '0.00',
        );
        $legs = array_map(fn (array $leg): array => [
            ...$leg,
            'entry_type' => 'debit',
            'currency' => $leg['currency'] ?? $currency,
            'fx_rate' => $leg['fx_rate'] ?? $fxRate,
        ], $debitLegs);
        $legs[] = [
            'account_id' => (int) $creditAccountId,
            'entry_type' => 'credit',
            'amount' => $total,
            'currency' => $currency,
            'fx_rate' => $fxRate,
            'base_amount' => $creditBaseAmount ?? $total,
            'description' => $creditDescription,
            'project_id' => $creditProjectId,
            'project_enquiry_id' => $creditProjectEnquiryId,
        ];

        return $this->postBalancedEntry(
            entryNo: $entryNo,
            postingDate: $postingDate,
            sourceType: $sourceType,
            sourceId: $sourceId,
            sourceRef: $sourceRef,
            description: $description,
            legs: $legs,
            createdBy: $createdBy,
            accountingPeriodId: $accountingPeriodId,
            spendVoucherId: $spendVoucherId,
        );
    }

    /**
     * The fee a bank, card or mobile-money operator charged us to move money.
     *
     *   Dr  7800 Bank & Mobile-money Charges   fee
     *   Cr  <the account the money left>       fee
     *
     * Posted from the payment rather than from what the payment settled, because
     * one payment carries one fee however many invoices or requisition lines it
     * discharges. Keyed on the payment's id, so a retry or a backfill re-posts
     * nothing.
     *
     * **Deliberately carries no project or enquiry.** A transfer charge is what
     * it costs WNG to operate a bank account, not what an event cost to produce;
     * two jobs paid on one M-Pesa transfer would otherwise have to split a fee
     * that neither of them caused. It was previously posted as a job cost line
     * under OE-FIN-001 — and only when the payment had a job number and was not
     * a supplier settlement, so most fees reached no ledger at all.
     */
    public function postPaymentFee(Payment $payment): ?JournalEntry
    {
        $fee = $this->money($payment->transaction_cost);
        if (bccomp($fee, '0.00', 2) <= 0) {
            return null;
        }

        // A void reverses the whole cash movement, fee included. Posting the
        // charge anyway would leave an expense behind for money that came back.
        if ($payment->status !== 'active') {
            return null;
        }

        $charges = $this->accountByCode(self::BANK_CHARGES_CODE);
        $sourceAccount = $payment->payment_source_id
            ? PaymentSource::whereKey($payment->payment_source_id)->value('gl_account_id')
            : null;

        if (! $charges || ! $sourceAccount) {
            throw new InvalidArgumentException(
                "Payment {$payment->payment_no} carries a transaction fee that cannot post: chart account "
                .self::BANK_CHARGES_CODE.' must be postable, and the paying account needs a GL account.'
            );
        }

        $reference = $payment->payment_no ?: (string) $payment->id;

        return $this->postBalancedEntry(
            entryNo: 'JE-PFEE-'.str_pad((string) $payment->id, 7, '0', STR_PAD_LEFT),
            postingDate: (string) ($payment->date_disbursed?->toDateString() ?? $payment->created_at?->toDateString() ?? now()->toDateString()),
            sourceType: Payment::class,
            sourceId: $payment->id,
            sourceRef: $reference,
            description: 'Transaction fee on payment '.$reference,
            legs: [
                [
                    'account_id' => $charges,
                    'entry_type' => 'debit',
                    'amount' => $fee,
                    'description' => 'Transfer charge on '.$reference,
                ],
                [
                    'account_id' => (int) $sourceAccount,
                    'entry_type' => 'credit',
                    'amount' => $fee,
                    'description' => 'Fee deducted from '.($payment->paymentSource?->name ?? 'paying account'),
                ],
            ],
            createdBy: $payment->created_by,
        );
    }

    /**
     * Post a payment that has no project cost line behind it.
     *
     * Project payments are posted by the cost collector so their journal keeps
     * the project dimensions. Overhead and unmatched payments have no cost
     * line, but they still moved money and must not disappear from the GL.
     *
     * @deprecated Use postPayment() instead, which handles both allocated and unallocated payments.
     *             This method will be removed in a future version.
     */
    public function postDirectPayment(Payment $payment): ?JournalEntry
    {
        if ($payment->status !== 'active') {
            return null;
        }

        $entryNo = 'JE-PAY-'.str_pad((string) $payment->id, 7, '0', STR_PAD_LEFT);
        if ($existing = JournalEntry::where('entry_no', $entryNo)->first()) {
            return $existing;
        }

        $expenseAccount = $payment->expenseCode?->default_debit_account_id;
        $sourceAccount = $payment->payment_source_id
            ? PaymentSource::whereKey($payment->payment_source_id)->value('gl_account_id')
            : null;

        if (! $expenseAccount || ! $sourceAccount) {
            throw new InvalidArgumentException(
                "Payment {$payment->payment_no} cannot post: its expense code and paying source must map to postable GL accounts."
            );
        }

        $amount = $this->money($payment->amount);
        if (bccomp($amount, '0.00', 2) <= 0) {
            return null;
        }

        return $this->postBalancedEntry(
            entryNo: $entryNo,
            postingDate: (string) ($payment->date_disbursed?->toDateString() ?? now()->toDateString()),
            sourceType: Payment::class,
            sourceId: $payment->id,
            sourceRef: $payment->payment_no ?: (string) $payment->id,
            description: 'Direct payment '.($payment->payment_no ?: $payment->id),
            legs: [
                [
                    'account_id' => (int) $expenseAccount,
                    'entry_type' => 'debit',
                    'amount' => $amount,
                    'description' => $payment->description ?: 'Direct payment expense',
                    'project_id' => $payment->project_id,
                    'project_enquiry_id' => $payment->project_enquiry_id,
                ],
                [
                    'account_id' => (int) $sourceAccount,
                    'entry_type' => 'credit',
                    'amount' => $amount,
                    'description' => 'Payment from '.($payment->paymentSource?->name ?? 'paying account'),
                ],
            ],
            createdBy: $payment->created_by,
        );
    }

    /**
     * Write one balanced entry with any number of legs.
     *
     * The single funnel. Producers decide WHICH accounts an event hits and for
     * how much — that is genuinely their business knowledge — and then hand the
     * legs here, where the rules that must hold for every entry regardless of
     * origin are applied in one place:
     *
     *   1. the month must be open
     *   2. every account must exist, be active and be postable
     *   3. debits must equal credits
     *   4. the same `entry_no` must never produce two entries
     *
     * This exists because HR was writing payroll journals directly against the
     * models, with its own copy of rules 1–3 and no copy of rule 4. Two writers
     * with two rule sets is how the petty-cash board-request path produced a
     * second, unreconciled ledger, and the fix there was the same as the fix
     * here: leave one door.
     *
     * @param  array<int, array<string, mixed>>  $legs  each with account_id,
     *                                                  entry_type ('debit'|'credit'), amount, and optionally description,
     *                                                  project_id, project_enquiry_id, cost_centre_id, activity_id
     */
    public function postBalancedEntry(
        string $entryNo,
        string $postingDate,
        string $sourceType,
        int $sourceId,
        ?string $sourceRef,
        string $description,
        array $legs,
        ?int $createdBy = null,
        ?int $accountingPeriodId = null,
        ?int $spendVoucherId = null,
    ): JournalEntry {
        if ($existing = JournalEntry::where('entry_no', $entryNo)->first()) {
            return $existing;
        }

        if ($legs === []) {
            throw new InvalidArgumentException("Journal entry {$entryNo} has no lines.");
        }

        $period = $accountingPeriodId
            ? AccountingPeriod::query()->find($accountingPeriodId)
            : AccountingPeriod::forDate(Carbon::parse($postingDate));
        $this->assertOpenPeriod($period?->id, $entryNo);

        $debit = '0.00';
        $credit = '0.00';

        foreach ($legs as $leg) {
            $amount = $this->money($leg['amount']);

            if (bccomp($amount, '0.00', 2) < 0) {
                throw new InvalidArgumentException(
                    "Journal entry {$entryNo} carries a negative amount. Swap the leg instead of negating it."
                );
            }

            if ($leg['entry_type'] === 'debit') {
                $debit = bcadd($debit, $amount, 2);
            } else {
                $credit = bcadd($credit, $amount, 2);
            }
        }

        if (bccomp($debit, $credit, 2) !== 0) {
            throw new InvalidArgumentException(
                "Journal entry {$entryNo} does not balance: debit {$debit} against credit {$credit}."
            );
        }

        // Checked as a set rather than per leg, so one query answers for the
        // whole entry and the error names the entry rather than a single line.
        $accountIds = array_values(array_unique(array_column($legs, 'account_id')));
        if (ChartOfAccount::postable()->whereIn('id', $accountIds)->count() !== count($accountIds)) {
            throw new InvalidArgumentException(
                "Journal entry {$entryNo} resolves to an inactive or non-postable account. "
                .'Finance must correct the account mapping before posting.'
            );
        }

        return DB::transaction(function () use (
            $entryNo, $postingDate, $period, $sourceType, $sourceId, $sourceRef, $description, $legs, $debit, $createdBy, $spendVoucherId
        ) {
            $entry = JournalEntry::create([
                'entry_no' => $entryNo,
                'posting_date' => $postingDate,
                'accounting_period_id' => $period->id,
                'spend_voucher_id' => $spendVoucherId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_ref' => $sourceRef,
                'description' => $description,
                'total_debit' => $debit,
                'total_credit' => $debit,
                'status' => 'posted',
                'created_by' => $createdBy ?? auth()->id(),
                'posted_at' => now(),
            ]);

            foreach ($legs as $leg) {
                $amount = $this->money($leg['amount']);

                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $leg['account_id'],
                    'entry_type' => $leg['entry_type'],
                    'amount' => $amount,
                    'base_amount' => $leg['base_amount'] ?? $amount,
                    'currency' => $leg['currency'] ?? 'KES',
                    'fx_rate' => $leg['fx_rate'] ?? 1,
                    'description' => $leg['description'] ?? null,
                    'cost_centre_id' => $leg['cost_centre_id'] ?? null,
                    'activity_id' => $leg['activity_id'] ?? null,
                    'project_id' => $leg['project_id'] ?? null,
                    'project_enquiry_id' => $leg['project_enquiry_id'] ?? null,
                ]);
            }

            return $entry;
        });
    }

    /**
     * A two-leg entry with its lines. Shared by the invoice and the payment so
     * the pair cannot drift apart in how they stamp a period, a source or a
     * dimension.
     */
    private function writeEntry(
        string $entryNo,
        string $postingDate,
        int $periodId,
        string $sourceType,
        int $sourceId,
        ?string $sourceRef,
        string $description,
        string $amount,
        int $debitAccountId,
        int $creditAccountId,
        string $debitDescription,
        string $creditDescription,
        ?int $createdBy,
        mixed $projectId = null,
        mixed $projectEnquiryId = null,
    ): JournalEntry {
        $entry = JournalEntry::create([
            'entry_no' => $entryNo,
            'posting_date' => $postingDate,
            'accounting_period_id' => $periodId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_ref' => $sourceRef,
            'description' => $description,
            'total_debit' => $amount,
            'total_credit' => $amount,
            'status' => 'posted',
            'created_by' => $createdBy ?? auth()->id(),
            'posted_at' => now(),
        ]);

        foreach ([
            ['account_id' => $debitAccountId, 'entry_type' => 'debit', 'description' => $debitDescription],
            ['account_id' => $creditAccountId, 'entry_type' => 'credit', 'description' => $creditDescription],
        ] as $leg) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'amount' => $amount,
                'currency' => 'KES',
                'fx_rate' => 1,
                'base_amount' => $amount,
                'project_id' => $projectId,
                'project_enquiry_id' => $projectEnquiryId,
                ...$leg,
            ]);
        }

        return $entry;
    }

    /**
     * Create a balanced GL journal entry when a petty cash requisition is disbursed as an advance float.
     *
     *   Dr  1300 Staff Advances / Imprest      disbursement amount
     *   Cr  Payment Source / Cash Float        disbursement amount
     */
    public function postPettyCashAdvance(Payment $disbursement): ?JournalEntry
    {
        $amount = $this->money($disbursement->amount);
        if (bccomp($amount, '0.00', 2) <= 0 || $disbursement->status !== 'active') {
            return null;
        }

        $advanceAccount = $this->accountByCode(self::STAFF_ADVANCE_CODE);
        $sourceAccount = $disbursement->payment_source_id
            ? PaymentSource::whereKey($disbursement->payment_source_id)->value('gl_account_id')
            : null;
        $sourceAccount ??= ChartOfAccount::postable()->where('category', 'asset')->where('code', '1010')->value('id');

        if (! $advanceAccount || ! $sourceAccount) {
            throw new InvalidArgumentException(
                "Disbursement {$disbursement->id} cannot post advance: chart account "
                .self::STAFF_ADVANCE_CODE.' must be postable, and the paying source needs a postable GL account.'
            );
        }

        $reference = $disbursement->requisition?->requisition_number ?: ($disbursement->payment_no ?: (string) $disbursement->id);
        $entryNo = 'JE-PCA-'.str_pad((string) $disbursement->id, 7, '0', STR_PAD_LEFT);

        return $this->postBalancedEntry(
            entryNo: $entryNo,
            postingDate: (string) ($disbursement->date_disbursed?->toDateString() ?? $disbursement->created_at?->toDateString() ?? now()->toDateString()),
            sourceType: Payment::class,
            sourceId: $disbursement->id,
            sourceRef: $reference,
            description: "Staff advance float for requisition {$reference} to ".($disbursement->payee_name ?? 'Requester'),
            legs: [
                [
                    'account_id' => (int) $advanceAccount,
                    'entry_type' => 'debit',
                    'amount' => $amount,
                    'description' => 'Staff advance float: '.($disbursement->payee_name ?? 'Requester'),
                    'project_id' => $disbursement->project_id,
                    'project_enquiry_id' => $disbursement->project_enquiry_id,
                ],
                [
                    'account_id' => (int) $sourceAccount,
                    'entry_type' => 'credit',
                    'amount' => $amount,
                    'description' => 'Disbursed from '.($disbursement->paymentSource?->name ?? 'Cash Float'),
                    'project_id' => $disbursement->project_id,
                    'project_enquiry_id' => $disbursement->project_enquiry_id,
                ],
            ],
            createdBy: $disbursement->created_by,
        );
    }

    /**
     * Create a balanced clearing GL journal entry when a petty cash requisition is surrendered and reconciled.
     *
     *   Dr  Expense / WIP (net)             per verified receipt item
     *   Dr  Input VAT 1330 (if ETR/eTIMS)   per verified receipt item
     *   Dr  Payment Source / Cash Float     cash change returned
     *   Cr  1300 Staff Advances             advance cleared (up to advance amount)
     *   Cr  Payment Source / Cash Float     reimbursement for overspend (if any)
     */
    public function postPettyCashSurrender(PettyCashRequisition $requisition): ?JournalEntry
    {
        $requisition->loadMissing(['surrenderItems.expenseCode', 'disbursement.paymentSource']);

        $entryNo = 'JE-PCS-'.str_pad((string) $requisition->id, 7, '0', STR_PAD_LEFT);
        if ($existing = JournalEntry::where('entry_no', $entryNo)->first()) {
            return $existing;
        }

        $advanceAccount = $this->accountByCode(self::STAFF_ADVANCE_CODE);
        $sourceAccount = $requisition->disbursement?->payment_source_id
            ? PaymentSource::whereKey($requisition->disbursement->payment_source_id)->value('gl_account_id')
            : null;
        $sourceAccount ??= ChartOfAccount::postable()->where('category', 'asset')->where('code', '1010')->value('id');

        if (! $advanceAccount || ! $sourceAccount) {
            throw new InvalidArgumentException(
                "Requisition {$requisition->requisition_number} cannot reconcile surrender: chart account "
                .self::STAFF_ADVANCE_CODE.' must be postable, and the paying source needs a postable GL account.'
            );
        }

        $legs = [];
        $totalSpent = '0.00';

        foreach ($requisition->surrenderItems as $item) {
            $tax = $this->money($item->tax_amount ?: 0);
            $net = $item->net_amount ? $this->money($item->net_amount) : bcsub($this->money($item->amount), $tax, 2);
            $gross = bcadd($net, $tax, 2);
            $totalSpent = bcadd($totalSpent, $gross, 2);

            $debitId = $item->expenseCode?->default_debit_account_id
                ?: ChartOfAccount::postable()->where('category', 'expense')->orderBy('code')->value('id');

            if (bccomp($net, '0.00', 2) === 1 && $debitId) {
                $legs[] = [
                    'account_id' => (int) $debitId,
                    'entry_type' => 'debit',
                    'amount' => $net,
                    'description' => $item->description ?: "Receipt {$item->receipt_number} on {$requisition->requisition_number}",
                    'project_id' => $requisition->project_id,
                    'project_enquiry_id' => $requisition->enquiry_id,
                ];
            }

            if (bccomp($tax, '0.00', 2) === 1 && $item->receipt_type === 'etr') {
                $vatInputId = $this->accountByCode(self::VAT_INPUT_CODE);
                if ($vatInputId) {
                    $legs[] = [
                        'account_id' => (int) $vatInputId,
                        'entry_type' => 'debit',
                        'amount' => $tax,
                        'description' => "Input VAT on receipt {$item->receipt_number} (PIN: {$item->supplier_kra_pin})",
                        'project_id' => $requisition->project_id,
                        'project_enquiry_id' => $requisition->enquiry_id,
                    ];
                }
            }
        }

        $cashReturned = $this->money($requisition->cash_returned_amount ?? 0);
        if (bccomp($cashReturned, '0.00', 2) === 1) {
            $legs[] = [
                'account_id' => (int) $sourceAccount,
                'entry_type' => 'debit',
                'amount' => $cashReturned,
                'description' => "Cash change returned on {$requisition->requisition_number}",
                'project_id' => $requisition->project_id,
                'project_enquiry_id' => $requisition->enquiry_id,
            ];
        }

        // Total accounted is total expenses + cash change returned
        $totalAccounted = bcadd($totalSpent, $cashReturned, 2);
        $advanceAmount = $this->money($requisition->total_amount);

        // Advance cleared cannot exceed the original advance amount
        $advanceCleared = bccomp($totalAccounted, $advanceAmount, 2) === 1
            ? $advanceAmount
            : $totalAccounted;

        if (bccomp($advanceCleared, '0.00', 2) === 1) {
            $legs[] = [
                'account_id' => (int) $advanceAccount,
                'entry_type' => 'credit',
                'amount' => $advanceCleared,
                'description' => "Clear staff advance on {$requisition->requisition_number}",
                'project_id' => $requisition->project_id,
                'project_enquiry_id' => $requisition->enquiry_id,
            ];
        }

        // If employee overspent, company owes / paid reimbursement
        $overspent = bcsub($totalAccounted, $advanceAmount, 2);
        if (bccomp($overspent, '0.00', 2) === 1) {
            $legs[] = [
                'account_id' => (int) $sourceAccount,
                'entry_type' => 'credit',
                'amount' => $overspent,
                'description' => "Reimbursement for out-of-pocket overspend on {$requisition->requisition_number}",
                'project_id' => $requisition->project_id,
                'project_enquiry_id' => $requisition->enquiry_id,
            ];
        }

        $postingDate = (string) ($requisition->surrender_reconciled_at?->toDateString() ?? now()->toDateString());

        return $this->postBalancedEntry(
            entryNo: $entryNo,
            postingDate: $postingDate,
            sourceType: PettyCashRequisition::class,
            sourceId: $requisition->id,
            sourceRef: $requisition->requisition_number,
            description: "Surrender reconciliation: {$requisition->requisition_number} ({$requisition->purpose})",
            legs: $legs,
            createdBy: $requisition->surrender_reconciled_by ?? auth()->id(),
        );
    }

    /**
     * Post a payment to the general ledger (Phase 5: Unified Payment Architecture).
     *
     * This replaces postSpendVoucher(), postSupplierPayment(), postDirectPayment()
     * with one method that handles all payment types based on allocations.
     *
     * Journal structure depends on payment allocations:
     * - Allocated to cost lines → Dr AP/Accrued (from cost line's original posting), Cr Cash
     * - Unallocated (advance) → Dr Staff Advances, Cr Cash
     * - Top-up (petty cash replenishment) → Dr Petty Cash Float, Cr Bank
     *
     * Transaction fees are posted separately via postPaymentFee().
     *
     * @param Payment $payment The payment to post
     * @return JournalEntry|null The created journal entry, or null if already posted
     * @throws InvalidArgumentException
     */
    public function postPayment(Payment $payment): ?JournalEntry
    {
        // Prevent posting voided payments
        if ($payment->status === 'voided') {
            return null;
        }

        // Prevent double-posting
        $entryNo = 'JE-PAY-'.str_pad((string) $payment->id, 7, '0', STR_PAD_LEFT);
        if ($existing = JournalEntry::where('entry_no', $entryNo)->first()) {
            return $existing;
        }

        // Validate payment amount
        $amount = $this->money($payment->amount);
        if (bccomp($amount, '0.00', 2) <= 0) {
            return null;
        }

        // Resolve accounting period
        $period = AccountingPeriod::forDate($payment->date_disbursed ?? now());
        $this->assertOpenPeriod($period?->id, "payment {$payment->payment_no}");

        // Validate payment source
        $paymentSource = $payment->paymentSource;
        if (! $paymentSource || ! $paymentSource->is_active || ! ($paymentSource->can_make_payment ?? true)) {
            throw new InvalidArgumentException(
                "Payment {$payment->payment_no} cannot post: invalid or inactive payment source."
            );
        }

        $creditAccountId = $paymentSource->gl_account_id;
        if (! $creditAccountId || ! ChartOfAccount::postable()->whereKey($creditAccountId)->exists()) {
            throw new InvalidArgumentException(
                "Payment {$payment->payment_no} cannot post: payment source has no valid GL account."
            );
        }

        return DB::transaction(function () use ($payment, $entryNo, $period, $amount) {
            $debitLegs = [];

            // Determine debit legs based on allocations
            if ($payment->paymentAllocations->isNotEmpty()) {
                // ALLOCATED PAYMENT: Debit the AP/Accrued account(s) recorded on
                // each cost line's own posted liability journal — validated and
                // reconciled by the same rule the spend-voucher settlement path
                // relies on (see resolveVerifiedLiabilityAccount), so a cost line
                // with a VAT/WHT-split liability can never have the wrong leg
                // picked here.
                $allocatedTotal = '0.00';
                foreach ($payment->paymentAllocations as $allocation) {
                    $costLine = $allocation->costLine;
                    $allocationAmount = $this->money($allocation->amount);
                    $accountId = $this->resolveVerifiedLiabilityAccount(
                        $costLine,
                        $allocationAmount,
                        "Payment {$payment->payment_no}"
                    );

                    $debitLegs[] = [
                        'account_id' => $accountId,
                        'amount' => $allocationAmount,
                        'description' => "Settlement: {$costLine->description}",
                        'project_id' => $costLine->project_id,
                        'project_enquiry_id' => $costLine->project_enquiry_id,
                        'cost_centre_id' => $costLine->cost_centre_id,
                    ];
                    $allocatedTotal = bcadd($allocatedTotal, $allocationAmount, 2);
                }

                if (bccomp($allocatedTotal, $amount, 2) !== 0) {
                    throw new InvalidArgumentException(
                        "Payment {$payment->payment_no} amount {$amount} does not equal allocated liabilities {$allocatedTotal}."
                    );
                }
            } else {
                // UNALLOCATED PAYMENT: Determine from voucher type or payment context
                $debitAccount = $this->resolveUnallocatedDebitAccount($payment);

                if (! $debitAccount) {
                    throw new InvalidArgumentException(
                        "Cannot resolve debit account for unallocated payment {$payment->payment_no}. ".
                        "Payment must either have allocations or be linked to a voucher with a type."
                    );
                }

                $debitLegs[] = [
                    'account_id' => $debitAccount->id,
                    'amount' => $amount,
                    'description' => $payment->description ?? 'Unallocated payment',
                    'project_id' => $payment->project_id,
                    'project_enquiry_id' => $payment->project_enquiry_id,
                ];
            }

            // Use postCashSettlement for consistent cash leg treatment
            return $this->postCashSettlement(
                entryNo: $entryNo,
                postingDate: (string) ($payment->date_disbursed?->toDateString() ?? now()->toDateString()),
                sourceType: Payment::class,
                sourceId: $payment->id,
                sourceRef: $payment->payment_no,
                description: $this->buildPaymentDescription($payment),
                debitLegs: $debitLegs,
                paymentSource: $payment->paymentSource,
                creditDescription: "Payment via {$payment->paymentSource->name}",
                createdBy: $payment->created_by,
                accountingPeriodId: $period->id,
                spendVoucherId: $payment->spend_voucher_id, // Legacy link
                creditProjectId: $payment->project_id,
                creditProjectEnquiryId: $payment->project_enquiry_id,
            );
        });
    }

    /**
     * Resolve debit account for unallocated payments.
     *
     * Determines the expense/asset account to debit when a payment has no
     * cost line allocations. This handles advances, top-ups, and direct payments.
     */
    private function resolveUnallocatedDebitAccount(Payment $payment): ?ChartOfAccount
    {
        // Check if payment was made via voucher
        if ($payment->voucher) {
            $accountCode = match ($payment->voucher->type) {
                'advance' => self::STAFF_ADVANCE_CODE,     // 1300 Staff Advances
                'top_up', 'replenishment' => '1030',       // Petty Cash Float
                'refund' => self::PAYABLE_CODE,            // 2100 AP (refund to supplier)
                default => null,
            };

            if ($accountCode) {
                return ChartOfAccount::postable()
                    ->where('code', $accountCode)
                    ->first();
            }
        }

        // Check source document type
        if ($payment->sourceDocument instanceof Bill) {
            return ChartOfAccount::postable()
                ->where('code', self::PAYABLE_CODE)
                ->first();
        }

        // Check if there's an expense code specified (direct payment)
        if ($payment->expense_code_id) {
            $expenseCode = \App\Modules\Finance\CostCollector\Models\ExpenseCode::find($payment->expense_code_id);
            if ($expenseCode && $expenseCode->default_debit_account_id) {
                return ChartOfAccount::find($expenseCode->default_debit_account_id);
            }
        }

        return null;
    }

    /**
     * Build descriptive text for payment journal entry.
     */
    private function buildPaymentDescription(Payment $payment): string
    {
        if ($payment->voucher) {
            return "Payment via voucher {$payment->voucher->voucher_no}: {$payment->payee_name}";
        }

        if ($payment->sourceDocument) {
            $docType = class_basename($payment->sourceDocument);
            $docRef = $payment->sourceDocument->bill_number ??
                      $payment->sourceDocument->requisition_no ??
                      $payment->sourceDocument->id;

            return "Payment for {$docType} {$docRef}: {$payment->payee_name}";
        }

        return "Payment {$payment->payment_no}: {$payment->payee_name}";
    }
}
