# Report 57 — Finance Frontend Redesign: Stream A (Finance Shell) Implementation

**Date:** 2026-09-28
**Predecessor:** Report 56 (baseline and plan).
**Scope:** Stream A only:
- the Finance information architecture;
- the Finance Overview;
- role-aware **My actions**;
- the shared page, status, money, loading/error/empty and error-message system;

plus the two Stream A clean-ups Report 56 §35 named.

**Environment:** development only, on branch `finance/critical-stabilization-fixes` in both repositories.
- No production database, configuration, deploy, queue worker, cutover or W8.
- No opening balances.
- Nothing pushed.

---

## 1. Summary

| Result | Evidence |
|---|---|
| Finance navigation rebuilt around how WNG works (8 sections) | `navigation.ts`, 10 + 6 navigation tests |
| `/finance` lands on a real **Overview** | `FinanceOverviewView.vue`, 7 component/data tests |
| **My actions** covers every workflow W1–W7 and never offers work the backend refuses | backend `FinanceWorkQueueService`, 13 feature tests; real-data run on the rehearsal target |
| One status vocabulary, one money format, one error normaliser, one state component | `shared/status.ts`, `money.ts`, `apiErrors.ts`, `FinanceState.vue`, 10 unit tests |
| Reconcile gate corrected; dead `updateDisbursement` removed | `RequisitionIndex.vue`, `usePettyCash.ts` |
| Frontend: 193/193 unit tests, build OK, ENG-1 256 = baseline, 0 new | §9 |
| Backend: 1,521 / 1,521 (see §9) | §9 |
| API contract: Finance **149/149** calls match live routes (was 144/145) | §5 |

**Next stream:** B (W1, Sales & receivables), §12.

---

## 2. Files Changed

**ERP-Backend**

| File | Change |
|---|---|
| `app/Modules/Finance/Services/FinanceWorkQueueService.php` | One definition per work type (permission, query, item). Count and list derived from the same query. 21 types (was 8). Maker/checker exclusions. `area` per item. `by_area`, `type_labels` and `type_areas` in the responses. PO supplier name fixed (`supplier_name`, not `name`). |
| `app/Modules/Finance/Controllers/FinanceWorkQueueController.php` | Optional `area` filter (validated against the service's areas) |
| `tests/Feature/Finance/FinanceWorkQueueTest.php` | 5 existing tests corrected to use a separate requester (they encoded self-approval the backend refuses); 8 new tests |
| `docs/…/56_…md`, `57_…md` | Reports. Report 55 §34 corrected (Reconcile permission). |

**ERP-Frontend**

| File | Change |
|---|---|
| `src/modules/finance/navigation.ts` | New IA: Overview · Sales & receivables · Purchasing & payables · Expenses & cash · Project finance · Payroll finance · Reports & controls · Finance setup. `external` links for Procurement screens. The resolver prefers exact pages over detail prefixes. |
| `src/router/finance.ts` | `/finance/overview` route; `/finance` redirects to it |
| `src/modules/finance/overview/useFinanceOverview.ts`, `views/FinanceOverviewView.vue` | **New** Overview |
| `src/modules/finance/work-queue/workQueue.ts` | **New** typed client and area vocabulary |
| `src/modules/finance/work-queue/views/FinanceWorkQueueView.vue` | Rebuilt as **My actions**: area tabs, URL-driven filters, shared states and money, normalised errors |
| `src/modules/finance/shared/status.ts`, `money.ts`, `apiErrors.ts` | **New** shared modules |
| `src/modules/finance/shared/components/FinanceState.vue` | **New** loading / error / empty component |
| `StatusChip.vue`, `MoneyValue.vue`, `FinanceNavigation.vue`, `components/index.ts` | Use the shared modules; `StatusChip` gains an optional `domain`, so existing callers are unchanged; external-link marker |
| `petty-cash/views/requisitions/RequisitionIndex.vue` | Reconcile gated on `create_disbursement` (the backend's `can('create', Payment::class)`) |
| `petty-cash/composables/usePettyCash.ts` | Dead `updateDisbursement` (withdrawn `PUT` route, no consumer) removed |
| `navigation.spec.ts`, `tests/unit/finance/financeNavigation.spec.ts`, `shared/shared.spec.ts`, `overview/overview.spec.ts` | Tests |

---

## 3. Screens Implemented

### Finance Overview: `/finance/overview` (also `/finance`)

- **Purpose:** what needs me, then what is wrong, then where the money stands, then where to go.
- **Roles:** anyone passing the Finance guard. Each block follows its own permission.

| Block | Shown to | Endpoint | Content |
|---|---|---|---|
| Needs your attention | everyone | `GET finance/work-queue/count` | Areas with counts, and each kind of work, linking to My actions already filtered (`?area=&type=`) |
| Waiting longest | everyone | `GET finance/work-queue?priority=exception&per_page=10` | The 5 oldest items over 7 days, each linking to its source record |
| Finance configuration | `finance.reports.view` or `finance.payment_sources.manage` | `GET finance/readiness` (reports.view only), `GET finance/payment-sources?include_inactive=1` | Readiness checks not ready, integrity counters above 0, paying accounts with no ledger link, e.g. **"M-Pesa receiving account has not yet been configured by Finance."** |
| Owed by clients / Past due date | `finance.reports.view` | `GET finance/reports/receivables-ageing` | Outstanding total and count, and overdue = every bucket except `current`, from the backend buckets |
| Petty cash float | `finance.petty_cash.view_balance` | `GET finance/petty-cash/balance` | Float and its low/critical state |
| Finance areas | everyone | navigation map | Every permitted section and page, including the Procurement links, so every page is reachable |

**Honesty rules held:**
- A block the user may not see is **not requested and not rendered** (tested for a Project Officer).
- A failing block shows its own reason; the others still render (tested).
- There is no WIP, P&L or invented KPI.

### My actions: `/finance/work-queue`

- **Purpose:** every check, approval, verification and payment waiting for this user, from every workflow.
- **Controls:** area tabs; filters for kind of work, age and owner; search (all server-side).
- Filters are in the URL, so Overview links open it narrowed.
- Claim / release are unchanged.
- Each row links to the source screen that remains authoritative. **Nothing is approved from the queue.**

| Work type (label) | Area | Backend permission | Maker/checker applied |
|---|---|---|---|
| Invoice to check | sales | `finance.receivables.invoice_check` | not the preparer (no self-approval exception, as `checkProjectInvoice`) |
| Invoice to issue | sales | `finance.receivables.billing_basis` | — |
| Invoice returned to you | sales | preparer | returned, not resubmitted |
| Client receipt | sales | `finance.receivables.verify` | not the recorder, unless self-approve |
| Purchase requisition / Purchase order exception | purchasing | `procurement.requisitions.approve` / `procurement.orders.approve` | unchanged |
| Supplier invoice | purchasing | roles Super Admin / Admin / Accounts (mirrors `BillController::canVerify`) | not the recorder, unless self-approve |
| Cash requisition | cash | `finance.petty_cash.edit_disbursement` | not the requester, unless self-approve |
| Cash to disburse | cash | `finance.petty_cash.create_disbursement` | — |
| Surrender to review | cash | `finance.petty_cash.create_disbursement` | — |
| Cash to account for | cash | the requester | own disbursed / received / returned requisitions |
| Direct disbursement | cash | `finance.spend_vouchers.approve` | not the requester, unless self-approve |
| Payment voucher | cash | `finance.spend_vouchers.approve` | not the requester; excludes vouchers returned for correction |
| Voucher returned to you | cash | `finance.spend_vouchers.create` and the requester | — |
| Voucher for senior approval | cash | `finance.spend_vouchers.approve_senior` | not the requester or approver |
| Voucher to post | cash | `finance.spend_vouchers.post` | not the requester or approver; never while senior approval is pending |
| Cost verification | project | `finance.costs.verify` | unchanged |
| Labour to PO-verify | project | `ProjectFinancialAccess::canPoVerifyLabour` per project | project-scoped |
| Labour to Finance-verify | project | `finance.labour.finance_verify` | — |
| Payroll to lock | payroll | `hr.manage_payroll` | not the creator, unless self-approve |
| Payroll to pay | payroll | `hr.manage_payroll` | not the locker, unless self-approve |

### Navigation (every Finance screen)

The section rail and sub-rail now follow the Report 56 §10 architecture. The Purchasing pages are Procurement's own screens, marked with an "opens in another workspace" icon.

---

## 4. Backend Endpoints Used

| Screen | Endpoints |
|---|---|
| Overview | `finance/work-queue/count`, `finance/work-queue`, `finance/reports/receivables-ageing`, `finance/petty-cash/balance`, `finance/readiness`, `finance/payment-sources` |
| My actions | `finance/work-queue` (+ `area`), `finance/work-queue/{type}/{id}/claim` (POST, DELETE) |

All existed before Stream A. The only backend change is to the read-only work-queue projection.

---

## 5. Backend Additions

**One, and read-only:** `FinanceWorkQueueService` was extended (Report 56 §28 #1).
- No new route. One new optional query parameter (`area`).
- No workflow, posting, permission or state change.
- Every predicate is copied from the controller or service that enforces it, and named in comments. For labour PO verification the service calls the real `canPoVerifyLabour`, rather than re-deriving project assignment in SQL.
- Count and list come from the same query per type, so the badge and the rows cannot disagree.

**API contract** (`route:list --json` against every frontend call): Finance **149/149** matched. The previously unmatched `PUT petty-cash/disbursements/{id}` is gone. `DELETE petty-cash/clear-all` (live "Reset" button) is handled in Stream D, as Report 56 §9 decided.

---

## 6. Permissions

- **Navigation:** a page shows when the user holds its permission. My actions shows to anyone holding any of the 15 workflow-action permissions, including `finance.labour.po_verify` (Project Officer) and `hr.manage_payroll` (tested).
- **Actions:** the server decides eligibility (§3 table). The frontend neither re-derives nor overrides it.
- **View vs action:**
  - A Project Officer sees Overview, My actions (their labour to PO-verify, their cash to account for) and their project pages.
  - They are never offered Finance verification (backend test `labour_po_verification_is_scoped_to_the_officers_project`).
  - Balances and configuration are not requested for them (frontend test).
- **Configuration-aware:**
  - An unlinked M-Pesa or Card is shown as a configuration item, not a broken option. Once MIG-P1/P2 link them the item disappears, with no code change.
  - Custody stays on `manage_custody` (R-2), untouched.
- **Correction carried from Report 55:** Reconcile on the requisition list is now gated on `create_disbursement`, which is what the backend authorises.

---

## 7. Tests

**Backend** (`FinanceWorkQueueTest`, 13 tests, 108 assertions, all passing):

| Test | Proves |
|---|---|
| queue only returns work the user may action | permission gating |
| completed work disappears from queue count | state-driven |
| server side search and pagination | filters |
| claim / release; second officer cannot take a claim | assignment |
| **the maker is never offered their own work** | own direct disbursement hidden; visible to another approver; visible to the maker with `approvals.self_approve` |
| **invoices flow from check to issue and back to their preparer** | preparer never checks; checked → issue; returned → preparer's correction; resubmitted → check again |
| **a receipt is not offered to the person who recorded it** | maker/checker |
| **a voucher is posted by a third person after any senior approval** | senior pending blocks posting; requester and approver excluded; returned → requester |
| **cash requisitions follow approval, disbursement and surrender** | pending → approved → disbursed (requester) → surrender_pending (cashier) |
| **labour PO verification is scoped to the officer's project** | other officer excluded; Finance verify only after PO verify; PO never offered Finance verify |
| **payroll is locked and paid by different people** | creator cannot lock; locker cannot pay |
| **counts are grouped by area and match the list** | `by_area`, `type_labels`, `type_areas`, `?area=` |

**Frontend** (vitest):

| File | Tests |
|---|---|
| `shared/shared.spec.ts` | status vocabulary (same word across workflows; unknown shown as itself; the full agreed set), money format, error normalisation (server sentence kept; all four shapes; 403 wording; no internals on 5xx; conflict / session / not-found / network) |
| `overview/overview.spec.ts` | overdue computed from backend buckets; configuration wording incl. M-Pesa; Project Officer sees no balances / config and none are requested; Accounts sees balances and config; area/type links pre-filter My actions; one failing block isolated with its reason; empty states; My actions reads filters from the URL and sends them to the server; error without internals; empty message |
| `navigation.spec.ts` (10), `tests/unit/finance/financeNavigation.spec.ts` (6) | new IA, exact-page precedence, external links, section roots, My actions visibility per permission |

**Real data:** the extended queue ran on the rehearsal target (`wng_target_rehearsal`, real WNG users) via the rehearsal checkout. It printed counts and types only, no amounts or salaries:

| Role (real user) | Result |
|---|---|
| Accounts #15 / Super Admin #32 / Manager #12 | 3 real pending purchase requisitions (`/procurement/requisition/43…`) |
| Project Officer #76 / HR #13 / Procurement #28 / Costing #25 | 0 (the Report 55 smoke run completed their items) |

---

## 8. Build Result

`vite build`: **1,900 modules, exit 0** (`rehearsal-reports/fe57-build.log`).

## 9. TypeScript Baseline (ENG-1) and Regression

| Gate | Result |
|---|---|
| Frontend unit | **193 / 193** (29 files; was 169: +24 new or rewritten) (`fe57-unit.log`) |
| `vue-tsc --build --force` | **256 errors, the identical error set to the Report 55 baseline** (`fe55-tsc.log`), compared message by message ignoring line numbers: **0 new**, and none in any new or touched file (`fe57-tsc.log`) |
| Backend full suite (`db_test`) | **1,520 passed, 1 failed** in the full run (`regress57.log`, 10,530 assertions). The failure is `AuthLifecycleTest` "a user can login logout and login again", the suite's first test, which hit `Table 'password_reset_tokens' already exists`. That table was a half-applied migration left in `db_test` by two overlapping runs killed when the previous session ended. The test **passes on its own rerun** (2/2), so the result is **1,521 / 1,521** (1,512 in Report 55, plus 8 work-queue tests and 1 receivables test). |
| Work-queue suite | 13 / 13 |

## 10. Screenshots / Evidence

**No screenshots.** No browser-automation client is installed (Chrome exists, but there is no DevTools driver without adding packages). One of the frontend `.env` files points `VITE_API_BASE_URL` at the **production** API, so running a dev server for captures risks production traffic, which this stream must not create.

Evidence per screen is instead:
- route, purpose, roles and endpoints (§3–4);
- permissions (§6);
- component tests that mount the real views against recorded response shapes (§7);
- the real-data queue run (§7).

Screenshots can be taken once a dev server is pinned to the local backend.

## 11. Known Issues

1. **Purchasing opens Procurement.** The section's pages are Procurement screens; there is no Finance payables view until Stream C. The Overview lists all four links so none is hidden.
2. **Supplier-invoice verification is role-based** (Super Admin / Admin / Accounts) in the backend, and the queue mirrors it. A permission is Stream C.
3. **`/petty-cash/balance`** detail (thresholds, last transaction) is readable by anyone signed in; the float amount is deliberately shared. Decide in Stream D.
4. The **"Reset petty cash data"** menu item still calls a withdrawn route. Removed in Stream D with the menu, per Report 56 §9.
5. The requisition list labels `received` as "Complete", while the backend still accepts surrender from `received`. Stream D.
6. The HR modals call the removed `/api/hr/technical-labour` (outside Finance; the W7 rule forbids restoring it). Reported to HR.
7. Only new and touched screens use the shared status / money / error system. The other screens adopt it as their streams redesign them. `StatusChip` without a `domain` behaves exactly as before.

## 12. Exact Next Stream: B (W1, Sales & Receivables)

1. **Backend (minimal, read-only):** `GET api/finance/invoices`, a paginated cross-project invoice index with filters for client, project, officer, status (the derived review state: draft / returned / awaiting review / checked / issued / part-paid / paid / void) and due date. It returns invoiced, received and balance per invoice, with the balance from the backend's own allocation figures, not recomputed in the browser. It includes feature tests for permission (`finance.receivables.read`) and maker/checker fields.
2. **Invoices list** at `/finance/invoices` in Sales & receivables, on the shared status/money/state/error system.
3. **Invoice detail page:**
   - lines, tax and totals;
   - prepared / checked / issued by and when; the return reason;
   - receipts applied and the balance;
   - actions only where the backend accepts them (check ≠ preparer; issue after check);
   - deliberate confirmation for Issue, Void and Verify receipt.
4. **Receipts:**
   - record → independent verify → allocate;
   - the M-Pesa method shows "M-Pesa receiving account has not yet been configured by Finance." while no active mobile-money account exists;
   - client deposits explained as money held for the client.
5. **Retire the 1,626-line `EnquiryFinanceModal`** once the pages cover it; keep it until then.
6. **Gates:** backend feature tests, component tests, ENG-1, build, and the contract check.

---

**Stream A is complete. Stopping here for review, as instructed (§46):** no W1 work started, production untouched, no cutover, no W8.
