# Unifying Payment Vouchers, supplier payments and petty cash around one Payment engine

_2026-09-14. Applies to `App\Modules\Finance` (SpendVoucher, Payment, PaymentSource,
JournalPostingService) and `App\Modules\ProcurementStores` (Bill, BillPayment,
SupplierPaymentService)._

## The premise, checked against the code

A reader of the Finance & Procurement Atlas (2026-09-14) is right that Payment
Vouchers, supplier payments and petty cash should not be three unreconciled ways of
recording a cash movement. Where the proposal is imprecise is in *why*: it isn't
that `SpendVoucher` bypasses the unified `Payment` document. It doesn't —
`SpendVoucherSettlementService::settle()` and `SupplierPaymentService::recordBatch()`
**both already create a `Finance\Models\Payment` row**, and both already attach
their per-line detail underneath it the same way (`SpendVoucherAllocation` /
`BillPayment.disbursement_id`). The unification the September 2026 work set out
to build is real, at the data-model level.

What isn't unified is the **code that produces that Payment, and the code that
posts it to the ledger** — each was written twice, independently, for the two
rails:

| | Settlement (creates the `Payment`) | GL posting |
|---|---|---|
| Payment Voucher | `SpendVoucherSettlementService::settle()` | `JournalPostingService::postSpendVoucher()` |
| Supplier bill payment | `SupplierPaymentService::recordBatch()` | `JournalPostingService::postSupplierPayment()` |

Both settlement methods do the same thing in the same order: lock the petty-cash
balance, refuse a `payment_source.type === 'payable'`, resolve the payment method,
run the top-up allocation and float debit when the source is petty cash,
`Payment::create()`. Both posting methods do the same accounting: debit the
liability-or-advance account, credit the payment source's GL account, guarded by
the same `type === 'payable'` check. `postSupplierPayment()` writes through the
shared `writeEntry()` helper; `postSpendVoucher()` builds `JournalEntry`/`JournalLine`
directly and does not. This is why today's AP-Supplier-Credit wash-entry defect had
to be fixed in **two places** rather than one, and why it is the kind of defect that
will recur at a third call site rather than a proof that this one is now safe.

Three other things the Atlas names are real and stay real under this redesign, but
are **different problems** from the one above and are out of this document's scope:

- **`CostLine` and `Bill`/`BillPayment` have no foreign key to each other**, despite
  sometimes describing the same real invoice. This is a liability-identity gap, not
  a payment-duplication gap — fixing the settlement/posting duplication above does
  nothing to it. Tracked separately.
- **Reversal leaves every source document stale.** Neither `postSpendVoucher()`
  nor `postSupplierPayment()` has a document-aware reversal — only
  `CostVerificationService::reverse()` does, for `CostLine`. This redesign fixes it
  (§4), because the fix is the same shape for both rails once they share one
  settlement path.
- **Petty cash as a *request* workflow** (`PettyCashRequisition`, schema-driven
  types, its own approve/reject) is a second front door alongside `SpendVoucher`'s
  `request → approve → post`. Petty cash as a *paying account*
  (`PaymentSource type=petty_cash`) is already correctly unified — nothing to do
  there. Consolidating the two request workflows is a product decision about which
  form Finance keeps, not an engineering one; flagged in §6, not built here.

"Payment Voucher" is already the term shown to users — `SpendVouchersView.vue`'s
page title is `"Payment vouchers"`. The internal names (`SpendVoucher`,
`spend_vouchers` table, `finance.spend_vouchers.*` permissions) are not user-facing
and renaming them buys nothing but migration risk. **Not part of this redesign.**

## 1. Target shape

```
                    request → approve → post              record payment
                    (Payment Voucher)                      (Supplier Bill payment)
                            |                                       |
                            v                                       v
                  PaymentSettlementService::settle()  <---- shared ---->
                            |
                    resolves source, method,
                    petty-cash top-up + float debit
                            |
                            v
                       Payment  (one row: what left which account, how)
                       /              \
           SpendVoucherAllocation   BillPayment (one or more, per invoice)
                 |                          |
             CostLine                     Bill
                            |
                            v
              JournalPostingService::postCashSettlement()
                 Dr liability/advance account
                 Cr payment source's GL account
                            |
                            v
                      JournalEntry  →  General Ledger

              PaymentReversalService::reverse($payment, $reason)
              -------------------------------------------------
              reverses the journal entry(ies), reopens the
              liability, credits the float back, marks the
              Payment and its source document reversed —
              never deletes the original row.
```

## 2. Phase 1 — Payment Source hygiene (do first, low risk)

`payment_sources.type` already distinguishes the one non-cash row
(`AP` / type `payable`, GL 2100) from every real account (`petty_cash`, `bank`,
`mobile_money`, `card`). That is a complete, correct predicate today — it does not
need a new column.

- Add `PaymentSource::scopeUsableForPayment()` → `where('type', '!=', 'payable')`.
- `PaymentSourceController::index()` accepts `?for=payment` and applies the scope
  server-side.
- Remove the client-side `.filter(account => account.type !== 'payable')` calls in
  `SpendVouchersView.vue`, `BillingShow.vue` and `PayrollDisbursement.vue` — they
  call the endpoint with `?for=payment` instead.
- Regression test: `GET /finance/payment-sources?for=payment` never includes the
  `AP` row, whatever is added to the table later.

This closes the "excluded per-screen, not structurally" gap without introducing a
second flag that can drift out of sync with `type`.

## 3. Phase 2 — One settlement service

Extract `App\Modules\Finance\Services\PaymentSettlementService`:

- `settle(PaymentSource $source, array $terms, callable $allocate): Payment` —
  everything currently duplicated: balance lock, `payable`-type refusal, method
  resolution (`PaymentMethods::TYPICAL_FOR_SOURCE_TYPE` fallback), petty-cash
  top-up planning (`TopUpAllocator`), the float debit (`LedgerService::post`), the
  activity log entry, `Payment::create()`.
- The `$allocate` callback is the ~20% that's genuinely different per rail: for a
  voucher, write `SpendVoucherAllocation` rows against the already-validated
  `CostLine`s; for a supplier payment, write one or more `BillPayment` rows and
  call `$bill->updatePaymentStatus()`.

`SpendVoucherSettlementService::settle()` and `SupplierPaymentService::recordBatch()`
become thin callers of this, keeping their existing public signatures so
`SpendVoucherController::post()` and `BillController::recordPayment()` /
`recordMultiBillPayment()` don't change.

## 4. Phase 3 — One posting rule

Extract `JournalPostingService::postCashSettlement()`, built on the existing
`writeEntry()` helper (already used by `postSupplierPayment()`, not yet by
`postSpendVoucher()`):

- Resolves the debit account (AP / advance / expense, per the rule already coded
  in each method) and the credit account (`payment_source.gl_account_id`).
- Runs the shared `type === 'payable'` guard once.
- Keeps each rail's existing `entry_no` prefix (`JE-SV-…`, `JE-BPAY-…`) and
  `source_type` (`SpendVoucher::class`, `BillPayment::class`) exactly as they are
  today — no historical journal entry, report, or `source_type` filter changes
  meaning.

`postSpendVoucher()` and `postSupplierPayment()` become thin wrappers supplying
their prefix and source identity to `postCashSettlement()`.

## 5. Phase 4 — Atomic reversal, keyed off `Payment`

New `App\Modules\Finance\Services\PaymentReversalService::reverse(Payment $payment, int $actorId, string $reason)`,
one transaction:

1. Load the journal entry(ies) tied to the payment's source document(s) and call
   the existing `JournalPostingService::reverseEntry()` on each — unchanged, this
   part already works correctly.
2. Set `Payment.status = 'reversed'`.
3. Set the source document's own status: `SpendVoucher.status = 'reversed'`, or for
   a supplier payment, reopen the `Bill` balance that `updatePaymentStatus()` had
   closed.
4. Release the liability: delete/void the `SpendVoucherAllocation` rows (so
   `eligible-liabilities` shows the `CostLine` as unpaid again) or restore the
   `Bill`'s outstanding balance.
5. If `payment_source.type === 'petty_cash'`, credit the float back — the mirror of
   `LedgerEntry::debitForDisbursement()`.
6. Write the audit log entry. **The original `Payment` row is never deleted.**

New endpoint: `POST /api/finance/payments/{payment}/reverse`
(`reason` required, permission `finance.payments.reverse` — new, granted to
Accounts alongside the existing `finance.journals.reverse`).

The existing `POST /api/finance/journals/{journal}/reverse` stays as-is for journal
entries with no `Payment` behind them (payroll, WIP releases, cash movements — see
the General Ledger build's Stage 0 note that named all of these as reasons the
generic reverser was built). It should **refuse with a 422** ("reverse the payment
instead") when the entry's `source_type` is `SpendVoucher::class` or
`BillPayment::class`, so a user can no longer reach the half-correcting path by
accident.

## 6. Not part of this redesign

- **Renaming `SpendVoucher`.** The user-facing label already reads "Payment
  vouchers"; the internal name is not visible to anyone this would help.
- **A new `can_make_payment` column on `payment_sources`.** `type != 'payable'`
  already says the same thing from one place; a parallel boolean is a second thing
  to keep in sync, not a simplification.
- **Merging `CostLine` and `Bill` into one liability table**, or adding the foreign
  key between them. Real, tracked in the Atlas's gap list — a different, larger
  question with its own risk, not a prerequisite for this work.
- **Retiring `PettyCashRequisition`'s request workflow.** Its schema-driven, per-type
  form (`RequisitionSchemaService`) is real functionality the September 2026
  unification deliberately did not merge into vouchers. Whether Finance eventually
  wants one request form or two stays a product call — this redesign only touches
  what happens after money is approved to move, not how it gets asked for.

## Sequencing and risk

Phases 1–3 touch only backend services and are behind existing, unchanged public
signatures — no frontend or API contract changes beyond Phase 1's new query param
and the removal of now-redundant client-side filters. Phase 4 adds one new
endpoint and one new permission; it does not remove the general journal-reverse
endpoint, only narrows what it accepts.

Each phase should land with its own tests and keep
`tests/Feature/{Finance,CostCollector,PettyCash}` and `tests/Feature/Procurement`
green before merging — a push to `master` in this repo runs `migrate --force`
against production unattended, so Phase 1's migration-free scope is deliberately
first.

Related: Finance & Procurement Atlas (2026-09-14, this conversation),
`purchase-to-pay-workflow.md`, `general-ledger-plan.md`.
