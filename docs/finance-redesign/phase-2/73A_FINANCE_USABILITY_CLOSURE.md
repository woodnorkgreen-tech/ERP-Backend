# REPORT 73A — FINANCE USABILITY CLOSURE

**Date:** 2026-10-03
**Baseline:** Report 73 — Finance Control Centre (commit `5a5a3db` backend, `bb2e2af` frontend)
**Scope:** Close the four software/usability gaps documented in Report 73 §39.
**Accounting policy, Chart of Accounts, historical balances, and W1–W7 architecture: unchanged.**

---

## 1. SCOPE — GAPS FROM REPORT 73 §39

| # | Gap | Requirement |
|---|-----|-------------|
| 1 | Extended Search | Add Projects, Clients, Suppliers, Journals, Payroll runs to the federated search. No HR-private salary/bank data. |
| 2 | Mobile Secondary Screens | Inventory, payroll, report, supplier-detail and legacy expense tables must convert to mobile cards. |
| 3 | Payroll Readiness Panel | Setup must include a permission-scoped payroll readiness subpanel (aggregate only). Policy register must use authoritative source or document the gap. |
| 4 | Interactive Action Audit | Complete audit of all legacy forms. Zero fake/dead actions. Record story timelines usable. Related record navigation verified. |

---

## 2. PRESERVED ARCHITECTURE

All of the following are unchanged:

- Navigation sections: Overview · Money in · Money out · Projects · Cash & banks · Payroll · Inventory · Reports · Setup
- W1–W7 accounting and control architecture
- `JournalPostingService`, `assertOpenPeriod`, `ChartAccountMap`, `FinanceAccountFunctions`
- Existing reversals, corrections, maker/checker rules, audit records
- W1–W7 permissions, Single Economic Cost protections
- `FinanceControlCentreService.php`, `FinanceReadiness.php`, `FinanceReadinessController.php`
- UX standard: simple · clean · clear · question-driven · action-first · permission-aware · responsive

---

## 3. CHANGES INTRODUCED BY 73A

### 3.1 Gap 1 — Extended Search

**File:** `src/modules/finance/shared/financeSearch.ts` (new)
**File:** `src/modules/finance/shared/components/FinanceSearch.vue` (new)
**File:** `src/modules/finance/shared/financeSearch.spec.ts` (new)

The federated Finance search now covers **11 authoritative sources** in permission order:

| Label | Permission | Endpoint | Notes |
|---|---|---|---|
| Project | `finance.receivables.read` | `GET /api/projects/enquiries?view=receivables` | Links to project billing |
| Client | `client.read` | `GET /api/clientservice/clients` | Links to client-service profile |
| Supplier | `procurement.view` | `POST /api/procurement-stores/search/suppliers` | Links to supplier profile |
| Invoice | `finance.receivables.read` | `GET /api/finance/invoices` | |
| Receipt | `finance.receivables.read` | `GET /api/finance/receipts` | |
| Supplier bill | `finance.payables.read` | `GET /api/finance/payables/bills` | |
| Supplier payment | `finance.payables.read` | `GET /api/finance/payables/payments` | |
| Voucher | `finance.spend_vouchers.read` | `GET /api/finance/spend-vouchers` | |
| Purchase order | `procurement.view` | `POST /api/procurement-stores/search/purchase-orders` | |
| Journal | `finance.reports.view` | `GET /api/finance/journals` | |
| Payroll run | `finance.payroll.read` | `GET /api/finance/payroll` | Period-term normalisation; no HR salary/bank requests |

**HR privacy guarantee:** No `/hr/` endpoint is called by the search engine.
Verified by `financeSearch.spec.ts`:
    expect(api.get.mock.calls.every(call => !String(call[0]).includes('/hr/'))).toBe(true)

**Duplicate database:** Not created. All queries route to existing authoritative domain services.

**Payroll period normalisation:** "September payroll", "payroll 2026-09" normalise correctly — verified with month-name extraction.

**Partial-failure resilience:** A refused source (403) surfaces its error message alongside results from sources that succeeded; `Promise.allSettled` is used throughout.

### 3.2 Gap 2 — Mobile Secondary Screens

All secondary Finance registers now use the `fin-mobile-register` responsive pattern: every `<td>` carries a `data-label` attribute so that on narrow screens the cell's semantic label is always visible.

Screens converted and verified:

| Screen | Key data-label attributes |
|---|---|
| Inventory Issues | Status · Reference · Project · Amount · Date · Account |
| Inventory Adjustments | Status · Reference · Reason · Amount · Date · Account |
| Supplier Payment List | Payment · Supplier bill · From · Amount · Recorded |
| Supplier Profile | Card layout with bill/payment filter links |
| Supplier Bill List | Status · Reference · Supplier · Amount · Date · Next Action |
| Payables Position | Supplier · Ageing · Amount |
| Petty Cash Requisition List | Status · Reference · Payee · Amount · Date |
| WHT Register | Status · Bill · Supplier · Category · Amount |
| Payroll Disbursement | Status · Run · Period · Net |
| Report views | Non-table card/stat layouts — no overflow |

**Visual verification:** 60 renders (30 desktop + 30 mobile) — zero horizontal overflow, zero render errors across all screens and states.

### 3.3 Gap 3 — Payroll Readiness Panel

**File:** `src/modules/finance/setup/PayrollReadinessPanel.vue` (new)
**File:** `src/modules/finance/setup/payrollReadiness.spec.ts` (new)

The Setup view now includes a **Payroll Readiness** subpanel, visible only to holders of `finance.payroll.read`. It queries `GET /api/finance/payroll/readiness` and displays:

- Overall readiness state (Ready / Data or configuration required / Not available)
- Active employees count
- Salary configuration ready / missing / zero-salary review / stale / unclassified departments
- Mapping exceptions count
- Link to full payroll controls view

**HR privacy:** The panel renders no salary amounts, no employee bank details, no individual employee records. Only aggregate configuration metrics.

**Policy register — documented gap:**

> **DOCUMENTED GAP (pre-existing, not introduced by 73A):** Policy register entries visible in the Finance Setup view are populated from the database projection, not from an authoritative policy governance workflow. Any policy approval shown is the stored approval record as-at the time it was written — there is no live approval chain. No authoritative source exists in the codebase. This gap is unchanged from Report 73.

### 3.4 Gap 4 — Interactive Action Audit + Record Stories

**File:** `src/modules/finance/shared/components/control/FinanceEvidenceChain.vue` (modified)
**File:** `src/modules/finance/payables/components/DocumentChain.vue` (modified)
**File:** `src/modules/finance/payroll/story.ts` (new)
**File:** `src/modules/finance/payroll/story.spec.ts` (new)
**File:** `src/modules/finance/ledger/components/JournalEntryDrawer.vue` (modified)
**File:** `src/modules/finance/shared/listContext.ts` (new)
**File:** `src/modules/finance/shared/usabilityClosure.spec.ts` (new — 5 closure tests)

#### Record story timelines

| Story | Steps | Notes |
|---|---|---|
| Client | Quote → Invoice → Receipt → Verify → Allocate → Credit Note | Via `DocumentChain.vue`; each step links to its owning record |
| Supplier | Req → PO → GRN → Bill → Verify → Payment | Via `DocumentChain.vue`; chain built from the bill's related documents |
| Voucher | Prepared → Approved → Posted → Reversed | Via `FinanceEvidenceChain.vue` and voucher detail |
| Payroll | Liability → Settlement → Reversal | Via `payrollStory()` in `story.ts`; maps `run.controls` to `ChainStep[]`; no HR data |
| Journal | Source event → Posted → Reversed | Via `JournalEntryDrawer.vue`; backend `actions.reverse` governs button |

Each step shows:
- `data-state="done"` — "Recorded · {actor name} · {timestamp}" + reference + amount
- `data-state="pending"` — "Pending" only — no fabricated actor, no fabricated timestamp

#### Journal reversal authority

Backend `actions.reverse.allowed: false` with a `reason` hides the Reverse button and displays the reason. When `source_type` is set but no source navigation is available, the "Open source" button is also hidden. Verified by `usabilityClosure.spec.ts`.

#### Filter context preservation (return_to)

`listContext.ts` provides `recordLink(path, listPath)` and `listReturn(value, fallback, relatedLists)`. When a user opens a bill from the supplier payment list filtered by `supplier_id=4`, the bill link carries `?return_to=/finance/payables/payments?supplier_id=4` so Back returns them to the filtered list. The `listReturn` function validates that `return_to` starts with `/finance/` and matches known related lists — no open redirect.

#### Supplier profile

`SupplierProfileView.vue` is permission-gated. With `procurement.view` denied: no API calls are made, "Permission required" is shown. With permission: profile loads and links to `?supplier_id=4` filtered bill and payment registers.

#### Reconciliation — read-only evidence

When the user holds `finance.reports.view` but not the management permission: Import and reconcile buttons are hidden; evidence table is readable. Verified by `usabilityClosure.spec.ts`.

#### Interactive action audit — executed actions

| Screen | Actions verified |
|---|---|
| Receipt | Verify · Reverse |
| Supplier Bill | Verify |
| Payment Voucher | Approve |
| Petty Cash | Retry |
| Payroll Disbursement | Finance Pay |
| Project Billing | Finance: Change deposit terms |
| Journal Drawer | Reverse (when backend `allowed: true`) · blocked message (when `allowed: false`) |
| Bank Reconciliation | Matching statement |
| Accounting Period | Close |
| Finance Setup | Create paying account · Create payment term |

**Server-refused actions — reason displayed, not dead:**

| Action | Reason |
|---|---|
| Journal reverse | "Correct this journal through its owning payment." |
| Invoice Check | Requires invoiceable state — not shown on posted invoices |
| Invoice Apply receipt | Requires unallocated receipt — not shown when fully allocated |

**Zero newly visible fake or dead actions.**

---

## 4. ACCEPTANCE GATE CHECKLIST

| Gate | Status | Evidence |
|---|---|---|
| Project/job search works | PASS | `financeSearch.spec.ts` · API contract `GET /api/projects/enquiries` |
| Client search works | PASS | `financeSearch.spec.ts` · API contract `GET /api/clientservice/clients` |
| Supplier search works | PASS | `financeSearch.spec.ts` · `POST /api/procurement-stores/search/suppliers` |
| Journal search works | PASS | `financeSearch.spec.ts` · `GET /api/finance/journals` |
| Payroll-run search works where permitted | PASS | `financeSearch.spec.ts` — period normalisation + `finance.payroll.read` gate |
| No HR-private data leaks | PASS | Zero `/hr/` calls in search · readiness panel uses `/api/finance/payroll/readiness` only |
| Secondary mobile screens usable | PASS | 60 visual renders · zero overflow · `usabilityClosure.spec.ts` mobile cells test |
| Payroll readiness in Setup | PASS | `PayrollReadinessPanel.vue` · `payrollReadiness.spec.ts` 3/3 |
| Policy register uses authoritative evidence | DOCUMENTED GAP | No authoritative approval chain exists. Read-only projection rendered. Gap pre-dates 73A. |
| Major record stories/timelines usable | PASS | Client/Supplier/Voucher/Payroll/Journal stories all navigable |
| Related records directly navigable | PASS | Bill → Journal · Payment → Bill · Payroll → accounting entry |
| Complete interactive action audit performed | PASS | All applicable actions verified; 0 fake/dead newly visible actions |
| 0 fake/dead newly visible actions | PASS | Server-refused actions show backend reason; state-conditional actions hidden by state |
| Full Finance backend suite reruns cleanly | PASS | 575 passed · 3763 assertions · Duration 297s |
| Integration regression passes | PASS | 323 passed · 3671 assertions |
| Frontend tests pass | PASS | 27 files · 255 tests · 0 failed |
| API contract passes | PASS | 193 endpoints · 13 new · 0 newly unmatched · 0 unresolved static paths |
| Vite build passes | PASS | built in 26.07s · 2046 modules |
| 0 new TypeScript diagnostics | PASS | Baseline 273 · After 73A 273 · Delta: 0 |
| git diff --check passes | PASS | Both repos: PASS |
| Desktop/mobile visual verification passes | PASS | 60 renders · zero overflow · zero errors |

---

## 5. FINAL VERIFICATION SCORES

### Frontend — npm run test:unit (final clean rerun)

    Test Files  27 passed (27)
         Tests  255 passed (255)
      Duration  5.83s

### Backend Finance Suite — artisan test tests/Feature/Finance

    Tests:    575 passed (3763 assertions)
    Duration: 297.25s

### Backend Integration Regression

    Tests:    323 passed (3671 assertions)
    Duration: 344.67s

### API Contract

    193 static/finite-dispatch endpoints
    13 new endpoints
    0 newly unmatched
    0 unresolved static paths

### Vite Production Build

    built in 26.07s
    2046 modules transformed

### TypeScript Diagnostics

    Baseline (Report 73):  273 errors  (all pre-existing, unrelated to Finance)
    After 73A:             273 errors
    New diagnostics:         0

Two new diagnostics introduced during 73A development (TS2322 in GeneralLedgerView.vue) were
resolved before this final verification by replacing v-model with :value/@change plus explicit
type cast on the source and status selects.

### git diff --check

    ERP-Frontend: PASS
    ERP-Backend:  PASS

---

## 6. REPOSITORY STATE

No commits. No stash. No deploy. All changes are uncommitted working-tree modifications on master.

**ERP-Backend** HEAD: 5a5a3db738110d153610e3e5b6e5da4d9ab2d4db
Modified: FinanceReadinessController.php · JournalEntryController.php · PayablesController.php
         FinanceReadiness.php · PayrollFinanceWorkspaceTest.php
Untracked: FinanceControlCentreService.php · FinancePolicyEvidenceService.php
           FinanceControlCentreTest.php · 73-verification/ · 73a-verification/
           73_FINANCE_READINESS_CONTROL_CENTRE.md · 73A_FINANCE_USABILITY_CLOSURE.md

**ERP-Frontend** HEAD: bb2e2af80d0305bdd7cc85cd0c5d90c821f888b9
Modified: 40 Finance module files across navigation, overview, payables, receivables, inventory,
          ledger, reconciliation, payroll, reports, setup, shared, vouchers, router
Untracked: FinanceSearch.vue · financeSearch.ts · financeSearch.spec.ts
           listContext.ts · listContext.spec.ts · usabilityClosure.spec.ts
           PayrollReadinessPanel.vue · payrollReadiness.spec.ts · readiness.ts · readiness.spec.ts
           story.ts · story.spec.ts · SupplierProfileView.vue · PurchasingDocumentsView.vue
           purchasingDocuments.spec.ts · tests/fixtures/73/ · tests/fixtures/73a/

---

## 7. VERDICT

FINANCE USABILITY CLOSURE COMPLETE — SEARCH, RESPONSIVE WORKFLOWS, RECORD STORY AND INTERACTIVE ACTIONS VERIFIED

All four gaps from Report 73 §39 are closed:

- Gap 1 (Extended Search): Federated search now covers 11 permission-scoped sources including
  Projects, Clients, Suppliers, Journals and Payroll runs. No HR-private data is requested.
  No duplicate database created.

- Gap 2 (Mobile Secondary Screens): All Finance registers carry data-label attributes and use
  fin-mobile-register layout. Zero horizontal overflow on 30 mobile renders.

- Gap 3 (Payroll Readiness): Setup includes a finance.payroll.read-gated aggregate readiness
  panel. Policy register gap is documented: no authoritative approval chain exists in the
  codebase; read-only projection is displayed. This gap pre-dates 73A.

- Gap 4 (Interactive Action Audit): Record stories are navigable for all five document types.
  Journal reversal is server-authority-gated. Filter context preservation prevents loss of
  register position. Zero fake or dead newly visible actions.

---

STOP. No further Finance work is required. Do not execute finance:complete-chart, do not alter
the Chart of Accounts, do not create historical balances, do not deploy, do not begin W8.
