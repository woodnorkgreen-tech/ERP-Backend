# Finance Module — Whole-Module Gap Analysis

**Date:** 2026-08-10 · **Scope:** `app/Modules/Finance/**` (84 PHP files) + `ERP-Frontend/src/modules/finance/**` (61 files)
**Supersedes:** the Petty-Cash-focused half of [finance-module-audit.md](./finance-module-audit.md) (2026-07-21)
**Companions:** [finance-gl-implementation-plan.md](./finance-gl-implementation-plan.md) · [cost-collector-integration.md](./cost-collector-integration.md) · [quote-to-cash-redesign.md](./quote-to-cash-redesign.md)

Test baseline at time of writing: **99 passed / 2,275 assertions** across
`tests/Feature/Finance`, `tests/Feature/CostCollector`, `tests/Feature/PettyCash`, `tests/Unit/PettyCash`.
Everything below is a gap the green suite does not cover.

---

## 0a. Progress log — 2026-08-18

Work completed against the numbered plan in §5. Test baseline is now **254 passed / 3,143
assertions** across the same three directories (was 99 / 2,275 at the time of writing).

| Item | Status | What was done |
|---|---|---|
| **9** (part) — period enforcement on voucher posting | **Done** | `SpendVoucherController::store` now resolves `accounting_period_id` from the posting date via `AccountingPeriod::forDate`, exactly as `CostContextResolver` does for a cost line. `post()` refuses a locked or closed period with the same guard `CostVerificationService` applies. Open/lock/close endpoints are still missing, so periods remain operable only by seeder. |
| **11** (API half) — GL read API | **Done** | `JournalEntryController`: paginated entry list (filter by source, status, date range, account, project enquiry; search on entry/source ref), single-entry drill-down with every leg and the account it hit, and a trial balance with an explicit `is_balanced`. Read-only by design — journals are written by `JournalPostingService` as a consequence of verifying a cost or posting a voucher, and a second writer here would repeat the petty-cash board-request mistake. Gated on `finance.reports.view`, which was declared since the module was written and enforced by nothing; a migration makes it grantable (Super Admin only, so nobody's effective access changed). **Screens are still to build.** |
| **14** (spend vouchers only) — per-action permission gating | **Done** | Create / Approve / Post in `SpendVouchersView.vue` are gated on their own permissions. Cost-collector still has no per-action equivalent. |
| **G9** — `voucher_no` race | **Already fixed; doc was stale** | `store()` derives the number from the primary key inside a transaction, not `count() + 1`. |

### Also fixed, not previously recorded

- **`GET /api/finance/spend-vouchers` returned 500 whenever a voucher existed.** `SpendVoucher::costLines()` was declared as `hasMany(CostLine::class, 'spend_voucher_id')` and **`cost_lines` has no such column**; both `index()` and `show()` eager-loaded it. Every existing test reached vouchers by id and never listed them, and the client swallowed the failure into `console.error` — so the spend voucher list page has never worked with data in it. The phantom relation and both eager loads are removed, with regression tests that list and show.
- **Spend voucher list was page-one-only.** The backend has always paginated at 25; the client fetched page 1 and then filtered, searched and computed its headline stats over that slice, so every figure was wrong past 25 vouchers and a voucher on page 2 could not be found. Filtering and search are now server-side, pagination is wired, and `index()` returns a `summary` aggregated over all vouchers rather than the page.
- **Voucher errors were invisible.** All four client handlers ended at `console.error`, and the success check read `response.data.success` while the API returns `status`. The 422 reasons — self-approval refused, requester-cannot-post, locked period — now reach the user.
- **`payment_source_id` was absent from the voucher form** although it decides which GL account the credit leg hits. A `payment-sources` endpoint (gated on voucher read) and the field now exist.
- **Petty cash reporting was orphaned at both ends.** `PettyCashReportService` had seven generators and no caller; `ReportsPanel.vue` called `/analytics` and `/reports/projects`, neither routed, and was rendered by no view. Two endpoints now exist, the panel is mounted behind `view_reports`, and `generateProjectReport` was rebuilt on the model scopes — it had been counting archived rows, summing `amount` while the summary summed `amount + transaction_cost`, and filtering on `created_at` while every other report uses `date_disbursed`, so it would have put two disagreeing totals on one screen.
- **`POST /api/finance/petty-cash/top-ups` had no authorization of any kind.** `update` and `destroy` were gated by audit BE1/BE2; `store` was missed, so any authenticated user could credit the ledger and raise the float. Now gated on `finance.petty_cash.create_top_up`, which Admin and Accounts already hold.

### Posting defect — every journal was crediting the bank *(fixed 2026-08-18)*

Found while tracing how Stores material cost reaches Finance. `posting_rules` held **zero rows**, so
`resolveAccountsForCostLine` fell through to a fallback reading *"the first postable account whose
category is asset"* — which in the seeded chart is `1010 Bank – Main Account`. Confirmed against live
journals:

| Movement | Was posting | Should post |
|---|---|---|
| Stores issue (JE 22) | Dr 1211 Project WIP · **Cr 1010 Bank** | Dr 1211 WIP · Cr 1200 Raw-material Inventory |
| Stores return (JE 21) | **Dr 1010 Bank** · Cr 1211 WIP | Dr 1200 Inventory · Cr 1211 WIP |
| GRN accrual (JE 2) | Dr 1211 WIP · **Cr 1010 Bank** | Dr … · Cr 2150 Accrued Expenses |

Issuing material from your own store moves no cash — it moved when the material was bought. So the
bank was being relieved a second time for every issue, `1200 Raw-material Inventory` never moved at
all despite existing and being postable, and returns inflated the bank by the same mechanism.

**Fix.** Debit and credit answer different questions: the debit is *what the cost was for* and comes
from the expense code (94 of 100 codes already map one correctly); the credit is *what settled it*,
which is a property of how the cost arose and was modelled nowhere. `settlementAccountFor()` now
resolves it, most certain first: explicit `PostingRule` → `stock-issue`/`stock-return` → 1200 →
`accrual` → 2150 → a named `payment_source_id` → that float's GL account → funding voucher's payment
source → **2100 Accounts Payable**.

That last fallback is deliberate. A cost we cannot trace to a settlement is one we still *owe*; of the
two available guesses only "we paid it from the bank" understates cash. Returns need no separate rule
— a negative net already swaps the legs.

Pinned by `SettlementAccountTest` (5 tests), which assert the accounting rather than the plumbing —
including that a stores issue must never credit 1010.

**The double-count, confirmed and half-fixed.** The operational answer is that material bought against
a specific project *does* route through the store and is issued out. So a delivery was charging the
job twice: once as a GRN accrual debiting WIP, once again as the stores issue debiting WIP.

*Fixed in the GL:* a goods receipt now debits **1200 Raw-material Inventory**. Receiving material is a
stock movement whoever it was bought for; the project is charged when it is consumed. Across a receipt
and its issue, Project WIP is now debited exactly once — pinned by
`test_the_full_material_cycle_touches_project_wip_exactly_once`.

*Second defect found while proving it:* the debit fallback matched `code like '12%'`, which catches
`1200 Raw-material Inventory` before the Project WIP band (1211–1219) because it sorts first. An
unmapped cost therefore debited Inventory — and since a stores issue also credits Inventory, the entry
hit the same account on both sides. Balanced, and meaningless. The pattern is now `121%` with 1200
excluded explicitly.

*Also fixed — the relay was missing a link:* `CostAccountService` computes project spend as
`committed + accrued + actual`, and each stage is meant to retire the one before. A goods receipt
already retired its purchase-order commitment; **nothing retired the accrual when the material was
issued**, so a job carried both figures for the same delivery. `CostCollectorService::releaseAccrual()`
now does, called from `StoresCostProducer` after an issue posts.

Two details worth knowing:

- **The accrual's journal is deliberately left standing.** It records Dr Raw-material Inventory /
  Cr Accrued Expenses — two facts still true after the material leaves the store: the goods exist, and
  the supplier is still owed. Reversing it would erase a liability nobody has settled. What is retired
  is the *project* figure.
- **The whole accrual is retired on the first issue of that material, not a proportion.** Accrued lines
  journal, so posting a partial balance back would duplicate the stock entry to correct a reporting
  figure. Between a part-issue and the rest this understates what is still in the store — the
  conservative direction, and much better than double-charging. Proportional relief needs a
  non-journaling adjustment line, which the model does not currently have.

Matching an issue to its accrual needs the material identity on both, so `postGoodsReceipt` now records
`library_material_id` on the accrual from `purchase_order_items.material_id`.

**History not rewritten.** The 26 existing journals still carry the old accounts. Reposting them is a
business decision; `RepostMisattributedStoresCostsCommand` is the precedent if it is wanted.

### Connectivity pass — nothing left dangling

A systematic sweep in both directions: every finance route against every frontend call, every
finance component against every render site, every finance class against every reference.

| Was unconnected | Now |
|---|---|
| **GL had no screen.** The three journal endpoints had no UI and no nav entry. | `GeneralLedgerView` at `/finance/ledger`, reached from a **General ledger** item in the finance nav (gated on `finance.reports.view`). Two tabs — journal entries with source/status/date/search filters and pagination, and a trial balance that states plainly whether the books agree. |
| **`journal_entry_no` was inert text** in the cost verification queue. | Now a button, in both the table cell and the expanded panel, opening `JournalEntryDrawer` — the entry's legs, the accounts each hit, the period, and its reversal links. |
| **`POST /costs/verification/{id}/resubmit`** was implemented, policy-gated and exposed in the client service, with **no caller**. | Wired as "Reply without changing the amount" on a queried cost. This was a real functional gap: `correct` requires an amount, so a query about coding, project or evidence could only be answered by re-asserting a figure nobody had questioned. |
| **`PettyCashTransactionsExport`** had no caller, and the client built exports in the browser from one unpaginated `/voucher` response — tab-separated text under an `.xls` extension. | `GET /petty-cash/export` returns a real `.xlsx` via the export class and `generateExportData()`, which also gives that method and its three private generators their first caller. CSV stays client-side deliberately, and is now honestly named. |
| **`ProjectBudgetDetailsModal.vue`** (315 lines) referenced by nothing — left behind when the Project Budgets tab was removed. | Deleted. |
| **`UpdateDisbursementRequest`** had no caller; `update()` validates through `PettyCashService::validateDisbursementData`. | Deleted. The service path is the richer one — it adds the disbursement's current amount back to the available balance when updating, which the FormRequest cannot do — so wiring the FormRequest instead would have been a regression, and keeping both would have meant two rule sets drifting apart. |

`FinanceServiceProvider` also appeared unreferenced by the sweep; it is registered in
`bootstrap/providers.php` and is fine.

### Usability pass — the module now explains itself

`finance.css` has carried a complete `.fin-guide` treatment — trigger, panel, numbered steps,
footnote, responsive collapse, dark mode — plus a `[data-fin-tooltip]` term tooltip, since the design
system was written. **No component had ever used either.** The stylesheet's own comment states the
intent: *"Tooltips explain terms in place; the guide explains the whole journey. Both are progressive
disclosure, so guidance never competes with live data."*

- **`FinanceGuide.vue`** implements it, built on `<details>` so it is keyboard-operable and
  screen-reader-announced without custom ARIA, and degrades to readable text if the stylesheet fails.
- **`shared/guides.ts`** holds the copy for all 13 screens plus 11 shared term definitions, in one
  file so vocabulary cannot drift between screens — "verified" has to mean the same thing in the
  capture queue and in the ledger, or the guidance actively misleads.
- **`StatTile` gained an optional `hint`**, because a headline figure is exactly where a reader who
  does not share Finance's vocabulary gets stuck: *committed* and *actual* look interchangeable until
  someone explains that one is promised and the other is spent.

Guidance is written for the person at the screen — a technician holding a receipt, a storekeeper
issuing material, a Finance officer clearing a queue — not for an accountant reading a spec. It
states the controls plainly where they bite: that you cannot verify your own cost, that requester,
approver and poster must be three different people, that an approved requisition is already committed
against the budget, and that marking a payroll run paid does **not** post it to the ledger.

The two public, unauthenticated screens are covered deliberately: whoever opens them has no account
and no training, and on the sign-off page they are signing that money reached them.
`RequisitionPreview.vue` is left without one — it is a preview pane inside the already-guided
requisition index, not a screen of its own.

### Stores issues could not be valued *(fixed 2026-08-18)*

A stores issue was valued from `receipt_unit_cost`, then `library_materials.unit_cost`, then nothing —
and with nothing it posted no cost line, failed the outbox job, and asked a person to price it by hand.
That manual path was carrying the normal case, not the exception:

| Where a price could live | Populated |
|---|---|
| `library_materials.unit_cost` (catalogue) | 8 of 438 — **2%** |
| `element_materials.unit_cost` (project material line) | 356 of 7,385 — **5%** |
| `task_budget_data.materials_data` → `unitPrice` (budget) | 407 of 437 budgets — **93%** |

This business prices at budget level. The valuation path checked the two empty places and never the
full one.

`StoresCostProducer::plannedUnitRate()` adds the budget rung, derived as the planned cost line's
`net_amount` over the planned quantity and matched on `element_materials.persistent_id` — that is the
route the budget's `unitPrice` actually travels. Exact-match only: `plannedLine()` may fall back to
catalogue identity for legacy movements, and dividing another line's total by this line's quantity
would invent a rate rather than find one. It returns null rather than zero, so "no budget rate" stays
distinguishable from "budgeted at nothing".

**Every such line is flagged `details.valued_at_plan`.** A cost priced from the budget is not evidence
of what the material cost and must never be silently indistinguishable from one that is. The Stores
exceptions panel's `planned_unit_cost` now reads through the same resolver — it previously read
`element_materials.unit_cost`, blank for 95% of rows, so it offered an empty hint at the one moment a
human needed a figure.

**The accounting trade-off, named:** this is standard costing. Material actual equals budget by
construction on affected lines, so material variance stops being informative until real receipt prices
flow. The flag is what keeps it visible and repriceable. The proper end state is goods receipts
carrying the purchase price and updating the catalogue, with the difference posted as purchase-price
variance.

**Still blocked:** the 10 failed postings will not resolve until a worker runs the `default` queue —
their planned cost lines were never created, because `ProjectBudgetLinesOnTaskCompletion` has 32 jobs
stuck there.

### The project cost account statement *(redesigned 2026-08-18)*

`CostAccountService` computed `committed`, `accrued` and `actual` per category and sent all three to
the client, which then collapsed them into a single **Spent** column — rendered as a segmented bar plus
a sub-label that only appeared when a row had more than one of them. A category sitting entirely on
committed spend therefore showed a figure with no indication that none of it had been paid, which is
the one distinction project cost control turns on.

The panel also hand-rolled its own table while the sibling Budget-vs-actual screen already used
`LedgerTable` — the design system's financial grid, with banded group headers, a sticky identity
column, per-column sorting and a ruled total row. The fix was to adopt the design already proven in
this codebase rather than invent one.

The statement now reads in three bands: **Approved budget** (Original · Changes · Current),
**Incurred** (Committed · Accrued · Actual · Total) and **Variance** (Remaining · Used · Unplanned).

`original` and `additions` are new, per category. The project-level revision split already existed;
without it per category a reader cannot tell a category that was always expensive from one that grew
by change request, and those call for opposite conversations. `original + additions === planned`,
pinned by two tests.

`Unplanned` sits in the Variance band deliberately: it is a subset of spend already counted in
Incurred, not a fourth kind of it, and reading it as a peer invites adding it to a total it is already
inside.

### The finance roles cannot reach the finance module *(found 2026-08-18)*

`RoleAndPermissionSeeder` gives the **Accounts** role full petty-cash rights and **no
`finance.receivables.*` permissions at all**. Every receivables route is gated on those permissions,
so an Accounts user opening Client Billing gets a 403.

Proved rather than assumed: seeding the canonical role map into
`ProjectWorkflowContractsTest` changed nothing, because the roles genuinely do not carry the
permissions. Granting them explicitly cleared the 403s.

Combined with `finance.reports.view` being granted to Super Admin only (see the progress log above,
where the GL endpoints were deliberately scoped that way), the picture is that the people who do
finance work hold very little of the finance module's own permission set. Worth a deliberate pass over
`RoleAndPermissionSeeder` for Accounts and Finance rather than another endpoint-by-endpoint grant.

### Endpoints still without a caller, by intent

Verified at the service-method level rather than by string matching, because `pettyCashService`
builds its URLs from a `baseUrl`.

*Redundant — the UI gets the same answer another way, no action suggested:*
`GET /petty-cash/search` · `GET /petty-cash/disbursements/{id}` · `GET /petty-cash/top-ups/{id}` ·
`POST /petty-cash/balance/check` · `GET /petty-cash/top-ups/{id}/available-balance` ·
`POST /petty-cash/transactions/bulk-archive-groups` ·
`POST /petty-cash/requisitions/{id}/confirm-receipt` (the item-level route supersedes it)

*Genuine missing UI — a decision, not an oversight:*
- `GET /petty-cash/balance/trends` — 69 lines of monthly trend aggregation with no screen. The natural home is the Reports tab.
- `GET /finance/spend-vouchers/{id}` — returns the voucher with its payment source; there is still no voucher detail view.

### Still open from the items above

- **Item 11, screens.** The GL API has no UI. `CostVerificationView` still prints `journal_entry_no` as inert text with no drill-down, even though the endpoint behind it now exists.
- **Item 9, the rest.** No open / lock / close endpoints, so a period can still only be locked directly in the database.
- **Voucher ↔ cost line linkage.** The relation was removed rather than built. A voucher settling verified cost lines is what would give the module a payables sub-ledger and a three-way match; it needs the column, plus decisions on which lines a voucher may settle and what settling does to their status. That is a schema and workflow change, not a relation, and it is the single largest remaining hole in §1.

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
