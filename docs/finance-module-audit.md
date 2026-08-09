# Finance Module Audit: Petty Cash, Chart of Accounts & Projects Integration

**Status:** Findings · **Date:** 2026-07-21 · **Scope:** `app/Modules/Finance/*` (backend), `src/modules/finance/*` (frontend), Finance↔Projects integration points

---

## 1. Executive Summary

Finance is not a general accounting module — it is **Petty Cash plus a decorative Chart of Accounts**, bolted onto a Projects module that runs its own flat, unscoped payment tracking (`EnquiryPayment`). There is no invoicing, no AR, no double-entry, no billing engine anywhere in the codebase; that gap is already scoped in [`quote-to-cash-redesign.md`](./quote-to-cash-redesign.md) and is not repeated in detail here.

This audit covers what that other document doesn't: the current, verified state of Petty Cash's own backend/frontend implementation (cross-checked against the open [`REFACTOR_TASKS.md`](../app/Modules/Finance/PettyCash/docs/REFACTOR_TASKS.md) checklist, several items of which are marked done but are only partially true), plus integration points between Finance and Projects beyond the quote/billing chain.

The most urgent problems are **authorization holes on live, money-moving endpoints** — not the ledger-math correctness the existing refactor plan is focused on. Two Petty Cash top-up endpoints can move real cash with no role check or no ledger reversal, while a disbursement endpoint that requires Super Admin protects a method that unconditionally throws.

---

## 2. Current State (module map)

```
app/Modules/Finance/
  Models/ChartOfAccount.php          ── no service, no CRUD, one read-only dropdown consumer
  Providers/FinanceServiceProvider   ── registers migrations only
  PettyCash/
    Controllers/  PettyCashController, PettyCashTopUpController, PettyCashRequisitionController
    Services/     PettyCashService, LedgerService, TopUpAllocator, ProjectIdentityResolver
    Repositories/ PettyCashRepository  ── raw joins into ProjectEnquiry/TaskBudgetData
    Requests/     3 unused FormRequest validators
    Imports/      PettyCashDisbursementImport  ── date-locale + cost-drop bugs
    docs/         REFACTOR_GUIDE.md, REFACTOR_TASKS.md (in-flight, partially executed)

src/modules/finance/
  petty-cash/     components, pettyCashStore.ts, pettyCashService.ts
                  ── well-layered but with error-pipeline and RBAC-decoration issues
```

No `Invoice`, `BillingSchedule`, or `AR` model exists anywhere in the backend. `EnquiryPayment` (in the Projects module, not Finance) is the only cash-in record.

---

## 3. Verified Flaws

### A. Backend — Petty Cash & Chart of Accounts

| # | Severity | Flaw | Evidence |
|---|---|---|---|
| BE1 | **Critical** | `PettyCashTopUpController::destroy()` hard-deletes a top-up with **no ledger reversal and no authorization check**. The `TOP-xxxxxx` credit entry and cached `current_balance` are never reversed — balance stays inflated forever, and the ledger keeps a dangling reference. Same bug class as documented C5 (`clearAllData` orphaning the ledger) but on an endpoint the refactor plan doesn't cover at all. | `Controllers/PettyCashTopUpController.php:418–457` |
| BE2 | **Critical** | `PettyCashTopUpController::update()` has **zero role check**, yet posts a real adjustment ledger entry for any amount delta — any authenticated active user can move the cash balance. Meanwhile the disbursement `update()` requires Super Admin to reach `PettyCashService::updateDisbursement()`, which **unconditionally throws** — a dead, always-400 endpoint protected like it matters. | `Controllers/PettyCashTopUpController.php:93`; `Services/PettyCashService.php:80–97, 276–279` |
| BE3 | **High** | Three well-built `FormRequest` classes (upper amount bound `max:999999.99`, M-Pesa transaction-code regex, top-up sufficiency check) are **never wired to any controller** — zero references anywhere. Controllers use a weaker inline validator with no upper bound and no transaction-code format check. | `Requests/CreateDisbursementRequest.php`, `CreateTopUpRequest.php`, `UpdateDisbursementRequest.php` |
| BE4 | **High** | Import path never sets `skip_balance_check`, so bulk historical imports hit the **strict** allocator and hard-fail per-row on any legitimate historical overspend — contradicts the import's own code comment ("allow negative balance imports"). | `Imports/PettyCashDisbursementImport.php:180–182, 501–518` |
| BE5 | Medium | `parseDate` tries `m/d/Y` before `d/m/Y` — `03/04/2025` parses as March 4, not April 3, on Kenyan data (H4, still live). | `Imports/PettyCashDisbursementImport.php:302–320` |
| BE6 | Medium | `transaction_cost` is silently dropped on every imported row (H3, still live). | `Imports/PettyCashDisbursementImport.php:433–519` |
| BE7 | Medium | No `PettyCashPolicy` exists (BE-0 in the refactor plan, still not done). Every check is a hand-typed `Auth::user()->hasRole('Super Admin')` scattered across ~10 controller methods; `store()`/`index()`/all GET endpoints have no authorization beyond `auth:sanctum`+`active`. | `Controllers/PettyCashController.php:383,439,482,519,548,933,976,1010,1038,1072` |
| BE8 | Medium | Manual `DB::beginTransaction()/commit()/rollBack()` still present in `updateTopUp`, `voidDisbursement`, `clearAllData`, `recalculateBalance`, archive methods — all catching `Exception`, not `Throwable`, so a fatal error mid-block leaves an open transaction. The refactor checklist marks BE-5 done; that's only true for `createDisbursement`/`createTopUp`. | `Services/PettyCashService.php:70,286,358,479,637,671` |
| BE9 | Low/Medium | `getApprovedProjectBudgetTotal` sums `grandTotal` in a PHP loop over all fetched rows instead of an SQL `SUM` — won't scale past a few thousand projects (BE-17, only partially fixed). | `Repositories/PettyCashRepository.php:743–766` |
| BE10 | Medium | No rate limiting on `clear-all`, `void`, `destroy`, or bulk-delete — a full-data-wipe endpoint has no throttle and no confirmation step beyond the inline role check. | `routes/api.php:774–845` |
| BE11 | Low (informational) | ChartOfAccounts is vestigial: model + seeder + one read-only dropdown lookup (`PettyCashController::accounts()`). No service, no posting logic, no linkage to `petty_cash_ledger_entries` despite a seeded `PETTY-001` account existing for exactly that purpose. | `Models/ChartOfAccount.php`, `Controllers/PettyCashController.php:254–272` |
| BE12 | Low | Residual float comparisons at the validation boundary — DB columns and `LedgerService`/`TopUpAllocator` correctly use `decimal`/`bcmath`, but pre-write gates (`PettyCashService.php:130,134,184–185`) and `PettyCashBalance::hasSufficientBalance/isLow/isCritical` still compare via native float. | `Services/PettyCashService.php:130,134,184–185`; `Models/PettyCashBalance.php:109–128` |
| BE13 | Low | `getFlatTransactions` search filters on `metadata->description` via unindexed JSON-path `LIKE` — will degrade on large ledgers. | `Repositories/PettyCashRepository.php:407–415` |

**Positives:** money columns are correctly `decimal`; `LedgerService`/`TopUpAllocator` correctly use `bcmath`; model `boot()` balance-mutation hooks are actually gone (ahead of the checklist, BE-4/BE-11 effectively done though unchecked); archive semantics (BE-12 in the refactor plan) are already correctly fixed — no ledger entry posted, single/bulk behave identically.

### B. Frontend — Petty Cash UI

| # | Severity | Flaw | Evidence |
|---|---|---|---|
| FE1 | **High** | Backend field-level validation errors **never reach the form**. `pettyCashService.ts` normalizes errors into `{status, message, errors}` at the top level before throwing, but `useErrorHandler.ts` and `DisbursementForm.vue`'s error check look for `error.response.errors` (Axios shape) — always `undefined`. A genuine 422, including a real insufficient-funds race, degrades to a generic banner with no field highlighted. | `services/pettyCashService.ts:212–318`; `composables/useErrorHandler.ts:81–139`; `components/DisbursementForm.vue:1406` |
| FE2 | **High** | Void is unreachable from the main transaction table. The handler, event, and modal all exist and are wired at the page level, but no button in the table's actions column calls it — only View/Download/Edit/Archive/**Delete** are exposed. The irreversible action is one click away; the reversible, audit-safe one isn't reachable from the primary workflow. | `components/TransactionList.vue:536–604, 928–930`; `PettyCashIndex.vue:220,332–390` |
| FE3 | **High** | Missing bank payment-method labels (`equity/stanbic/ncba/kcb/family`) — present in the backend enum and the FE type union, but the label map is duplicated **5 times** and incomplete in every copy, including the transaction-history **filter dropdown**, so a clerk can't filter by those methods at all. | `services/pettyCashService.ts:938–946`; `store/pettyCashStore.ts:342–348,419–425,777–783`; `components/TransactionList.vue:152–166` |
| FE4 | Medium | Decorative RBAC: `usePermissions.ts` hardcodes `role === 'Super Admin'` for edit/void/delete/recalculate instead of checking the granular `finance.petty_cash.*` keys it otherwise validates; backend mirrors the same hardcoded string. Consistent (no security hole), but a Finance Manager role can't be granted these rights without a code change on both repos. | `composables/usePermissions.ts:59–75` |
| FE5 | Medium | The same permission gate is implemented three different ways (service helper, composable hardcode, one component's own `useAuth().roles.includes(...)` check) — a future role change means hunting all three. | `services/pettyCashService.ts`; `composables/usePermissions.ts`; `components/TransactionList.vue:694–696` |
| FE6 | Medium | A full declarative validation ruleset (including the M-Pesa regex and a "transaction code required for non-cash payments" rule) exists and is **never used** — the hand-rolled `validateForm()` doesn't validate transaction codes at all, silently diverging from the declared rules and from whatever the backend actually enforces. | `types/forms.ts:220–333`; `components/DisbursementForm.vue:1070–1153` |
| FE7 | Low | `DisbursementForm`'s reactive form (all money fields) is typed `as any` — no type checking on the highest-risk data in the component. | `components/DisbursementForm.vue:794–803` |
| FE8 | Low | Dead `debounce` import; unused. Account/project autocomplete filters client-side against already-fetched lists, so not a live perf bug, just dead code. | `components/DisbursementForm.vue:757` |
| FE9 | Low | Emoji-prefixed `console.log`s left in, not gated by a dev-only check. | `DisbursementForm.vue:1051,1182,1518`; `TransactionList.vue:1121,1129,1137,1151` |
| FE10 | Low | Money formatting hand-typed ad hoc in 6+ call sites (including a fragile `.replace('KES ', '')` string-strip) instead of the shared `pettyCashService.formatAmount()` that `ProjectBudgetsTab.vue` uses correctly. | `store/pettyCashStore.ts`; `components/TransactionList.vue:511,852–857`; `components/DisbursementForm.vue:947–949` |
| FE11 | Low | Empty-vs-error conflation: fetch helpers `console.error` and swallow failures, leaving lists empty — a genuinely-empty project-budget list and a failed API call render an identical "No Active Budgets Found" state with no retry. | `components/ProjectBudgetsTab.vue:227–231` |
| FE12 | Low | Native `confirm()`/`alert()` for destructive bulk actions in an otherwise fully custom-styled, dark-mode-themed UI. | `components/TransactionList.vue:766–782,941–981` |
| FE13 | Practicality | `ProjectBudgetsTab` (budget/spend/utilization drill-down) has no link to "create disbursement against this project" or "filter transaction history by this job number" — a clerk investigating an over-budget project must leave the tab and re-enter the job number elsewhere. Pagination is also reimplemented from scratch here, duplicating `TransactionList.vue`'s generator. | `components/ProjectBudgetsTab.vue` |
| FE14 | Practicality | CSV/Excel export lives only in a separate `ReportsPanel.vue`; the daily-driver transaction table only exposes a PDF button — bulk export isn't available from the surface a clerk actually uses day to day. | `components/TransactionList.vue`; `components/ReportsPanel.vue` |

### C. Finance ↔ Projects Integration

Already-verified-fixed from the prior quote-to-cash audit (A5 payment scoping, A6 `FinanceService::releaseProject` removal, A7 activation rethrow, B8 payment-basis approved-only) remain correct in current code — see [`quote-to-cash-redesign.md`](./quote-to-cash-redesign.md) §6 for the full Phase 0 record. Phase 1+ of that doc (unified approval pipeline, billing engine, invoices) has **not started**.

| # | Severity | Finding | Evidence |
|---|---|---|---|
| X1 | Medium-High | PettyCash reaches directly into Projects' persistence layer, not through any service boundary — raw joins/queries against `ProjectEnquiry`, `enquiry_tasks`, `TaskBudgetData`, reading JSON keys like `budget_summary.grandTotal` by string. Already broke silently once: the 2026-07-08 internal-budget-approval removal required a manual re-key across three PettyCash call sites (see `[[petty-cash-refactor-plan]]` / `[[erp-quote-to-cash-redesign]]` memory). No test in Finance guards this boundary, so the next Projects-side reshape breaks it again silently. | `Repositories/PettyCashRepository.php:610–701,743–766`; `Controllers/PettyCashController.php:203–234` |
| X2 | Informational | **Correction to a prior assumption:** there is no Finance gate on procurement/materials release at all. `ProcurementService::ensureBudgetReadyForProcurement` and `BudgetService::ensureMaterialsApproved` gate purely on Projects-internal state — Finance has zero say in procurement release. | `app/Services/ProcurementService.php:404–423`; `app/Services/BudgetService.php:361–385` |
| X3 | Medium | `ChartOfAccount` has zero postings tied to any project transaction anywhere in the app — confirmed by full-repo grep. Finance's "chart of accounts" is UI furniture (a dropdown source), not a general ledger. | — |
| X4 | **Medium-High** | The actual cost-growth mechanism has no audit trail. `BudgetAdditionService::approve/reject/approveVirtualAddition` mutate `status`/`approved_by`/`approved_at` directly — **zero `GovernanceAuditLog` calls, zero `NotificationService` calls**, anywhere in the file (grep-confirmed). Contrast with quote invalidation, which does both. Since approved `BudgetAddition` rows feed quote pricing, this is the concrete, previously-unlocated mechanism behind the redesign doc's C4 ("scope growth is executed but never billed"). | `app/Services/BudgetAdditionService.php:168–430` |
| X5 | Low-Medium | `FinanceReleased` event has no notification listener of its own — only `ActivateProjectAfterFinance`. Masked in the common case because `ActivateProjectAction` separately fires `sendProjectActivated`, but a manual-override release where activation is delayed produces only a `GovernanceAuditLog` row and no proactive notification of the finance decision itself. | `app/Providers/EventServiceProvider.php:23–25`; `app/Modules/Projects/Actions/ReleaseFinanceGateAction.php:52–68` |
| X6 | Low | `FinanceService::updatePayment`/`deletePayment` write `GovernanceAuditLog` rows but call no `NotificationService` method — inconsistent with the audit+notify pairing established for quote invalidation in the same subsystem. | `app/Modules/Projects/Services/FinanceService.php:38–89` |
| X7 | Medium | `enquiry_payments.transaction_reference` still has no `unique()` constraint and there's still no currency column — confirms redesign-doc C3/C6 are fully open, not partially mitigated. | `database/migrations/2026_03_10_131208_create_enquiry_payments_table.php:20` |
| X8 | Positive | `ProjectIdentityResolver` (PettyCash) is a clean, deliberate adapter resolving `requisition_id`/`project_id`/`job_number` into a consistent `{project, enquiry}` pair — the one place PettyCash's coupling to Projects is contained rather than ad hoc, in contrast to X1. | `Services/ProjectIdentityResolver.php` |

---

## 4. Practicality Assessment

Cash *disbursement* tracking is reasonably solid at the ledger-math level (decimal columns, bcmath, FIFO allocation, correct archive semantics). But the two operations that actually move money outside the disbursement flow — deleting and editing a top-up — have no authorization and, in the delete case, no ledger reversal (BE1, BE2). Above petty cash, there is no billing layer at all: no invoices, no AR, no way to answer "how much do we owe on this project vs. how much have we billed." The Chart of Accounts exists in name only (X3, BE11). Cost growth on active projects (BudgetAddition approvals) happens with zero audit trail (X4) — this is the concrete leak the quote-to-cash redesign doc already flagged, now pinpointed to a specific unaudited write path.

Frontend UX is workable for the happy path but has two workflow-breaking gaps for a real finance clerk: void (the correct way to reverse a mistaken disbursement) isn't reachable from daily use (FE2), and a genuine backend rejection renders as a useless generic error instead of pointing at the field (FE1).

---

## 5. Priority Order

> **Status as at 2026-08-09.** Closed items are struck through with how they were closed. Several
> were closed by *deletion* rather than repair — the surface they lived on no longer exists, which is
> a better outcome than a fixed version of something nobody should use.

1. ~~**BE1, BE2** — the two unguarded top-up endpoints.~~ **DONE.** `destroy()` now posts a
   reversing ledger entry (`LedgerEntry::reversalForTopUp`) inside one transaction before removing
   the row, so the cached balance can no longer be left overstated; both endpoints require an
   explicit permission (`delete_top_up`, and a new `edit_top_up` that had no counterpart, which is
   part of why that endpoint ended up with no check at all). Pinned by `TopUpIntegrityTest`, whose
   central assertion is that the cached balance still equals the ledger it summarises.
2. **BE3** — wire the existing, unused `FormRequest` classes into the controllers. Near-zero risk, closes several validation gaps at once. **STILL OPEN.**
3. ~~**FE1, FE2**~~ **DONE.** FE1 (error transform) was already fixed by earlier work in the module;
   FE2 — Void was unreachable from the transaction table. The emit, the local wrapper *and* the
   parent's modal all already existed; the only missing piece was a button. It now sits **before**
   Delete, so the reversible action is the nearer one.
4. **X4** — add `GovernanceAuditLog`/notification calls to `BudgetAdditionService`. **PARTIALLY
   ADDRESSED:** budget additions now project into the cost ledger as `planned` lines carrying
   `cost_cause = CLIENT-CHANGE` and a poster, so scope growth finally has a trail. The
   `GovernanceAuditLog` call itself is still absent.
5. ~~**FE3 / BE7 / FE4 / FE5** — duplicated label maps and decorative RBAC.~~ **DONE for the RBAC
   half.** `usePermissions` now checks the granular permission each gate is named after, with a
   single declared Super Admin bypass; `TransactionList`'s own `isSuperAdmin` and
   `RequisitionShow`'s inline role array are gone. Four permissions the frontend had always asked
   for turned out never to have been seeded — the role checks were a workaround for grants that were
   impossible, not a policy decision. Label-map consolidation (FE3) is partly done: 5 copies → 2.
6. ~~**BE9 / BE17** — `getProjectBudgetsSummary` performance.~~ **CLOSED BY DELETION.** See
   `REFACTOR_TASKS.md` BE-17. Also removed: `ProjectBudgetsTab.vue`, the `budgets/summary` route and
   controller action, and the unread `budget_snapshot` in `workspace()`. All of it rendered a
   four-way category split derived from `budget_category`, which is null on every disbursement.
6. Everything else in [`REFACTOR_TASKS.md`](../app/Modules/Finance/PettyCash/docs/REFACTOR_TASKS.md) (Phases 1–5) and [`quote-to-cash-redesign.md`](./quote-to-cash-redesign.md) (Phase 1+) stands as previously scoped — this audit doesn't change that sequencing, it adds BE1–BE13/FE1–FE14/X1–X8 as a parallel, narrower-scope worklist focused on what's provably broken today rather than architectural redesign.

---

## 6. Relationship to Existing Docs

- [`app/Modules/Finance/PettyCash/docs/REFACTOR_GUIDE.md`](../app/Modules/Finance/PettyCash/docs/REFACTOR_GUIDE.md) / `REFACTOR_TASKS.md` — the ledger-architecture refactor (single-writer `LedgerService`, `TopUpAllocator`, `RowNormalizer`). This audit verifies actual progress against those checkboxes (§3.A) and adds authorization/practicality findings (BE1–BE3, BE7, BE10) that fall outside that plan's scope.
- [`quote-to-cash-redesign.md`](./quote-to-cash-redesign.md) — the billing/AR gap and quote approval integrity holes. This audit doesn't repeat that analysis; §3.C (X1–X8) extends it with Finance-side integration points (BudgetAddition audit trail, PettyCash↔Projects coupling, ChartOfAccounts) that the billing-focused doc didn't cover.
