# Finance Module — Whole-Module Gap Analysis

**Date:** 2026-08-10 · **Scope:** `app/Modules/Finance/**` (84 PHP files) + `ERP-Frontend/src/modules/finance/**` (61 files)
**Supersedes:** the Petty-Cash-focused half of [finance-module-audit.md](./finance-module-audit.md) (2026-07-21)
**Companions:** [finance-gl-implementation-plan.md](./finance-gl-implementation-plan.md) · [cost-collector-integration.md](./cost-collector-integration.md) · [quote-to-cash-redesign.md](./quote-to-cash-redesign.md)

Test baseline at time of writing: **99 passed / 2,275 assertions** across
`tests/Feature/Finance`, `tests/Feature/CostCollector`, `tests/Feature/PettyCash`, `tests/Unit/PettyCash`.
Everything below is a gap the green suite does not cover.

---

## 0. The shape of the problem

The module is four sub-systems at wildly different maturity, sharing a nav bar:

| Sub-system | Built | State |
|---|---|---|
| **Cost Collector** | Aug 2026 | **Solid.** Policy-based auth, explicit state machine, idempotent producers, notifications, 40+ tests. This is the reference quality bar. |
| **Petty Cash** | Jun–Jul 2026 | **Ledger is sound**, authorization is not. `LedgerService` single-writer + reconciliation invariant hold; access control is still role strings. |
| **GL / Journal + Spend Vouchers** | 2026-08-09 (yesterday) | **Skeletal and unguarded.** Schema is thoughtful; the service and controller on top of it are a prototype wired to a live route with no permissions. |
| **Receivables / Payroll Disbursement** | — | **Labels, not systems.** Views over other modules' data. No Invoice, no AR ledger, no AP. |

The single most important structural fact: **a general ledger was introduced yesterday and nothing
enforces who may write to it.** Everything in §1 flows from that.

---

## 1. Critical — general ledger and spend vouchers

### G1 · Spend voucher endpoints have zero authorization
`routes/api.php:851–857` registers five endpoints under `finance/spend-vouchers` with **no
permission middleware**, and `SpendVoucherController` contains **no `authorize()` call**. The only
gate is `auth:sanctum`.

Any authenticated user — a technician, a driver, a store keeper — can create a payment voucher,
approve it, and post it to the general ledger. There are no `FINANCE_VOUCHER_*` permission constants
in `app/Constants/Permissions.php` to attach even if someone wanted to.

The module's own test demonstrates the hole rather than catching it:
`JournalPostingTest::test_spend_voucher_endpoints_flow` runs create → approve → post as a **single
user holding only `finance.costs.verify` and `finance.costs.read`**, and asserts success.

### G2 · No separation of duties on the document that moves cash
`approve()` never compares `requester_user_id` to `auth()->id()`. `post()` never compares
`approved_by` to the poster. One person is requester, approver, and Finance-poster.

This is the design's own non-negotiable rule (GL plan §1, brief §6), and it *is* enforced — on the
cheaper document. `CostVerificationService.php:46` refuses to let you verify a cost you reported.
The voucher that actually disburses money has nothing.

### G3 · A voucher can be posted to the GL without ever being approved
`SpendVoucherController.php:143–152` guards only against double-posting. It never checks
`status === 'approved'`, and then overwrites `status` to `'posted'`. Draft → GL in one call. The
approve step is decorative.

### G4 · A voucher can be marked posted with no journal entry behind it
`post()` sets `status = 'posted'` and *then* calls `postSpendVoucher()`, which returns **`null`** when
accounts cannot be resolved or the amount is zero. The return value is never checked. The response
says *"Voucher posted to General Ledger successfully"* with `journal_entry: null`.

Worse, it is unrecoverable: the guard at line 147 now sees `status === 'posted'` and refuses every
retry. Cash left, the GL never saw it, and the only fix is a manual DB edit.

### G5 · Account resolution is a guess, and the guess is the normal path
`JournalPostingService::resolveAccountsForCostLine/ForVoucher` fall through to:

```php
ChartOfAccount::postable()->where('category', 'expense')->value('id')   // debit
ChartOfAccount::postable()->where('category', 'asset')->value('id')     // credit
```

That is *the first arbitrary row in the table*. No `PostingRule` rows are seeded anywhere, and
`ExpenseCode.gl_account_id` is only consulted for the debit leg — so today **every posting lands on
two accounts picked by insertion order.** `test_journal_posting_service_posts_cost_line` exercises
exactly this fallback (a cost line with no expense code) and blesses the result as correct.

### G6 · Tax never reaches the ledger, and WHT is never computed at all
`CostVerificationService::taxAttributes()` strips `tax_amount` out of `net_amount` at verification.
`JournalPostingService` then posts **`net_amount` on both legs**. So on a KES 11,600 receipt with
KES 1,600 VAT: debit expense 10,000, credit cash 10,000 — and 1,600 of real cash outflow exists
nowhere in the GL. No VAT-recoverable leg, no VAT-payable leg.

`wht_amount` exists as a column on both `cost_lines` and `spend_vouchers` and has **zero writers**
anywhere in the codebase. `vat_treatments` and `wht_categories` carry `rate_percent`,
`threshold_amount`, residency and effective-dating — and no code ever reads `rate_percent`. The tax
engine the schema was built for does not exist; a human types a tax figure at verification.

### G7 · Every verified cost is now permanently unreversible
Wiring GL posting into `verify()` created a dead end:

1. `verify()` always calls `postCostLine()`, which sets `posted_at`.
2. `reverse()` throws if `posted_at` is set (`CostVerificationService.php:123`), directing the user
   to reverse by journal entry instead.
3. **There is no journal reversal.** No `JournalEntryController`, no `reverse()` on the posting
   service, no route. `journal_entries.reversal_of_id` is never written by anything.

`test_a_posted_cost_must_be_reversed_by_journal_instead` locks the dead end in. Before GL posting
existed the comment above `reverse()` was accurate; it is now describing a door that was never built.

### G8 · The general ledger is write-only
No endpoint reads it. No trial balance, no account statement, no journal listing, no period report.
`AccountingPeriod::isOpen()` is enforced on cost verification — but **nothing can open, lock, or
close a period through the API**; periods exist only because `AccountingPeriodSeeder` made them.
Voucher posting skips the period check entirely, so a voucher posts happily into a locked month.

### G9 · `voucher_no` generation races
`'SV-' . date('Ymd') . '-' . str_pad(SpendVoucher::count() + 1, 4)` (`SpendVoucherController.php:79`).
The counter is global rather than per-day, and two concurrent creates compute the same number —
the unique index then throws a raw 500 instead of a validation error.

---

## 2. High — petty cash and cost coverage

### G10 · Petty cash authorization is still role strings; `PettyCashPolicy` still doesn't exist
`BE-0` has been open since June. `CostLinePolicy` is the only policy in the Finance module.

- `PettyCashController` — `hasRole('Super Admin')` at lines **344, 400, 443, 480, 509, 894, 937, 971, 999, 1033**, plus `hasRole([...4 roles])` at :127
- `PettyCashRequisitionController` — `hasRole([...])` at :35 and :95

Fourteen `FINANCE_PETTY_CASH_*` permission constants are defined; only three are actually enforced
server-side (`upload_excel`, `edit_top_up`, `delete_top_up`). The frontend gates on permission keys
the backend largely ignores.

### G11 · Live petty cash spend never becomes a project cost or a GL entry
`PettyCashCostProducer`'s **only caller** is `BackfillPettyCashCostsCommand`. The Petty Cash
sub-module contains zero references to `CollectsCost`, `CostCollectorService`, or
`JournalPostingService`. Every disbursement made today is invisible to the cost account and the
ledger until a human runs `finance:backfill-petty-cash`.

Fixing this is cheap and high-value: dispatch an event from the disbursement write path and add a
queued listener, per the pattern in `cost-collector-integration.md`.

### G12 · Five of seven cost producers are unwired — project cost is understated by design
Per `cost-collector-integration.md`: Projects budget ✅ · Petty Cash ⚠️ command-only · **Stores ❌ ·
HR overtime ❌ · Procurement ❌ · Logistics ❌ · Payroll ❌**.

This is a documented roadmap item, not a defect — but it means the Cost Accounts screen currently
shows budget against a fraction of actual outturn. Stores is the cheapest next win
(`inventory_logs` already carries both `project_id` and `receipt_unit_cost`).

### G13 · Receivables and invoicing do not exist behind their permissions
`FINANCE_INVOICE_CREATE/READ/UPDATE/DELETE` are defined and granted to Finance roles. There is no
`Invoice` model, no AR ledger, no billing schedule, no AP. Confirmed still open from the July audit:
`enquiry_payments` has no `unique(transaction_reference)` and no currency column.

---

## 3. Frontend

### F1 · The Spend Vouchers & GL screen cannot talk to the API at all
`SpendVouchersView.vue` uses raw `fetch('/api/…')` at **:63, :80, :110, :125** — the only finance
file that bypasses `@/plugins/axios`.

Authentication is a bearer token from `localStorage`/`sessionStorage`, injected by the axios request
interceptor (`plugins/axios.ts:38`). Raw `fetch` sends no `Authorization` header, so **every one of
those four calls returns 401.** The Vite `/api` proxy makes the URL resolve, which is exactly why
this looks like it should work.

All four handlers act only `if (response.ok)` with a `console.error` fallback and no user-facing
error, so the screen silently renders *"No spend vouchers found."* forever, and Approve / Post GL
appear to do nothing. **The GL front end is non-functional in the browser today.**

### F2 · The voucher form omits every field the journal needs
The create modal collects type, payee, amount, invoice refs — and **no `payment_source_id`**, which
is the only input to the credit-leg account resolution (`resolveAccountsForVoucher`). Every
UI-created voucher therefore falls into the arbitrary-account fallback of G5. It also collects no
project, cost centre, activity, or expense category — none of the five mandatory classification
dimensions the design is built around.

### F3 · No permission gating on the most dangerous buttons in the module
Approve and Post GL render for anyone who reaches the route. `requiresFinanceAccess`
(`router/index.ts:307`) is a single coarse route-level check. Petty cash has a `usePermissions`
composable; cost-collector and spend-vouchers have no per-action equivalent.

### F4 · No GL surface, and no admin screen for any reference data
Nothing renders journal entries, a trial balance, an account statement, or period status. The
`FinanceShell` nav has eight items; **chart of accounts, expense codes, posting rules, accounting
periods, payment sources, suppliers, VAT treatments and WHT categories have no screen at all** —
they are seeder-only. Finance cannot maintain the GL mapping table the design says Finance owns.

### F5 · Receivables is a projects view wearing a finance label
`ProjectReceivablesIndex.vue` fetches `/api/projects/enquiries?view=receivables&per_page=500` and
derives every stat, tab count and filter in the browser. Totals silently truncate past 500 projects,
search only covers the loaded slice, and there is no invoice, aging, or allocation concept anywhere.

### F6 · Petty cash 422 field errors still never reach the form *(open since the July audit)*
`pettyCashService` builds a **flat** `EnhancedApiError` with `errors` at the top level
(`pettyCashService.ts:231`, `:265`). `useErrorHandler.ts:138` populates state via
`response: error.response?.data` — undefined for that flat shape. `DisbursementForm.vue:1404` then
tests `errorState.value.response && errorState.value.response.errors`, which is always falsy.

Backend field-level validation messages are computed, serialised, received — and dropped one line
from the input that needs them. (The top-level `message` does survive; only per-field errors are lost.)

**Fixed since July, confirmed:** the Void button is now reachable in `TransactionList.vue:586–597`,
and payment methods are centralised in `PaymentMethods.php`.

---

## 4. What "complete" needs, in order

Ordering matters more than the list — several of these make later work harmful if done out of
sequence.

### Phase A — Stop the bleeding (do before anything else touches the GL)
1. **Permissions + policy for spend vouchers** — add `FINANCE_VOUCHER_CREATE/APPROVE/POST/REVERSE`
   constants, a `SpendVoucherPolicy`, and route middleware. *(G1)*
2. **Separation of duties on the voucher** — requester ≠ approver ≠ poster, enforced on the record
   the way `CostVerificationService` already does. *(G2)*
3. **`post()` requires `status === 'approved'`**, and **fails loudly when `postSpendVoucher()`
   returns null** — no "posted" without an entry id. *(G3, G4)*
4. **`PettyCashPolicy`** — retire the 12 `hasRole` checks onto the 14 permissions that already
   exist. Long-open BE-0; it also unblocks the role table in the GL plan. *(G10)*
5. **Fix `SpendVouchersView` to use the axios client** and surface errors. Until then the screen is
   a placebo. *(F1)*

### Phase B — Make the ledger trustworthy
6. **Seed `PostingRule` rows and make resolution explicit** — a posting that cannot resolve a rule
   must raise, not guess. Delete the `category`-based fallbacks and retire the test that blesses
   them. *(G5)*
7. **Journal reversal** — `JournalPostingService::reverse()` writing a compensating entry with
   `reversal_of_id`, plus the endpoint that reaches it. This reopens the door G7 currently points
   at. *(G7)*
8. **Tax legs in the journal** — post gross cash, split VAT-recoverable to its own account, compute
   WHT from `wht_categories.rate_percent` and credit WHT-payable. *(G6)*
9. **Period enforcement on voucher posting**, plus open/lock/close endpoints so periods are
   operable. *(G8)*
10. **`voucher_no` from an atomic sequence**, not `count() + 1`. *(G9)*

### Phase C — Make the GL visible and complete
11. **GL read API + screens** — journal listing, account statement, trial balance. *(G8, F4)*
12. **Reference-data admin screens** — chart of accounts, expense codes, posting rules, periods,
    payment sources, tax rates. Finance cannot own the mapping table it has no screen for. *(F4)*
13. **Voucher form carries the five dimensions** — payment source, project, cost centre, activity,
    cost cause. *(F2)*
14. **Per-action permission gating in the finance UI.** *(F3)*

### Phase D — Close the cost-capture perimeter
15. **Petty cash → cost collector as a queued listener** (not the backfill command). *(G11)*
16. **Stores → cost at issue**, then HR overtime, Procurement, Logistics, Payroll. *(G12)*

### Phase E — The genuinely missing module
17. **AR / billing** — Invoice, milestones, allocations, aging. Already scoped as Phase 2 of
    `quote-to-cash-redesign.md`; do not build a second one here. Until it exists, Receivables stays
    a derived view and the invoice permissions stay vestigial. *(G13, F5)*

### Small, independent
18. **F6** — one-line-ish fix to the petty cash error shape so 422 field errors land on the form.

---

## 5. Notes for whoever picks this up

- **The Cost Collector is the pattern to copy**, not to work around: policy-based authorization,
  transitions declared in one place, idempotency keys on producers, notifications resolved by
  permission rather than role, and a test per rule. Where the new GL code disagrees with it, the GL
  code is wrong.
- **A green suite is not coverage here.** Two of the three most serious findings (G1, G5) are
  actively *asserted as correct* by `JournalPostingTest`. Fixing them means changing tests, and that
  is the right move.
- **Do not build Phase C before Phase B.** Making an untrustworthy ledger visible converts a quiet
  problem into reported numbers people act on.
