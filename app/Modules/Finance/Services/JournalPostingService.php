<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\PostingRule;
use App\Modules\Finance\Models\SpendVoucher;
use App\Modules\Finance\Models\VatTreatment;
use App\Modules\Finance\Models\WhtCategory;
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

            $total = array_reduce(
                array_filter($legs, fn (array $leg) => $leg['entry_type'] === 'debit'),
                fn (string $carry, array $leg) => bcadd($carry, $leg['amount'], 2),
                '0.00',
            );

            $entryNo = 'JE-CL-' . str_pad((string) $line->id, 7, '0', STR_PAD_LEFT);

            $entry = JournalEntry::create([
                'entry_no' => $entryNo,
                'posting_date' => substr((string) ($line->incurred_at ?? now()->toDateString()), 0, 10),
                'accounting_period_id' => $line->accounting_period_id,
                'cost_line_id' => $line->id,
                'source_type' => CostLine::class,
                'source_id' => $line->id,
                'source_ref' => $line->ref,
                'description' => $line->description ?? 'Cost line posting: ' . $line->ref,
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
                'description' => 'Recoverable input VAT on ' . $line->ref,
            ];
        }

        if (bccomp($wht, '0.00', 2) === 1) {
            $legs[] = [
                'account_id' => $this->whtPayableAccountId($line),
                'entry_type' => 'credit',
                'amount' => $wht,
                'base_amount' => $this->base($line, $wht),
                'description' => 'Withholding tax retained on ' . $line->ref,
            ];
        }

        $legs[] = [
            'account_id' => $creditAccountId,
            'entry_type' => 'credit',
            'amount' => $settled,
            'base_amount' => $this->base($line, $settled),
            'description' => 'Payable/Clearing for ' . $line->ref,
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
                . 'Set a GL account on the VAT treatment, or add account ' . self::VAT_INPUT_CODE . ' to the chart.'
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
                . 'Set a GL account on the WHT category, or add account ' . self::WHT_PAYABLE_CODE . ' to the chart.'
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
     */
    public function postSpendVoucher(SpendVoucher $voucher): ?JournalEntry
    {
        $existing = JournalEntry::where('spend_voucher_id', $voucher->id)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($voucher) {
            $amount = (string) ($voucher->net_cash_paid ?? $voucher->total_amount);
            if (bccomp($amount, '0.00', 2) <= 0) {
                return null;
            }

            [$debitAccountId, $creditAccountId] = $this->resolveAccountsForVoucher($voucher);

            if (! $debitAccountId || ! $creditAccountId) {
                throw new InvalidArgumentException("No complete posting rule could be resolved for spend voucher {$voucher->voucher_no}.");
            }

            $entryNo = 'JE-SV-' . str_pad((string) $voucher->id, 7, '0', STR_PAD_LEFT);

            $entry = JournalEntry::create([
                'entry_no' => $entryNo,
                'posting_date' => $voucher->posting_date ?? now()->toDateString(),
                'accounting_period_id' => $voucher->accounting_period_id,
                'spend_voucher_id' => $voucher->id,
                'source_type' => SpendVoucher::class,
                'source_id' => $voucher->id,
                'source_ref' => $voucher->voucher_no,
                'description' => 'Voucher payment: ' . $voucher->voucher_no,
                'total_debit' => $amount,
                'total_credit' => $amount,
                'status' => 'posted',
                'created_by' => $voucher->posted_by ?? auth()->id(),
                'posted_at' => now(),
            ]);

            // Debit leg
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $debitAccountId,
                'entry_type' => 'debit',
                'amount' => $amount,
                'currency' => $voucher->currency ?? 'KES',
                'fx_rate' => $voucher->fx_rate ?? 1,
                'base_amount' => $voucher->base_total_amount ?? $amount,
                'description' => 'Disbursement to ' . ($voucher->payee_name ?? 'Payee'),
            ]);

            // Credit leg
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $creditAccountId,
                'entry_type' => 'credit',
                'amount' => $amount,
                'currency' => $voucher->currency ?? 'KES',
                'fx_rate' => $voucher->fx_rate ?? 1,
                'base_amount' => $voucher->base_total_amount ?? $amount,
                'description' => 'Cash/Bank Outflow',
            ]);

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

        $original = JournalEntry::with('lines')->findOrFail($line->journal_entry_id);

        if ($existing = JournalEntry::where('reversal_of_id', $original->id)->first()) {
            return $existing;
        }

        $period = AccountingPeriod::forDate(now());
        if (! $period || ! $period->isOpen()) {
            throw new InvalidArgumentException('No open accounting period is available for the reversal.');
        }

        return DB::transaction(function () use ($line, $original, $period, $actorId, $reason) {
            $entry = JournalEntry::create([
                'entry_no' => 'JE-CL-' . str_pad((string) $line->id, 7, '0', STR_PAD_LEFT) . '-REV',
                'posting_date' => now()->toDateString(),
                'accounting_period_id' => $period->id,
                'cost_line_id' => $line->id,
                'source_type' => CostLine::class,
                'source_id' => $line->id,
                'source_ref' => $line->ref,
                'description' => 'Reversal of ' . $original->entry_no . ': ' . $reason,
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
                    'description' => 'Reversal: ' . ($originalLine->description ?? $line->ref),
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

    private function resolveRuleForCostLine(CostLine $line): ?PostingRule
    {
        if ($line->expense_code_id) {
            $rule = PostingRule::active()
                ->where('expense_code_id', $line->expense_code_id)
                ->orderBy('priority', 'desc')
                ->first();
            if ($rule) return $rule;
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

        if (! $debitId && $line->expense_code_id) {
            // `default_debit_account_id` is the column expense_codes actually
            // carries; `gl_account_id` belongs to payment_sources. Reading the
            // wrong name here returned null for every code ever posted, so the
            // catalogue's GL mapping was dead and everything fell through to
            // the guesswork below.
            $debitId = $line->expenseCode?->default_debit_account_id;
        }

        // Fallback accounts if rules not yet fully configured
        if (! $debitId) {
            $debitId = ChartOfAccount::postable()
                ->where(function ($q) {
                    $q->where('code', 'COS-001')->orWhere('code', 'like', '12%')->orWhere('category', 'expense');
                })
                ->value('id');
        }

        if (! $creditId) {
            $creditId = ChartOfAccount::postable()
                ->where(function ($q) {
                    $q->where('code', '1030')->orWhere('code', 'ACC-001')->orWhere('category', 'asset')->orWhere('category', 'liability');
                })
                ->value('id');
        }

        return [$debitId, $creditId];
    }

    /** @return array{0: int|null, 1: int|null} */
    private function resolveAccountsForVoucher(SpendVoucher $voucher): array
    {
        $creditId = $voucher->paymentSource?->gl_account_id;

        if (! $creditId) {
            $creditId = ChartOfAccount::postable()
                ->where(function ($q) {
                    $q->where('code', '1030')->orWhere('category', 'asset');
                })
                ->value('id');
        }

        $debitId = ChartOfAccount::postable()
            ->where(function ($q) {
                $q->where('code', 'COS-001')->orWhere('category', 'expense')->orWhere('category', 'liability');
            })
            ->value('id');

        return [$debitId, $creditId];
    }
}
