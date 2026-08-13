# Cost Capture — implementation audit (backend + frontend)

Date: 2026-08-11
Scope: `app/Modules/Finance/CostCollector/**`, `JournalPostingService`, `routes/api.php` costs block,
`ERP-Frontend/src/modules/finance/cost-collector/**`.

The design is sound — one ledger, `nature` separating budget from spend, append-only with
reversals, a real policy layer. The gaps below are not architectural; they are unfinished
edges, and several of them are load-bearing for month-end.

---

## Status

Phase 1 and Phase 2 were both implemented on 2026-08-11 — see the closing sections.
Items marked ✅ below are done and covered by tests; everything else stands.

Phase 2's brief was different from phase 1's. Phase 1 was "the ledger is wrong";
phase 2 was "the screens are too vague to work from" — a verifier was being asked
to approve payments without being told who was paid, and the module's headline
signal (unbudgeted spend) had no way to be resolved.

---

## A. Accounting correctness — fix before anyone trusts the GL

### ✅ A1. Tax is subtracted from the cost but never posted anywhere (BLOCKING) — FIXED

`postCostLine` posted a two-leg entry where **both legs were `net_amount`**.

For a KES 116 receipt with 16 VAT:

| | current | correct |
|---|---|---|
| Dr Expense/WIP | 100 | 100 |
| Dr VAT input receivable | — | 16 |
| Cr Cash/Payable | 100 | 116 |

The entry balances internally, so nothing errors — but the credit understates what was
actually paid by exactly the tax. Cash/payable will never reconcile to the bank, and
recoverable input VAT never reaches an account, so there is no VAT return to file from
this data.

**Fixed:** the credit leg now carries what actually left, and a third leg debits the VAT
input account resolved from `vat_treatment_id`.

### ✅ A2. Withholding is computed, stored, and then dropped (BLOCKING) — FIXED

`withholdingAttributes()` (`CostVerificationService.php:320-340`) resolves the WHT category
from the supplier + expense code and prices `wht_amount`. `postCostLine` ignores it entirely.

Consequences: no WHT liability on the balance sheet, nothing to remit to KRA, no data for
supplier WHT certificates, and the supplier's payable is overstated by the withheld amount.

**Fixed:** the credit is split — Cr WHT payable `wht`, Cr Payable the balance. A WHT payable
schedule/report keyed on `wht_category_id` and `posting_date` is still outstanding (phase 3).

### A3. Procurement spend terminates at `accrued` — no invoice ever lands

The chain that exists: requisition → `committed` → GRN accepted → `releaseCommitment()` →
`accrued` (`ProcurementCostProducer.php:41`, `:85-99`).

Nothing posts an `actual` from a supplier invoice, and there is no `releaseAccrual()` —
only `releaseCommitment()` exists (`CostCollectorService.php:149`). The migration comment
promises accruals are "reversed when the actual lands, so the two can never both count"
(`create_cost_lines_table.php:18-19`); that code was never written.

So every procurement cost sits as an accrual permanently. `CostAccountService` sums
`actual + accrued + committed` as "spent", so the project total is right today — but the
moment an invoice producer is added without a release step, everything double-counts.

**Fix:** a `SupplierInvoiceCostProducer` that posts `actual` and calls a new
`releaseAccrual()` in the same transaction, matched on `details->purchase_order_item_id`.

### A4. `posting_date` is never used; a late receipt for a closed month is a dead end

`postCostLine` derives the JE posting date from `incurred_at` (`JournalPostingService.php:43`)
and inherits `accounting_period_id` from the line, which `CostContextResolver.php:57` resolved
from `incurred_at` at capture. The `posting_date` column is written by nobody.

`assertPeriodOpen()` then refuses to verify a line whose period is closed
(`CostVerificationService.php:370-383`) — with no way to re-date it into the current open
period. A receipt that surfaces in September for an August cost becomes permanently
unverifiable.

**Fix:** let verification accept an optional `posting_date` (defaulting to `incurred_at`,
falling forward to the current open period when that one is closed), and post the JE on
`posting_date` while keeping `incurred_at` as the economic date.

### A5. No accounting-period management exists at all

`AccountingPeriod` models `open|locked|closed` and carries `locked_by`, `locked_at`,
`reopened_by`, `reopen_reason` — but there is **no controller, no route, no UI**. Periods
only ever come from `AccountingPeriodSeeder`. Nobody can close a month, so `assertPeriodOpen`
never actually fires and there is no month-end close.

**Fix:** period CRUD + lock/close/reopen endpoints behind a new `finance.periods.manage`
permission, and a Finance → Periods screen showing per-period unposted/unverified counts as
a close checklist.

### A6. WIP is never relieved to cost of sales

`cos_transferred_at` / `cos_transfer_ref` exist and nothing writes them. Verified costs stay
in WIP forever; there is no project-closeout or revenue-recognition transfer. Acknowledged in
the migration as a deliberate hook — flagged here so it stays on the list.

### A7. FX: the journal totals are in transaction currency

`total_debit`/`total_credit` on the entry are `net_amount` (transaction currency) while the
lines carry `base_amount` separately (`JournalPostingService.php:50-51` vs `:65`). A
mixed-currency trial balance summed on entry totals will be wrong. No revaluation and no
FX gain/loss account either.

---

## B. Controls and authorization

### ~~B1. A verifier can edit an amount and then verify it~~ — WITHDRAWN, not a hole

The policy is permissive — `resubmit` allows anyone holding `finance.costs.verify`
(`CostLinePolicy.php:62-66`) — but `correctQueried()` independently refuses anyone who is not
the original reporter (`CostCollectorService.php:123-128`), so the service closes what the
policy leaves open. The only residual is cosmetic: a verifier hitting `correction` on someone
else's line gets a 422 rather than a 403.

### ✅ B2. `budgetLines` is unauthorized — any logged-in user can read any project's budget — FIXED

`CostLineController::budgetLines()` (`:149`) has no `authorize()` call and sits behind only
`auth:sanctum` + `active`. `GET /api/costs/budget-lines/{enquiry}` returns every planned line
with budgeted, spent and remaining for any enquiry id. Budget figures are commercially
sensitive.

**Fixed:** gated on `finance.costs.create`, waived for holders of `finance.costs.read`. Scoping
the list to projects the caller is assigned to is still worth doing.

### ✅ B3. `query`, `reject` and `reverse` are unlocked and untransacted — FIXED

Only `verify()` re-reads under `lockForUpdate` (`CostVerificationService.php:60`).
`query()` (`:182`), `reject()` (`:199`) and `reverse()` (`:221`) check
`canTransitionTo()` on the route-bound instance, then `forceFill(...)->save()` unconditionally.

Concurrent `verify` + `reject` on the same line: verify locks, posts the journal, commits;
reject then writes `status = rejected` over it from a stale read. Result is a rejected cost
line with a posted, un-reversed journal entry behind it.

**Fixed:** all four now re-read under `lockForUpdate` inside a transaction, `resubmit`
included — it read-modify-writes `capture_meta`, so a race there silently dropped one of two
query responses.

### ✅ B4. Money-moving endpoints are unthrottled — FIXED

`store` and `evidence` carry `throttle:60,1`; `verify`, `query`, `reject`, `reverse`,
`resubmit` and `correction` carry none (`routes/api.php:169-176`).

### ✅ B5. Rejection is terminal, one-click, and unconfirmed — FIXED

`rejected` has no outbound transitions (`CostLine.php:44`). The UI rejects on a single button
plus a `window.prompt` (`CostVerificationView.vue:271-275`), with no confirmation and no
minimum on the reason. A misclick permanently destroys a cost record.

---

## C. Missing lifecycle stages

| Gap | Detail |
|---|---|
| **No payment/settlement state** | `verified` recognises the expense; nothing records that the payable was settled. `voucher_id`/`funding_voucher_id` exist but no AP aging, no payment run, no "paid" status. |
| **No reclassification of unbudgeted spend** | `consumes_line_id` can only be set at capture. There is no endpoint to attach a budget line afterwards. The verifier sees the "unbudgeted" chip and can only query it back — so the design's headline signal has no resolution path. |
| **No 3-way match** | GRN → accrual posts on accepted quantity alone. No PO/GRN/invoice quantity-and-price match, no tolerance rules. |
| **No `show` endpoint** | Every read is a list. No single-cost-line drill-down, so no full history view anywhere. |
| **No audit-trail read** | Self-verification overrides land in `HRAuditLog`; nothing reads them back. `capture_meta.revisions` and `query_responses` are exposed only as `latest_*` — earlier entries are unreachable. |

---

## D. API surface

### ✅ D1. `CostLineResource` omits most of the record — FIXED

Missing from the payload, all present on the table:

- **Who was paid** — `payee_name`, `payee_type_id`, `payee_id`, supplier
- **Coding** — `cost_centre_id`, `activity_id`, `cost_cause_id`, expense code `code`
- **Tax** — `wht_amount`, `wht_category_id`, `vat_treatment_id`
- **Budget** — `consumes_line_id`, the consumed line's description, `budget_remaining_before`
- **Quantity** — `unit`, `quantity`, `unit_rate`
- **GL** — `posted_at`, `journal_entry_id`, entry no, `accounting_period_id`
- **Provenance** — `source_type`/`source_id` (producer vs human capture), `voucher_id`, `funding_voucher_id`
- **FX** — `fx_rate`, `base_net_amount`
- **Other** — `verified_by` name, `created_at`, `site`, `asset_id`

The consequence that matters: **the verification screen cannot show the verifier who
received the money.** It shows expense type, job, reporter and amount. Approving a payment
without the payee is not a review.

### ✅ D2. Cost-accounts filtering is a stub — FIXED

`CostAccountController::index` validates only `per_page`. `CostAccountService::index(array $filters)`
never applies `$filters` to the query, and `grandTotals(array $filters)` never reads its
parameter at all (`CostAccountService.php:90-121`) — dead plumbing.

Nothing can be filtered by period, date range, project status, cost centre, or job number,
and there is no sort control or search.

### ✅ D3. Verification queue filters are minimal — FIXED

Accepted: `status`, `job_number`, `unbudgeted`, `per_page`. Order is hardcoded `orderBy('id')`.

Missing: date range on `incurred_at`, amount range, expense code/family, cost centre,
project/enquiry, `submitted_by`, payee, has-evidence, currency, ageing bucket, sort.

Also `meta.awaiting` is a global count that ignores the applied filters
(`CostVerificationController.php:55-56`), so the header contradicts the list.

### ✅ D4. No queue aggregates — FIXED

Only a count. No total value awaiting, no ageing buckets (>7d / >30d — the stated risk in
the controller's own docblock is "a backlog ageing quietly", and nothing measures it), no
unbudgeted value, no per-verifier workload.

### D5. No export anywhere

No CSV, Excel or PDF in the whole module. Finance needs at minimum: cost account per project
(client/PM handout), verification queue, unbudgeted spend report, WHT return schedule, VAT
input schedule, GL posting reconciliation.

### D6. No bulk actions

Verifying a 200-line backlog is 200 requests and 200 clicks.

---

## E. Frontend

### ✅ E1. `window.prompt` collects audit-record reasons — FIXED

Query, reject, reverse and the self-verification override all use `window.prompt`
(`CostVerificationView.vue:88`, `:107`). No validation feedback, no character counter against
the 15-char override minimum, no multiline, unstyled, dismissible by accident, and blocked
outright in some browsers. These strings are permanent audit records.

**Fix:** a proper reason modal with the minimum enforced inline.

### ✅ E2. Two of the three tax inputs are unreachable — FIXED

The API accepts `tax_amount`, `vat_treatment_id` and `wht_category_id`
(`CostVerificationController.php:65-73`). The UI only ever sends `tax_amount`
(`CostVerificationView.vue:66-72`). VAT treatment cannot be chosen; WHT category is resolved
silently server-side with no preview and no way for Finance to see or override the withholding
before it is committed.

### ✅ E3. Nothing is paginated — FIXED

`meta.current_page` / `last_page` come back from all three endpoints and every view ignores
them:

- Verification queue — default 25/page, no controls. A backlog past 25 is invisible, and the
  "Value on this page" tile silently means "value of the first 25".
- My submissions — `mySubmissions()` called with no params at all (`CostCollectorIndex.vue:73`).
- Cost accounts — `per_page: 100` hardcoded (`CostAccountsView.vue:49`).

### ✅ E4. Rejected and reversed costs are unreachable in the UI — FIXED

The queue toggle is binary `awaiting | verified` (`CostVerificationView.vue:31`). `draft`,
`rejected` and `reversed` lines cannot be viewed on any screen, so there is no audit view of
what was refused or backed out.

### ✅ E5. Evidence cannot be previewed in place — FIXED

Attachments render as new-tab links (`:286-290`). Checking a receipt against an amount means
leaving the screen and coming back. Needs an inline image/PDF viewer beside the amount field —
this is the single highest-frequency action on the page.

### ✅ E6. Currency is cosmetic — FIXED

`MoneyValue` defaults to KES and no caller passes `:currency`, while `formatMoney` hardcodes
`en-KE`/KES. `CostLine.currency` is in the type and rendered nowhere. A USD cost displays as
KES with no indication.

### ✅ E7. Cost account panel has no drill-down — FIXED

Category totals and the unbudgeted list are shown; clicking a category shows nothing. There is
no path from a variance to the lines causing it — the question the screen exists to answer
stops one level short.

### E8. Smaller items

- Cost accounts grid fetches `status` per row and never displays it; closed and cancelled
  projects mix into the list with no filter.
- Errors surface only in a page-level banner; the failing row is not marked.
- `taxAmounts` is never cleared after a successful verify.
- No filters on the queue beyond "unbudgeted only", though the API already supports
  `job_number`.

---

## F. Fields and filters a finance user will ask for

**Verification queue** — columns: payee, cost centre, budget line + remaining, evidence count,
age in days, WHT preview, currency. Filters: date range, amount range, expense family, cost
centre, project, submitter, payee, has-evidence, ageing bucket, currency. Aggregates: total
awaiting value, ageing split, unbudgeted value.

**Cost accounts** — filters: period/date range, project status, cost centre, client, job
search, overrun-only, has-unbudgeted-only. Columns: project status, last cost date, coverage %.
Sort on any money column. Export.

**Per-project account** — drill from category to lines; committed/accrued split shown
separately from actual rather than folded into "spent"; a movement view over time; print/export.

**New screens needed** — Accounting periods (close checklist), WHT payable schedule, VAT input
schedule, AP aging once payment state exists, cost line detail with full revision + audit
history.

---

## Suggested order

**1 — Correctness, before the GL is relied on** — ✅ DONE 2026-08-11
A1 VAT posting · A2 WHT posting · B3 transition locks · B2 budgetLines authorization
(B1 withdrawn — the service already enforced it)

**2 — Makes verification a real review**
D1 fat resource (payee first) · E2 VAT/WHT controls · E1 reason modal · E5 evidence preview ·
E3 pagination · B5 reject confirmation

**3 — Closes the accounting month**
A5 period management · A4 posting_date · A3 invoice producer + releaseAccrual · D5 exports

**4 — Scale and completeness**
D3/D4 queue filters + aggregates · D2 cost-account filters · C reclassification endpoint ·
D6 bulk verify · E4 audit views · E7 drill-down · A6 WIP relief

---

## Phase 1 changes (2026-08-11)

**Schema.** `2026_08_11_000300_add_gl_accounts_to_tax_tables` adds a nullable
`gl_account_id` to `vat_treatments` and `wht_categories`. The account sits on the rate row
rather than in settings because it already varies with the rate, and effective dating then
carries the mapping for free. Null is meaningful: it says the tax is not separately
recoverable, which is exactly the non-recoverable, exempt and out-of-scope treatments —
their VAT stays inside `net_amount` and posts to the expense account with everything else.

`FinanceTaxSeeder` points `STD16-REC` and `ZERO` at `1330 Input VAT Recoverable`, and
`PROF-RES` / `CONTRACT-RES` at `2120 Withholding Tax Payable` — the accounts the expense
catalogue already named in `NE-013` and `NE-014`.

**Posting.** `JournalPostingService::postCostLine` now builds up to four legs:

```
Dr  Expense / WIP          net_amount
Dr  Input VAT recoverable  tax_amount        (recoverable VAT only)
Cr  WHT payable            wht_amount        (retained, owed to KRA)
Cr  Cash / Payable         net + tax − wht   (what actually leaves)
```

The settlement credit is the balancing figure rather than an independently computed one, so
rounding between the tax legs cannot unbalance the entry. Gross is derived as `net + tax`
rather than read from `amount`, so a legacy row whose `amount` drifted still posts balanced.
Account resolution reads the treatment/category on the line first and falls back to chart
code, throwing an actionable configuration error only if both miss. `reverseCostLine` needed
no change — it already mirrored every leg generically, so tax legs reverse correctly.

**Locking.** `query` and `reject` moved onto a shared `transition()` helper that re-reads
under `lockForUpdate`; `reverse` and `resubmit` gained the same. `reverse` also no longer
checks the period outside the transaction.

**Authorization.** `budgetLines` gained a permission check.

**Tests.** `tests/Feature/Finance/CostLineTaxPostingTest.php` (8 new) pins leg shape, not just
balance — the old entry balanced perfectly and was still wrong. Three race-guard tests in
`CostVerificationTest` and one authorization test in `CostCollectorApiTest`. Full
`tests/Feature/CostCollector` + `tests/Feature/Finance` + `tests/Feature/PettyCash` green
(124 + 33 passing).

**Not addressed in phase 1.** A7 stands — journal `total_debit`/`total_credit` remain in
transaction currency while legs carry `base_amount` separately, so a mixed-currency trial
balance summed on entry totals is still wrong.

---

## Phase 2 changes (2026-08-11)

The brief: make the screens practical. A verifier could see what was bought and who
typed it in, but not who was paid, which budget line was drawn down, or what tax was
being split off — and had no way to resolve the unbudgeted spend the module exists to
surface. Everything below follows from those.

### The payload (D1)

`CostLineResource` went from 20 fields to the whole record: payee (name, type, and
whether it resolves to a supplier), coding (cost centre, activity, cause, expense
family), tax (`wht_amount`, both treatment ids with their rates), the consumed budget
line with its description and budgeted figure, quantity, FX, GL (`journal_entry_no`,
`posted_at`, period), provenance, and two derived fields that did not exist anywhere:

- `payable_amount` — gross less withholding, the credit leg `postCostLine` builds.
  Derived, not stored, because computing it in two places is how two places disagree.
- `age_days` — measured from `incurred_at`, not `created_at`. A receipt keyed in three
  weeks late is already three weeks old, and dating the queue from capture hid exactly
  the delay the queue exists to expose.

The four dimension tables (`cost_centres`, `activities`, `cost_causes`, `payee_types`)
have no Eloquent models — deliberately; they are rows Finance adds. `CostLine::scopeWithReferenceNames()`
resolves their names with correlated subselects instead, so the queue costs one query
rather than four eager loads per row. The supplier-name subselect is guarded on
`payee_types.requires_supplier_record`, because `payee_id` is a bare integer whose
meaning depends on the payee type — joining it straight to `suppliers` would print a
supplier's name against an employee sharing the id.

**Bug found while testing this.** The `consumes_line_*` subselects read `cost_lines`
from inside a query on `cost_lines`, and without an alias the WHERE compared each row
to itself, so the budget-line lookup could never match. Aliased to `planned_line` /
`planned_budget`.

### Queue filters and aggregates (D3, D4)

New `CostQueueQuery` holds one definition of the queue, used by both the rows and the
figures above them. That is the point: `meta.awaiting` previously counted every open
cost in the system while the table showed a filtered subset, so "42 awaiting" sat above
eleven rows and neither number explained the other.

Filters: status (including `all`, `rejected`, `reversed`), date range, amount range,
expense code and family, cost centre, project/enquiry/job, submitter, currency, origin,
free-text search across ref/description/job/payee/submitter, unbudgeted, has-evidence
(three-valued — absent means "do not narrow", `false` is a real filter), age bucket, and
sort on five columns.

Aggregates: count and value, unbudgeted count and value, distinct submitters, and three
ageing buckets (under a week / 1–4 weeks / over a month) with count and value each. The
ageing bar is computed over every filter EXCEPT the age filter, because the bar is also
the control that sets it — intersecting it with its own selection would zero the two
buckets you would click back to.

### One tax pricer, two callers (E2)

`CostVerificationService::taxAttributes` and `withholdingAttributes` moved into
`CostTaxPricer`. `GET /costs/verification/{cost}/tax-preview` and `verify` now run the
same code, so the split and the four journal legs shown before clicking are what gets
written. A preview computed separately from the commit is worse than no preview — it
invites approving a figure the ledger will not post. `CostVerificationReviewTest` pins
that equivalence directly.

The preview resolves options on the cost's own date, not today's — both tax tables are
effective-dated, so offering the current rate for an August receipt would price it under
a rule that did not apply. Each WHT option comes back priced against the cost
(`would_withhold`), so a category whose threshold the payment sits under reads as 0.00
before it is chosen rather than after.

**Bug found while testing this.** `tax_amount` was passed through as `(string) 1600` →
`"1600"`, so the preview showed `1600` where the column's cast stored `1600.00`. Now
normalised to 2dp in the pricer.

### Reclassification — the resolution path unbudgeted spend never had (C)

`POST /costs/verification/{cost}/reclassify` points a cost at a budget line, or detaches
it. `consumes_line_id` could only be set at capture, so a verifier who knew perfectly
well where a cost belonged could only query it back and hope. Unbudgeted spend
accumulated permanently and the panel listing it only ever grew.

It is a re-classification, not a re-pricing — no amount moves, so the journal is
untouched and no reversal is needed. That is what makes it safe after verification,
which matters because the person who knows the right budget line is usually Finance
reading it afterwards, not the technician who paid on site. Refused for planned lines,
rejected and reversed costs, no-op changes, and budget lines belonging to another
project. The budget snapshot is recomputed excluding the line's own draw; the change,
its reason and its author append to `capture_meta.reclassifications`.

### Cost accounts (D2, E7)

`CostAccountService::index`/`grandTotals` accepted a `$filters` array and never read it —
and its one written branch filtered on a `projectEnquiry` relation that did not exist on
the model, so it would have thrown had anything ever passed a filter. Relation added;
filters now real: project status, cost centre, date range, project search, plus
`overrun_only` and `unbudgeted_only` as HAVING conditions (they are properties of the
summed row, so they cannot be applied before the GROUP BY). Sort on any money column in
SQL across every page, and `last_cost_at` per row to tell a finished project from a
forgotten one.

`GET /costs/account/{enquiry}/category-lines` returns budget and spend as two lists, so
there is finally a route from "materials is 40% over" to the costs that put it over.

### Screens

- `CostVerificationView` rebuilt: payee column, budget line with remaining, ageing per
  row, origin and exception chips, journal reference, pagination, and a review drawer
  with the receipt inline beside the amount.
- `CostReasonModal` replaces `window.prompt` for query/reject/reverse/override —
  minimum length enforced while typing, character counter, and a named consequence to
  acknowledge before anything irreversible. Reject previously fired on one click.
- `EvidenceViewer` — images inline, PDFs in a frame, click to enlarge. Checking a
  receipt against an amount was a round trip out of the app and back.
- `CostTaxPanel` — VAT treatment and WHT category selects (both accepted by the API
  since day one and sent by nothing), live split, and the journal on request.
- `CostReclassifyPanel` — budget line picker with the resulting remaining figure.
- `QueueFilterBar` — filters with a count badge, so a filter left applied behind a
  closed panel cannot silently narrow the screen.
- `CostAccountPanel` — accrued split out into its own column and tile (it was part of
  every "spent" total and appeared in none of them), and each category opens to its lines.
- `CostAccountsView` — filters, sort, pagination. Was pinned at `per_page: 100`, which
  is a page limit dressed as "all of them".
- `CostCollectorIndex` — paginated with server-side totals, so "reported by you" cannot
  quietly become "reported by you, on this page". Payee column added. The "what happens
  next" box now explains all four states a reporter can land in, including the two that
  need an action from them.
- `formatMoney` and `MoneyValue` take the currency. A USD cost rendered with a KES
  symbol is not a formatting slip, it is a wrong number on a finance screen.

### Unrelated bug fixed in passing

`StoresCostProducer::postStockIssue` eager-loaded `material.expenseCode`, a relation
that does not exist — library materials carry no expense code, only requisition items
do. Every stock issue threw `RelationNotFoundException` before reaching the catalogue
fallback below it, so **stores spend never produced a cost line at all**. Its own test
had been failing. Now loads `material` only and reads the code from the catalogue.

### Tests

`CostVerificationReviewTest` (30 new) — payload completeness, filter-aware aggregates,
ageing buckets, each filter, sorting, pagination, tax preview vs commit equivalence, and
eleven reclassification cases. `CostAccountTest` +6 for the drill-down and grid filters.

`tests/Feature/CostCollector` + `tests/Feature/Finance` + `tests/Feature/PettyCash`:
**180 passing, 2,883 assertions.** Frontend `vue-tsc` clean.

### Still outstanding after phase 2

Phase 3 is unchanged and is now the whole remaining risk: A5 period management (no
month-end close exists), A4 `posting_date`, A3 supplier-invoice producer plus
`releaseAccrual()`, D5 exports (no CSV/Excel/PDF anywhere in the module), A6 WIP relief,
A7 FX entry totals, D6 bulk verify, and the payment/settlement state in section C.
