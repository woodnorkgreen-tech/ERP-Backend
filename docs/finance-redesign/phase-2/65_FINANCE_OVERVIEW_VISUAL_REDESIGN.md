# 65 — Finance Overview Visual Redesign (Pass 1)

**Date:** 2026-09-29
**Environment:** local development / DDEV only
**Branch:** `finance/frontend-stream-e-spend-vouchers` in both repos (the current development branch; not merged to `master`)
**Production:** untouched. No deploy, migration, seed, journal, queue or cut-over.
**Migrations added:** none.
**Stream F:** paused, not started.
**Verdict:** see §28.

---

## 1. Executive Summary

The Finance Overview has been rebuilt in the reference's control-centre design language:

- a header of real control facts;
- four dense KPI cards;
- an action queue beside a context inspector, about 65/35;
- a project finance snapshot.

Every figure comes from a backend source. A new read projection, `GET api/finance/overview?section=…`, reuses the services the workspaces already trust, and the page computes no Finance total. Twelve reusable primitives now form the Finance control design system for later screens.

No fictional reference data, certification, feed or approval tier was copied.

## 2. Reference Design Analysis

What the reference is doing:

- **Canvas and cards:** a light blue-grey canvas (`#F8FAFC`) with white, square (2 px radius) cards on a soft shadow.
- **Type:** Hanken Grotesk for text, JetBrains Mono for every figure and identifier, and 11 px uppercase tracked section labels.
- **Numbers:** a large mono figure with a small toned metric beside it; 6 px bars with 2 px segment gaps.
- **Queue and table:** dense tables with a left state indicator on the active row, and pill tabs carrying count badges.
- **Inspector:** a sticky right column with the document header, evidence chain, posting breakdown and audit gate, and dark primary actions at the bottom.
- **Colour:** restrained: emerald for good, amber for pending, rose for exceptions, indigo for balanced or neutral facts.
- **Composition:** 12 columns, 8/4.

## 3. What Was Reproduced

The following were reproduced as a scoped design system (`finance-control.css`, under `.fin-ctl`):

- the canvas, card and shadow treatment, the uppercase labels and the tabular figures;
- the KPI card anatomy, including thin segmented bars;
- pill tabs with counts, the dense table with the active-row indicator, and compact tone chips;
- the evidence chain, the posting breakdown, and the audit-gate rows (green, amber, red, neutral);
- the 4-card ribbon, the 8/4 workspace with a sticky inspector, and a secondary insight panel below the queue.

Dark mode is included.

**Fonts: not reproduced, by decision.** After the first capture, WNG asked to keep the app's own slim type. The Finance workspace therefore uses the app's Poppins at its slim weights (body 300, UI 400, emphasis 500, strong 600), with tabular lining figures as `.fin-num` does. Hanken Grotesk was removed again, and JetBrains Mono is not used for figures.

## 4. What Was Deliberately Not Copied

**Sample data** (design reference only):

- **Names and banks:** JPMorgan, BAI2 and KCB feeds; every sample supplier; every sample person.
- **Numbers and references:** `PRJ-8042`, PO/GRN/invoice numbers, GL codes, USD amounts.
- **The KES/USD switch:** multi-currency is not implemented.

**Claims the system does not enforce:**

- "IFRS & Statutory Financial Controls Enforced", `REV-2025.2`, "Immutable WORM logging" and "Target Phase 2 Architecture";
- "4-Eye Gate ENFORCED", "Tier 3 (> $10k)" and "Postings blocked if ≠ 0";
- the "Zero-Variance Ledger Guard";
- the 7-year KRA archive statement;
- "Approve & Commit to Immutable Ledger".

**The reference's shell** (its top bar and sidebar): the WNG shell, `FinancePageFrame` and the existing Finance navigation are kept.

## 5. WNG Data Mapping

| Metric | Backend source | Calculation | Permission | Freshness | Unavailable state |
|---|---|---|---|---|---|
| Accounting period | `AccountingPeriod::forDate(now())` | label, status, days to period end | Overview access | per request | "None covers today" (red) |
| Currency | `FinanceOverviewController::BASE_CURRENCY` (journal `base_amount` is KES) | none | Overview access | static | — |
| WIP policy | `config('finance_accounts.wip_policy')` | none | Overview access | per deploy (config cache) | "NOT CONFIGURED" (amber) |
| Paying accounts | active non-payable `payment_sources` | count linked and paying / unlinked | Overview access | per request | 0 configured shown red |
| Ledger integrity | posted and reversed `journal_lines.base_amount`, as `trialBalance` totals them | Σdebit, Σcredit, difference, balanced | `finance.reports.view` | per request | badge hidden |
| Cash (ledger) | ledger balance of each **distinct** GL account linked to an active paying source | Σ(dr−cr) per account; type subtotals | `finance.reports.view` | per request | card hidden |
| Petty cash float | `petty_cash_balances` (read with `find`, never `firstOrCreate`) | none | `finance.petty_cash.view_balance` | per request | not shown |
| Receivables | `ReceivablesAgeingService::summary()` | overdue = non-current buckets | `finance.receivables.read` | per request | card hidden |
| Receipts to verify, unapplied money | `FinancePositions::receipts()` (shared with `ReceivablesController`) | as the workspace | `finance.receivables.read` | per request | — |
| Payables | `FinancePositions::payables()` (shared with `PayablesController`) | as the workspace | `finance.payables.read` | per request | card hidden |
| Portfolio direct cost, billed, margin | `CostAccountService::portfolioMargin()` over projects not financially closed with verified actual cost or posted billing (≤ 200) | sum per field | `finance.costs.portfolio` | per request | card hidden; "200+" when limited |
| WIP balance | ledger balance on the WIP-function accounts (`1211`–`1219`, through `ChartAccountMap`) | Σ(dr−cr) | `finance.costs.portfolio` | per request | — |
| Project snapshot | `ClientFinancialPositionService::forEnquiry()` + `portfolioMargin` `cost_completeness` | as the Project Billing screen | `finance.costs.portfolio` | per request | "No active project…" |
| Queue, tab counts | `FinanceWorkQueueService` (`work-queue`, `work-queue/count`) | server-side | per work type | per request | empty sentence |
| Inspector | the owning workspace's projection (W1–W4) plus the ledger journal by `source_ref` | re-labelled only | the projection's own | per selection | a plain summary with a link |
| Exceptions banner | readiness, paying sources, petty-cash, inventory and voucher signals (Reports 56, 61, 63) | as before | as before | per request | hidden |

## 6. Finance Overview Architecture

- **Backend:** `FinanceOverviewController::show`, reached at `GET api/finance/overview?section=controls|cash|receivables|payables|projects`.
  - One section per request, so each block loads, fails and is permission-gated on its own.
  - Overview access requires any of: reports, receivables, payables, spend-voucher, petty-cash or portfolio read.
  - Each section then checks its own permission.
- **Frontend:** `useFinanceOverview`'s blocks call one section each.
- **Removed:** the browser-side overdue arithmetic (`receivablesPosition`).
- **Queue:** the queue and the inspector are page state.

## 7. KPI Ribbon

The ribbon has four cards, each linking to the screen that owns its figure. A card the user may not see is not requested or rendered.

1. **Cash & liquidity → Ledger.**
   - Headline: the ledger total and the number of accounts.
   - Bar: by paying-account type, plus the float.
   - Footer: "Ledger balance, not a bank statement", and the unlinked count.
2. **Receivables → Invoices.**
   - Headline: outstanding, and the number of open invoices.
   - Bar: ageing buckets (current green, older amber, oldest red).
   - Footer: overdue value, unapplied money, receipts to verify.
3. **Owed to suppliers → Bills.**
   - Headline: outstanding, and the number awaiting verification.
   - Bar: verified-unpaid, awaiting verification, and returned.
   - Footer: ready to pay, overdue, returned.
4. **Project finance · direct cost → Cost accounts.**
   - Headline: direct cost, and the number of active projects.
   - Bar: billed against direct cost.
   - Footer: direct margin, WIP, incomplete costing.
   - Note: "Direct and provisional; not a fully loaded profit."

## 8. Finance Action Queue

- **Tabs:** All actions, plus the five work-queue areas, each with its backend count. Payroll appears only when payroll work exists.
- **Server-side:** the area filter and pagination (12 per page).
- **Columns:** reference (mono) with its kind, party and project, amount, workflow step, control state, age, and action.
- **Control chip:**
  - red only for a genuine failure (a failed posting);
  - amber for overdue or ageing;
  - neutral otherwise.
- **Selection:** clicking a row or pressing Enter loads the inspector. The first item is inspected automatically.
- **Voucher-only tab:** not added. The work queue filters by area, and vouchers sit in "Expenses & cash".

## 9. Context Inspector

The header shows the reference, kind, party, amount and the backend state chip. The sections are control chain, accounting impact and audit trail, and the footer holds the actions.

It reads the owning projection:

| Work types | Projection |
|---|---|
| `spend_voucher*` | `spend-vouchers/{id}/detail` (Report 63) |
| `supplier_invoice*`, `supplier_payment` | `payables/bills/{id}` (Report 60) |
| `invoice_*` | `invoices/{id}` (Report 58) |
| `client_receipt` | `receipts?receipt_id=` (Report 58) |
| `fund_*`, `petty_cash_posting_failed` | `petty-cash/finance/requisitions/{id}` (Report 61) |

Anything else (cost and labour verification, payroll, requisitions, orders) shows its queue summary and a link to act.

## 10. Evidence Chain

- **Supplier bill:** requisition → PO → GRN → bill → verification → payment. A direct bill shows the first three steps as not applicable, not as evidence.
- **Client invoice:** commercial basis (or the no-quote exception) → invoice → check → issue → receipt and allocation.
- **Receipt:** recorded → verification → allocation.
- **Voucher:** source liability → voucher → check → (senior) → post and pay → settlement.
- **Cash requisition:** request → approval → disbursement → advance posting → surrender → reconciliation.

A step is done only when its record exists. Refusals, voids, reversals and failed postings show as failed.

## 11. Accounting Impact

`FinancePostingBreakdown` finds the ledger's own journal for the document (`journals?search=` with an exact `source_ref` match), then shows its DR/CR lines and the entry's own `total_debit` / `total_credit`. It marks the entry BALANCED only when the two are equal.

- **No journal:** "Not yet posted."
- **No `finance.reports.view`:** "Ledger detail needs Finance report access."

No preview is computed. No backend posting preview exists, so none is shown.

## 12. Audit Trail

The audit trail uses real actors and timestamps only:

- **Voucher:** the review history.
- **Invoice and cash requisition:** their audit rows.
- **Bill:** prepared, returned, verified, and each posting.
- **Receipt:** recorded and verified.

Green means done, amber pending, red refused or failed. No actor is invented.

## 13. Project Finance Snapshot

The snapshot shows the active project with the highest direct cost; a specific project can be requested with `?project=`. Its figures come from `ClientFinancialPositionService` and are the Project Billing screen's own:

- approved quote value;
- invoiced, with its bar against the remaining amount to invoice;
- cash received, with its bar against the outstanding amount;
- invoice outstanding;
- direct cost and its basis;
- direct margin, labelled provisional.

Every `not_included` category is named ("LOGISTICS NOT INCLUDED", "OVERHEAD NOT INCLUDED"), with "Direct margin only; fully loaded margin is not available".

## 14. Ledger Integrity

The header badge reads **BALANCED**, or **EXCEPTION** with the difference, from the same lines and totals as the trial balance. It is shown only to users with reports access. It makes no claim that postings are blocked: that is a property of `JournalPostingService::postBalancedEntry`, not of this badge.

## 15. Permissions

| Permission | Unlocks |
|---|---|
| Overview access (any of 6 Finance read permissions) | the endpoint |
| none beyond access | `controls`: period, currency, WIP policy, paying-account configuration |
| `finance.reports.view` | the ledger totals in `controls`; the `cash` section |
| `finance.petty_cash.view_balance` | the float |
| `finance.receivables.read` | `receivables` |
| `finance.payables.read` | `payables` |
| `finance.costs.portfolio` | `projects` |

The queue and the inspector keep their own projections' permissions.

## 16. Backend Changes

**New:**

- `FinanceOverviewController`, and the route `GET api/finance/overview`;
- `Support\FinancePositions`, which holds the payables summary and the receipt totals. The one query per figure now lives here, and `PayablesController` and `ReceivablesController` delegate to it.

There are no writes and no migrations.

## 17. Frontend Changes

- **Rewritten:** `overview/views/FinanceOverviewView.vue` and `overview/useFinanceOverview.ts` (backend sections; the overdue arithmetic is removed).
- **New:**
  - `overview/inspector.ts`, the adapters;
  - `shared/styles/finance-control.css`;
  - `shared/components/control/*`.
- **Font:** none added. `index.html` is unchanged; the app's slim Poppins is kept (§3).

## 18. Shared Design Components

`shared/components/control/`:

| Component | Role |
|---|---|
| `FinanceControlHeader` | the control header |
| `FinanceControlBadge` | one control fact |
| `FinanceKpiCard` | the KPI card |
| `FinanceMetricBar` | the thin distribution bar |
| `FinanceQueueTabs` | tabs with counts |
| `FinanceSkeleton` | loading placeholders shaped as a card, rows or a panel |
| `FinanceExceptionBanner` | exceptions |
| `FinanceInspector` | the inspector frame |
| `FinanceInspectorSection` | an inspector section |
| `FinanceEvidenceChain` | the chain |
| `FinancePostingBreakdown` | the journal |
| `FinanceAuditTrail` | the audit gate |
| `FinanceProjectSnapshot` | the project snapshot |

A dense-table component was not added: the table treatment is the `ctl-table` class set, which any table can adopt.

## 19. Responsive Behaviour

| Width | Layout |
|---|---|
| Desktop (≥ 1280 px) | 4 KPI cards; 8/4 queue and sticky inspector |
| Tablet | 2×2 KPI; queue, then inspector |
| Mobile | one column; tabs scroll horizontally; the table scrolls horizontally with references kept on one line; inspector below |

Verified by screenshots at 1600, 900 and 400 px (§24).

## 20. Tests

**Backend:** `FinanceOverviewTest` (5) proves:

- each section's permission gate, and that a role name alone is refused;
- `controls` equals the trial balance's totals;
- cash counts a shared GL account once, hides the float without permission, and writes nothing;
- receivables and payables equal their own workspace endpoints;
- projects exclude financially closed work, stay direct and provisional, carry not-included flags, and honour `?project=`.

The Payables and Receivables workspace suites re-pass after the refactor: 31 tests together with the Overview test.

**Frontend:**

- **`overview.spec.ts`** (18). The screen:
  - requests only permitted sections;
  - builds the header from facts only, with no reference claim (IFRS, WORM, JPMorgan, BAI2, Tier 3, 4-Eye, USD) anywhere;
  - shows an unconfigured WIP policy and a ledger exception as such;
  - shows the KPI figures in KES with their links;
  - keeps one failing card from taking down the others;
  - shows the snapshot as direct and provisional with its not-included chips;
  - takes tab counts from the server and asks for the right area;
  - gives the generic inspector a link only;
  - shows the voucher inspector's chain, audit and allowed actions only;
  - shows the journal breakdown from the ledger's own entry with exact reference matching;
  - has empty and loading skeletons.

  It also keeps the configuration, ledger-exception and My Actions tests.
- **`shared/components/control/control.spec.ts`** (9) covers:
  - the primitives: bar proportions, KPI states, chain and audit states, the ledger permission gate, no fully loaded claim;
  - the adapters: direct-bill steps not applicable, a voided invoice, a failed advance posting, receipt lookup.
- **Mutation check:** showing every inspector action instead of only allowed ones fails 2 tests.

## 21. API Contract

**0 unmatched.** 1,404 routes were matched against 197 direct calls and 65 petty-cash service calls. `api/finance/overview` is matched.

## 22. ENG-1

`vue-tsc --build --force`: **267 → 267, 0 new diagnostics.** The comparison was a normalised set against the Stream E starting tree.

## 23. Build

`vite build` **PASS** (32.4 s).

## 24. Visual Validation

**Performed safely.** A throwaway harness mounted the real `FinanceOverviewView` with fixture data: neutral names, no reference data, the API stubbed, and no network, backend or production. Headless Chrome captured it at 1600×1000, 900×1300 and 400×1800. The harness was deleted afterwards and never committed.

Compared against the reference:

- **Information density, card proportions, type hierarchy (caps labels, mono figures) and number alignment:** match.
- **65/35 composition with a sticky inspector:** matches.
- **Status hierarchy:** red appears only on the failed posting.
- **Whitespace:** the reference is slightly denser. The WNG page frame keeps its header and navigation above.

Fixed from the first capture:

- KPI figures sat at uneven heights; the bars now anchor to the bottom of each card.
- A truncated bar label on the Project card.
- A wrapped amount in the selected row.
- References wrapping on tablet.

Screenshots are at `~/.cache/erp-verify/stream-e/overview-{1600x1000,900x1300,400x1800}.png`.

## 25. Files Changed

**Backend:**

- New: `app/Modules/Finance/Controllers/FinanceOverviewController.php`, `app/Modules/Finance/Support/FinancePositions.php`, `tests/Feature/Finance/FinanceOverviewTest.php`, and this report.
- Modified: `PayablesController.php`, `ReceivablesController.php`, `routes/api.php`.

**Frontend:**

- New: `src/modules/finance/overview/inspector.ts`, `src/modules/finance/shared/styles/finance-control.css`, and `src/modules/finance/shared/components/control/` (13 components and `control.spec.ts`).
- Modified: `overview/views/FinanceOverviewView.vue`, `overview/useFinanceOverview.ts`, `overview/overview.spec.ts`.

## 26. Known Limitations

1. **Inspector actions are links, not in-place buttons.** Each allowed action opens the document's own screen, where its dialogs, reasons and controls apply. Executing from the inspector would duplicate each workflow's write path.
2. **Cash is a ledger figure.** Bank balances are not held in the system and opening balances are not loaded, so the card says so plainly.
3. **Projects are limited to 200** (`portfolioMargin`'s own cap). Beyond that, the card shows "200+".
4. **No posting preview for unposted documents,** because no backend preview exists.
5. **Queue items without a workspace projection** (cost and labour verification, payroll, procurement requisitions and orders) get a summary and a link only.
6. **No "Payment vouchers" queue tab.** The work queue filters by area. A type-group filter would be a small backend addition.
7. **Visual validation used fixtures,** not a logged-in session on real data.

## 27. Recommended Next Display

**Payment Vouchers (W4)**, on these primitives:

- `FinanceKpiCard` for the register summary;
- `FinanceQueueTabs` with `ctl-table` for the register;
- `FinanceInspector`, `FinanceEvidenceChain`, `FinancePostingBreakdown` and `FinanceAuditTrail` for the detail.

Its projection already carries everything the inspector needs. After that: Purchasing & Payables, then Sales & Receivables.

## 28. Final Verdict

### FINANCE OVERVIEW REDESIGN COMPLETE — REFERENCE DESIGN SYSTEM READY FOR ROLLOUT

The Overview reproduces the reference's design language on WNG's own shell and data:

- every KPI maps to a named backend authority (§5), shared with its workspace wherever one exists;
- no fictional data or unenforced claim appears;
- completed controls are untouched.

Tests, API contract (0 unmatched), ENG-1 (0 new) and build pass. Backend full suite: **1,611 passed, 0 failed** (11,613 assertions). The limitations in §26 are design choices or backend capabilities that don't exist yet, not data-mapping gaps.
