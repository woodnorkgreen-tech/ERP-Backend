# Report 56 — Finance Frontend Redesign: Baseline and Implementation Plan

**Date:** 2026-09-28
**Predecessor:** Report 55. Phase 2B is technically ready: 71/71 smoke steps pass, 0 technical failures, backend 1,512/1,512, `ready=true`.
**Scope:** audit the current Finance frontend and design the redesigned Finance workspace. Stream A (Finance Shell) is then implemented and reported in Report 57.
**Environment:** development only.
- ERP-Frontend and ERP-Backend on branch `finance/critical-stabilization-fixes`.
- No production database, configuration, queue worker, cutover or W8 was touched.
- No opening balances were created (MIG-D7).

**Method.** Every finding comes from code, not recollection:
- the router files and the Finance navigation map;
- a fresh backend route table (`php artisan route:list --json`, 1,365 routes);
- a scripted frontend → backend API contract check;
- a permission-string comparison against `app/Constants/Permissions.php`;
- reading each workflow's controller and service for its states, permissions and maker/checker rules;
- a live probe of Finance endpoints as a user with no permissions.

---

## 1. Executive Summary

**The Finance frontend is functional but organised by the history of its build, not by how WNG works.**

What exists:
- **5 navigation sections, organised by internal build history:** Work queue, Costs & budgets, Payments & cash, Client billing, Controls & reports.
- **22 Finance routes.**
- About 150 Vue/TS files, ~42,000 lines.

Much of the W1–W7 journey lives elsewhere and cannot be reached from Finance navigation:
- W2 procurement: Procurement workspace;
- W6 payroll processing: HR;
- W1 invoices and receipts: a 1,626-line modal;
- W7 labour: a panel inside the cost sheet.

**The good news:**
- The backend is complete and coherent.
- Every Finance permission the frontend checks exists (63/63).
- Finance API calls match live routes except **two dead calls**.
- A sound shared base exists: `FinancePageFrame`, `MoneyValue`, `StatusChip`, `StatTile`, a two-level navigation map with tests, and a server-side **work queue** covering 8 work types.

**The problems are structure, completeness and consistency:**
1. **No Finance landing page.** `/finance` redirects straight into the work queue.
2. **The work queue is incomplete, and it shows work the backend will refuse.** It omits:
   - invoice checking and issuing;
   - requisition disbursement and surrender review;
   - voucher posting and senior approval;
   - payroll lock and payment;
   - both W7 labour verifications.

   It also lists items the viewer created themselves (receipts, vouchers, requisitions, bills, direct disbursements), which the backend's maker/checker rules refuse.
3. **The navigation hides whole workflows.** Nothing in Finance navigation leads to purchasing and payables, invoices as a list, project finance as a whole, or setup of the chart and expense codes.
4. **Status language is raw database values** (`surrender_pending`, `po_verified`, `pending_approval`), and it differs by screen.
5. **Errors are handled ad hoc in 26 files.** The backend returns errors in four shapes (`message`, `error` string, `error` object, `errors`), so users sometimes see "Failed to …" where the backend gave an exact reason.
6. **Two backend gaps with no UI:**
   - department labour classification (D6: `GET/PATCH /api/finance/labour-classification`);
   - a read-only chart of accounts: no endpoint and no screen.
7. **Defects found:**
   - a live **"Reset petty cash data"** button calls a route the backend withdrew;
   - the petty-cash **balance endpoint answers any signed-in user** (verified: HTTP 200 for a user with no permissions). The float figure itself is deliberately exposed to payers through `payment-sources`, but `/balance` also returns thresholds and the last transaction, which is for Stream D to decide on;
   - my Report 55 fix gated **Reconcile** on the wrong permission;
   - supplier-bill verification is authorised by **role names**, not a permission.

**The plan:** ten streams, A–J. Each is made functional against the real backend before the next starts. Stream A (Finance Shell) builds:
- the new information architecture;
- the Overview;
- a complete, role-aware **My actions** queue (the smallest necessary backend change: extending the read-only work-queue projection);
- the shared status, money, state and error system.

**Verdict:** **BASELINE COMPLETE — STREAM A APPROVED TO START.**

---

## 2. Current Finance Frontend Inventory

| Area | Current Route | Components | Backend APIs | Permissions (frontend gate) | Current Problems | Keep / Redesign / Remove |
|---|---|---|---|---|---|---|
| Finance home | `/finance` → redirect | — | — | — | No landing page; redirect into the queue | **Redesign** (Stream A: Overview) |
| Work queue | `/finance/work-queue` | `FinanceWorkQueueView`, `useFinanceWorkQueueCount` | `GET/POST/DELETE/PUT api/finance/work-queue*` | any of 6 finance permissions | 8 of ~20 work types; shows self-created items the backend refuses; raw labels | **Redesign** (Stream A: My actions) |
| Client invoicing / receipts / deposits | `/finance/project-receivables` | `ProjectReceivablesIndex` (777), `EnquiryFinanceModal` (1,626) | `projects/enquiries/{e}/invoices*`, `…/payments*`, `projects/receivables/*`, `finance/payment-terms` | `finance.receivables.read` | Whole W1 lifecycle inside one modal per project; no invoice list across projects; statuses raw | **Redesign** (Stream B) |
| Receivables ageing / P&L | `/finance/reports` | `FinancialReportsView` (431) | `finance/reports/profit-and-loss`, `…/receivables-ageing`, `journals/trial-balance` | `finance.reports.view` | Functional; filtering thin | **Keep, improve** (Stream J) |
| Purchase requisitions | `/procurement/requisitions` | Procurement views | `procurement-stores/requisitions*` | procurement permissions | Outside Finance; unreachable from Finance navigation | **Keep in Procurement; link** (Stream C) |
| Purchase orders | `/procurement/purchase-orders` | Procurement views | `procurement-stores/purchase-orders*` | procurement | As above | **Keep; link** (C) |
| GRNs | `/procurement/goods-receipt-notes` | Procurement views | `procurement-stores/goods-receipt-notes*` | procurement | As above | **Keep; link** (C) |
| Supplier bills / payments | `/procurement/billing` | `BillingCreate` etc. | `procurement-stores/bills*` | procurement; verify = **role names** | Payables unreachable from Finance; verify authorised by role | **Redesign Finance view** (C) |
| WHT | `/finance/tax` | `TaxSchedulesView` (577) | `finance/tax/*` | `finance.reports.view` | Schedules exist; the per-bill WHT view is in the bill form only | **Keep; surface in C** |
| Petty cash requisitions | `/finance/petty-cash/requisitions[/new, /:id, /:id/edit]` | `RequisitionIndex` (1,072), `RequisitionForm` (2,465), `RequisitionShow` (1,461) | `finance/petty-cash/requisitions*` | `view` / `create_disbursement` / `edit_disbursement` | Very large files; Reconcile gate wrong (§9); not in `FinancePageFrame` | **Redesign** (D) |
| Petty cash register / float / custody | `/finance/petty-cash` | `PettyCashIndex` (1,169), `TransactionList`, `TopUpForm`, `DisbursementForm` (1,580), `FundCustodyDashboard` | `finance/petty-cash/*` (81 routes) | `finance.petty_cash.*` | Dead "Reset" action; custody built on `manage_custody` (correct) | **Redesign** (D) |
| Spend (entry door) | `/finance/spend` | `SpendEntryView` | — | none | Chooses between two flows | **Keep** (merge into E) |
| Spend / payment vouchers, accruals | `/finance/payment-vouchers` (`/spend-vouchers` redirects) | `PaymentVouchersView` (902) | `finance/spend-vouchers*`, `finance/payments/{p}/reverse` | `finance.spend_vouchers.read` | Lifecycle not visualised; posting needs a third user, which is not explained | **Redesign** (E) |
| Cost capture / my submissions | `/finance/costs?tab=capture\|mine` | `CostCollectorIndex` (738), `CostCaptureForm` (1,134) | `costs/*` | `finance.costs.create`, `project.costs.read_assigned` | Sound; one route with tab-driven views | **Keep** (restyle in E/H) |
| Cost verification | `/finance/costs/verification` | `CostVerificationView` (750) | `costs/verification*` | `finance.costs.verify` | Sound | **Keep** |
| Project costing / budget vs actual | `/finance/costs?tab=account`, `/finance/costs/accounts` | `CostAccountPanel` (813), `CostAccountsView` | `costs/account*`, `costs/accounts`, `costs/portfolio-margin` | `project.costs.read_assigned`, `finance.costs.read` | No single project finance view; WIP / COS invisible | **Redesign** (H) |
| Project labour (W7) | inside `CostCollectorIndex` | `ProjectLabourPanel` (853), `useProjectLabourActuals` | `costs/projects/{e}/labour-actuals*` | backend `canPoVerifyLabour` / `canFinanceVerifyLabour` | PO and Finance verification not distinguished by screen | **Redesign** (G) |
| Project financial close | modal | `ProjectFinancialClosureModal` | `costs/projects/{e}/close\|reopen\|closure-check` | backend | Hidden in the cost sheet | **Redesign** (H) |
| Payroll Finance | `/finance/payroll-disbursement` | `PayrollDisbursement` (452) → HR composables | `hr/payroll/runs*`, exports | `hr.manage_payroll` | Mixes HR and Finance; no posting / liability view | **Redesign** (F) |
| Payroll processing | `/hr/payroll` | `PayrollManagement` | `hr/payroll/*` | `hr.manage_payroll` | Out of Finance scope | **Keep in HR** |
| Inventory Finance | none in Finance | Stores views | `procurement-stores/*` | stores | No Finance view of inventory value or WIP impact | **New view** (D) |
| Chart of Accounts | **none** | — | **no read endpoint** | — | 152 accounts invisible except via the ledger / trial balance | **New** (I, plus a minimal endpoint) |
| Expense codes | none (picker only: `ExpenseTypeModal`) | — | `costs/expense-codes*` | — | 109 codes unmanageable from the UI | **New** (I) |
| Payment sources | `/finance/setup/paying-accounts` | `PayingAccountsView` (276) | `finance/payment-sources*` | `finance.payment_sources.manage` | Works; unconfigured sources not explained | **Redesign** (I) |
| Department labour classification (D6) | **none** | — | `finance/labour-classification` (GET, PATCH) | backend | Needed at cutover (MIG-D6); no screen | **New** (F/I) |
| Readiness / control centre | `/finance/setup` | `FinanceReadinessView` (156) | `finance/readiness` | `finance.reports.view` | Good; feeds the Overview | **Keep** |
| Requisition types | `/finance/setup/requisition-types` | `RequisitionTypesView` | `petty-cash/requisition-types*` | `finance.requisition_types.manage` | Fine | **Keep** |
| Bank reconciliation | `/finance/reconciliation` | `ReconciliationView` (802) + 6 components | `finance/reconciliation/*` | `finance.reports.view` | Fine | **Keep** |
| Month-end close | `/finance/periods` | `AccountingPeriodsView` | `finance/accounting-periods*` | `periods.manage` / `reports.view` | Fine | **Keep** |
| General ledger | `/finance/ledger` | `GeneralLedgerView`, `JournalEntryDrawer` | `finance/journals*` | `finance.reports.view` | Fine | **Keep** |

---

## 3. Current Finance Routes

22 routes under `/finance`, all inside `MainLayout` → `FinanceShell` (`src/router/finance.ts`):
- `work-queue`
- `setup`
- `setup/requisition-types`
- `setup/paying-accounts`
- `reconciliation`
- `spend`
- `costs`
- `costs/accounts`
- `costs/verification`
- `payment-vouchers`
- `spend-vouchers` (redirect)
- `petty-cash`
- `petty-cash/requisitions`
- `petty-cash/requisitions/new`
- `petty-cash/requisitions/:id`
- `petty-cash/requisitions/:id/edit`
- `project-receivables`
- `payroll-disbursement`
- `tax`
- `periods`
- `ledger`
- `reports`

**Access:**
- Routes carrying `requiresFinanceAccess` pass `canAccessFinance()`: any `finance.*` permission, `hr.manage_payroll`, or `project.costs.read_assigned`.
- Individual pages gate themselves through the navigation filter and the backend.

**Finance work outside `/finance`:**
- `/procurement/requisitions|purchase-orders|goods-receipt-notes|billing` (W2);
- `/hr/payroll` (W6 inputs);
- `/stores/*` (W5 operations).

---

## 4. Current Components

- **Shared** (`modules/finance/shared`):
  - `FinancePageFrame`, used by 20 screens;
  - `FinancePageHeader`, `FinanceNavigation` (two-level rail);
  - `MoneyValue` (12 users), `StatusChip` (6 users, raw status text), `StatTile` (2), `LedgerTable`;
  - `FinanceFormField` / `FormSection` / `ModalTabs`;
  - `usePayingAccounts`, `usePaymentMethods`, `guides.ts`.
- **Not in `FinancePageFrame`:** the requisition index, form, show, preview and statement screens, and the two public sign-off pages (correct for public pages).
- **Petty-cash-local duplicates of shared concerns:** `EmptyState` (0 users), `ErrorBoundary`, and a `useErrorHandler` / `usePermissions` pair.
- **Largest files, which are the redesign risk:**
  - `RequisitionForm` 2,465
  - `EnquiryFinanceModal` 1,626
  - `DisbursementForm` 1,580
  - `RequisitionShow` 1,461
  - `pettyCashService` 1,346
  - `PettyCashIndex` 1,169
  - `CostCaptureForm` 1,134

---

## 5. Backend API Coverage

**Contract check** (`scratchpad/contract.py` against `route:list --json`; the method and path of every `api.*('/api/…')` call; `${…}` treated as a wildcard):

| Module | Calls | Matched | Unmatched |
|---|---|---|---|
| finance | 145 | 144 | `PUT /api/finance/petty-cash/disbursements/{id}` (`usePettyCash.ts:141`, no consumer) |
| finance: `pettyCashService.makeRequest` | 66 | 65 | `DELETE /api/finance/petty-cash/clear-all` (**live button**, §9) |
| procurement-stores | 205 | 205 | — |

Outside Finance (not in scope, recorded for their owners):
- HR still calls the removed `/api/hr/technical-labour` from `CompensationRequestModal`, `OvertimeRequestModal` and `useTechnicalLabour`. That is relevant to W7's rule that Technical Labour is **not** restored.
- A few Projects / Logistics / Client Service calls are also unmatched.

**Backend capabilities with no UI:**

| Endpoint | Purpose | Stream |
|---|---|---|
| `GET/PATCH api/finance/labour-classification` | D6 department direct/indirect classification | F / I |
| `GET api/costs/expense-codes/families`, `POST api/costs/expense-codes` | expense code management | I |
| `POST api/finance/journals/{journal}/reverse` | used by the ledger drawer; OK | — |

**Missing read endpoints the redesign needs:**
- a read-only chart of accounts (§28);
- a cross-project invoice list (§28).

---

## 6. Permission Coverage

- **63 / 63** permission strings used by the Finance, Procurement and Projects frontends exist in `Permissions.php`. None is phantom. Report 55 removed the last two.
- **Role-name checks remain:**
  - `usePermissions.hasRole('Accounts')` on `RequisitionShow` (documented as transitional);
  - `useRouteGuard` Super-Admin shortcuts. These mirror the backend's `Gate::before`, so they are acceptable.
  - **Backend:** supplier-bill verification is authorised by the roles Super Admin, Admin and Accounts (`BillController::canVerify`), not by a permission. The work queue mirrors it. **Recorded for Stream C** as a permission-model gap; not changed here.
- **Probe as a user with no permissions:**

| Endpoint | Status |
|---|---|
| `finance/readiness` | 403 |
| `payment-sources/ledger-accounts` | 403 |
| `labour-classification` | 403 |
| `work-queue/count` | 200, with total 0 (correct) |
| `payment-sources` | 200 (picker data) |
| `costs/expense-codes` | 200 (picker data) |
| **`finance/petty-cash/balance`** | **200.** Readable by anyone signed in. The float amount is deliberately shared with payers through `payment-sources` (see `PaymentSourceController::index`), but `/balance` also returns thresholds and last-transaction detail. **Stream D decides** whether that detail needs `view_balance`. |

---

## 7. Existing User Journeys

| Journey | Today |
|---|---|
| **W1** | Receivables list → open a project → a 1,626-line modal holds invoices, checking, issue, receipts, verification, allocation, credit notes and payment terms. There is no invoice-level list or queue, and receipt verification is reached from the work queue. |
| **W2** | Entirely in Procurement (requisition → PO → GRN → bill → verify → pay). Finance sees supplier invoices only as work-queue items. |
| **W3** | Requisition list / show / form (not in `FinancePageFrame`). The requester surrenders, Finance reconciles. |
| **W4** | The Payment Vouchers screen. The return → correct → resubmit → approve → post lifecycle exists, but the need for a third user to post is not explained until the backend refuses. |
| **W5** | The petty-cash register (float, top-ups, cash counts, custody) plus Stores for stock. There is no Finance view of inventory value. |
| **W6** | Finance "Payroll" = HR's runs list with mark-paid and exports. Processing and locking happen in HR. |
| **W7** | A panel inside the project cost sheet. Recording, PO verification and Finance verification share one panel. |
| **Setup** | Readiness, paying accounts and requisition types. No chart, expense-code or department-classification screens. |

---

## 8. Fragmentation / Duplication Findings

1. **Three places answer "what do I need to do?"**
   - the Finance work queue;
   - `RequisitionIndex` quick actions;
   - each module's own pending tabs.

   Only the first is cross-workflow, and it is incomplete (§12).
2. **Two money-formatting paths:** `MoneyValue`, versus ad hoc `Intl.NumberFormat` in the work queue and others.
3. **Two status systems:** `StatusChip` (raw value, 9 tones), versus per-screen `statusLabel` / `statusColor` helpers (payroll, requisitions, receivables).
4. **Two error systems:** petty cash's `useErrorHandler`, versus `response?.data?.message || fallback` repeated in 26 files. Neither reads the `error` / `errors` shapes some controllers return.
5. **Payment vouchers vs spend vouchers:** one screen, two names. The route redirects, and the navigation says "Payment vouchers".
6. **`SpendEntryView`** is a valid "one door" that sits outside navigation.

---

## 9. Dead Code

| Item | Evidence | Action |
|---|---|---|
| `usePettyCash.updateDisbursement` → `PUT disbursements/{id}` | Route withdrawn (append-only ledger); the composable has **no importer** anywhere | **Remove** (Stream A) |
| `pettyCashStore.clearAllPettyCashData` → `DELETE /clear-all`, plus the "Reset petty cash data" menu item | Route withdrawn. The button is **live** for `manage_settings` holders and can only fail. | Live consumer, so it is **not removed silently**: removed with the Petty Cash redesign (Stream D), where the menu is rebuilt |
| `petty-cash/components/EmptyState.vue` | 0 importers | Remove in D, replaced by the shared state component |
| HR `technical-labour` calls | Endpoint removed (W7) | Report to the HR owner; do not restore (W7 rule) |

**Report 55 correction.** The requisition-list **Reconcile** quick action is gated on `edit_disbursement`. The backend authorises reconcile with `can('create', Payment::class)`, which is **`create_disbursement`**. Both permissions are held by the same roles today, so nobody lost access, but the gate is wrong. **Fixed in Stream A.**

---

## 10. Proposed Finance Information Architecture

Organised by how WNG works. Each page is an existing screen or a stream deliverable; nothing points at a page that does not exist.

| Section | Pages (stream that delivers or redesigns) |
|---|---|
| **Overview** | Overview (A), My actions (A) |
| **Sales & receivables** | Invoices & receipts (B), Receivables ageing (J) |
| **Purchasing & payables** | Supplier bills, Purchase orders, Goods received, Purchase requisitions: linked into Procurement now, with a Finance payables view in C; Withholding tax (C, `/finance/tax`) |
| **Expenses & cash** | Record expense, My submissions, Cash requisitions, Petty cash register, Payment vouchers (D/E) |
| **Project finance** | Project cost sheet, Budget vs actual, Cost verification (G/H) |
| **Payroll finance** | Payroll runs & posting (F) |
| **Reports & controls** | Reports, Account book (ledger), Tax review, Bank matching, Close month (J) |
| **Finance setup** | Control centre, Money accounts, Request forms (I), plus Chart of accounts, Expense codes and Departments as Stream I delivers them |

**Rules:**
- A page appears only when the user holds its permission.
- Links into another workspace (Procurement) are marked as such.
- Detail routes resolve to their section.
- Staff advances and Inventory Finance get pages when their streams deliver them (D, E), not before.

---

## 11. Finance Overview Design

The Overview answers, in order: **what needs me → what is wrong → where the money stands → where to go.**

1. **Needs attention:** counts per work type from `GET finance/work-queue/count`, grouped by area. Each card links to My actions filtered to that type. It shows only types the user may act on.
2. **Exceptions:**
   - the oldest work over 7 / 30 days (`work-queue?priority=exception`);
   - overdue receivables buckets (`reports/receivables-ageing`, when `finance.reports.view`);
   - configuration items: readiness checks not ready, integrity counters above 0, and paying accounts with no ledger link (for example **"M-Pesa receiving account has not yet been configured by Finance."**).
3. **Balances:**
   - receivables outstanding and overdue (ageing totals);
   - petty-cash float on hand (only with `finance.petty_cash.view_balance`).
4. **Navigation:** the sections the user can open, with one line each.

**Honesty rule:** every block states its source. A block the user may not see is not rendered. A block that fails shows an error with its reason. **No figure is estimated or invented.** There is no WIP or P&L headline on the Overview; those belong to Project finance (H) and Reports (J).

---

## 12. My Actions Design

**Source:** the server-side work queue, extended so that it is complete and matches what the backend will accept.

| Work type | State | Permission (backend) | Maker/checker exclusion |
|---|---|---|---|
| invoice_check | draft, not checked | `finance.receivables.invoice_check` | not the preparer |
| invoice_issue | draft, checked | `finance.receivables.billing_basis` | — (WNG allows checker = issuer) |
| invoice_correction | draft, returned, not resubmitted | preparer only | — |
| client_receipt | pending, not reversed | `finance.receivables.verify` | not the recorder, unless self-approve |
| supplier_invoice | unverified, open | roles Super Admin / Admin / Accounts (as `BillController`) | not the recorder, unless self-approve |
| purchase_requisition / purchase_order | pending_approval | procurement approve | (unchanged) |
| fund_requisition | pending | `edit_disbursement` | not the requester, unless self-approve |
| fund_disbursement | approved | `create_disbursement` | — |
| fund_surrender | disbursed / received / surrender_returned | the requester | — |
| fund_surrender_review | surrender_pending | `create_disbursement` | — |
| direct_disbursement | pending_approval | `spend_vouchers.approve` | not the requester, unless self-approve |
| spend_voucher | pending_approval, not returned | `spend_vouchers.approve` | not the requester, unless self-approve |
| spend_voucher_correction | returned for correction | the requester | — |
| spend_voucher_senior | approved, awaiting senior | `spend_vouchers.approve_senior` | not the requester or approver |
| spend_voucher_post | approved, not posted, no senior pending | `spend_vouchers.post` | not the requester or approver |
| labour_po_verify | recorded | `canPoVerifyLabour` (project-scoped) | — |
| labour_finance_verify | po_verified | `finance.labour.finance_verify` | — |
| payroll_lock | processing | `hr.manage_payroll` | not the creator, unless self-approve |
| payroll_payment | locked | `hr.manage_payroll` | not the locker, unless self-approve |

- Each item carries an **area** (sales, purchasing, cash, project, payroll) for grouping, and a plain-language action ("Check invoice", "Verify receipt", "Post voucher", "Record surrender").
- **The queue never approves anything:** it links to the source screen, which remains authoritative. Claim / release / reassign continue.

---

## 13. W1 Design (Stream B)

**Invoice list across projects**, filtered by client, project, officer, status and due date. Status runs: **Draft → Checked → Issued → Part-paid → Paid**, or **Void**.

**Invoice detail:**
- the commercial basis (approved quote);
- lines, tax and totals;
- **who prepared, checked and issued it, and when**;
- the return reason;
- receipts applied and the balance.

**Receipts:** Record → **Verify** (another person) → **Allocate** → settled.

**Client deposits** are shown as "money received before invoicing", held for the client, never as revenue.

**M-Pesa:**
- The picker lists only active, linked receiving accounts.
- If no active mobile-money account exists, the M-Pesa method shows **"M-Pesa receiving account has not yet been configured by Finance."** instead of an empty picker.
- Once MIG-P1 links it, it works with no code change.

**Backend need:** a cross-project invoice index (§28).

---

## 14. W2 Design (Stream C)

**A Finance "Payables" view:** supplier bills with their document chain (**REQ → PO → GRN → BILL → PAYMENT**) as linked chips.
- Its states come from the existing `PurchaseOrderWorkflow::bill()` state, which is backend-authoritative.
- Operational procurement screens stay in Procurement, linked, not duplicated.

**WHT:**
- The bill shows the invoice amount, the WHT retained, **the amount payable to the supplier** (backend `payableAmount()`), and the WHT liability.
- Nothing is calculated by hand.

**Permission gap:** bill verification moves from role names to a permission (smallest safe change, with a test).

---

## 15. W3 Design (Stream D)

**One Petty Cash workspace**, with tabs for requisitions, approvals, disbursements, surrenders and reconciliation, the float (top-ups, cash counts) and custody.
- Every action is gated on the permission the backend enforces: `edit_disbursement` approve, `create_disbursement` disburse and reconcile, `manage_custody` custody, `review_cash_count` count review.
- Custody stays on `finance.petty_cash.manage_custody` (R-2): granting it to a named custodian or role needs no frontend change.
- The dead Reset action is removed.
- The requisition list labels `received` as **"Complete"**, but the backend still accepts a surrender and reconciliation from `received`. Stream D confirms the meaning of `received` and corrects the label.
- Stream D decides whether `/balance` detail beyond the float amount needs `view_balance`.

---

## 16. W4 Design (Stream E)

**A voucher lifecycle stepper:** Draft → Submitted → (Returned → Corrected → Resubmitted) → Approved → (Senior approved) → Posted.
- Each step shows **who and when**, from `spend_voucher_reviews` and the voucher columns.
- Action buttons appear only for a user the backend will accept, applying the same exclusions as §12.
- Posting by a third person is explained up front: "The requester and approver cannot post this voucher."
- Accruals (GRN liabilities) are listed as "Eligible to pay".

---

## 17. W5 Design (Stream D)

**Finance-facing inventory, read-only:** inventory value (IA-001 balance), receipts into stock, materials issued to projects and their WIP effect, and adjustments. Operational stock stays in Stores.

**Data:** the existing journals / trial balance endpoints plus Stores movements. If a per-project issue summary is not efficiently available, it is recorded as a backend need, not computed in the browser.

---

## 18. W6 Design (Stream F)

**Payroll Finance:** runs by period with status (Draft → Processed → Locked → Paid), posting status (the accrual journal), payment status (the payment journal) and liabilities (PAYE / SHIF / NSSF / Housing / net pay).
- Salaries are **never** shown without `hr.manage_payroll`.
- HR inputs stay in HR.
- **Department classification (D6)** becomes a Finance setup screen on the existing `labour-classification` API, so MIG-H2 can be done in the UI at cutover.

---

## 19. W7 Design (Stream G)

The final W7 model: Employees are authoritative, labour is planned from the project budget, and actuals are recorded against it. There is **no Technical/Casual Labour master**.

| View | Contents |
|---|---|
| **Project Officer** | Planned vs actual labour on their projects; record; **PO verify** |
| **Finance** | **Finance verify** (rate resolution, cost); review |

- The two verifications are shown as two distinct stamps.
- A Project Officer never sees a Finance-verify control.

---

## 20. Project Finance Design (Stream H)

**One project page:**
- **Commercial:** quote, invoiced, received, outstanding.
- **Budget:** approved budget, planned cost.
- **Actual by family:** materials, labour, subcontractors, transport, equipment/site, utilities, facilitation, venue/statutory, rework/warranty.
- **Position:** budget vs actual (table: Family | Budget | Actual | Variance | Status, with drill-down), WIP, cost of sales released, remaining WIP, financial status (open / closed), and financial close.

**Rules:**
- Totals come from backend endpoints (`costs/account`, `closure-check`), never from a second calculation in the browser.
- The WIP panel reads the policy from the backend. Under `expense_on_capture` it shows "Costs are expensed when captured; there is no WIP to release" rather than zeros.
- No salary figures.

---

## 21. COA Design (Stream I)

**A read-only chart:** code, name, category/type, normal balance, parent, active/postable, and the **Finance function** mapped to it (from the chart profile, for example "Input VAT → VAT-002").
- WNG accounts are shown as the chart. Configuration concepts (functions, the WIP policy) sit in a separate panel.
- No delete or renumber action.
- The 4 unclassified accounts and the 2 deprecation candidates are flagged.

**Backend need:** `GET api/finance/chart-of-accounts`, read-only, `finance.reports.view` (§28).

---

## 22. Expense Code Design (Stream I)

A table showing code, meaning, family, **default account** (or "Chosen per transaction" for the NE-* codes), active/inactive with the reason, and project/office use (`job_id_rule`).
- Creating a code uses the existing `POST costs/expense-codes`.
- The mapping internals are not exposed.

---

## 23. Payment Source Design (Stream I)

A table: **Source | Account | Status | Usage**.

| Source | Status shown |
|---|---|
| MPESA, CARD | **"Not configured — disabled"**, with the MIG-P1 / MIG-P2 note |
| KCB, Stanbic, Family | "Inactive — Finance to confirm use" (KCB is in use per the source) |

Linking a source or activating it remains the existing admin action.

---

## 24. Reports Design (Stream J)

Profit & Loss and Receivables Ageing are kept, with better period/as-of filters, bucket drill-down and CSV (both backend-supported).

**No new report is built.** Future needs, recorded only:
- project profitability;
- payables ageing (the type exists; the endpoint is not exposed);
- WIP by project;
- cash position;
- VAT/WHT returns summary.

---

## 25. Shared Components

Built in Stream A and used from then on:

| Piece | Purpose |
|---|---|
| `shared/status.ts` + `StatusChip` (with `domain`) | One vocabulary: Draft, Pending, Awaiting review, Approved, Verified, Posted, Paid, Partially paid, Returned, Rejected, Reconciled, Closed, Void. It maps each domain's backend value to a label and tone; backend values are unchanged. |
| `shared/money.ts` (`formatMoney`) + `MoneyValue` | One money format; ad hoc `Intl` calls replaced as screens are touched |
| `shared/apiErrors.ts` (`financeErrorMessage`) | Normalises every backend error shape to an actionable sentence |
| `FinanceState.vue` | Loading / error / empty, with specific messages ("No supplier bills awaiting verification.") |
| `FinancePageFrame` / `FinancePageHeader` / `FinanceNavigation` | The existing page structure, fed by the new IA |

---

## 26. Error / Empty-State Design

| Case | Message pattern |
|---|---|
| 403 | "You don't have permission to …". The backend's own sentence is preferred when it gives one. |
| 422 (state / maker-checker) | The backend's sentence verbatim, for example "You prepared this invoice, so someone else has to check it." |
| 422 (validation) | The first field message |
| 409 | "Someone else already …" (claim or duplicate) |
| 404 | "This record no longer exists or was moved." |
| 419 / 401 | "Your session has expired. Sign in again." |
| 5xx / network | "The server could not complete this. Nothing was changed; try again." (Never a stack trace.) |
| Empty | Names the thing and the reason: "No receipts awaiting verification." |
| Configuration gap | "M-Pesa is not yet linked to a receiving account." |

---

## 27. Permission Model

- **The frontend is convenience; the backend decides.**
- Navigation shows a page when the user holds its permission.
- An action is shown when the user holds the permission **and** the §12 maker/checker exclusions do not apply. That information comes from the server (the queue) or the record (`created_by` and so on).
- **View is separated from action:** a Project Officer sees their project's finance data (`project.costs.read_assigned`) and PO-verify, never Finance verify.
- **Configuration-aware:**
  - R-2: custody works for whoever holds `manage_custody`.
  - MIG-P1/P2: sources appear when active and linked.
  - WIP: the policy is read, not assumed.
  - D6: the classification screen exists before the decision is made.

---

## 28. Required Minimal Backend Additions

| # | UI requirement | Existing limitation | Proposed addition | Stream |
|---|---|---|---|---|
| 1 | Complete, trustworthy My actions | The queue omits 11 work types and lists self-created work the backend refuses | Extend the read-only `FinanceWorkQueueService` (new types, exclusions, `area`). No workflow or posting change. | **A** |
| 2 | Cross-project invoice list | Invoices only per enquiry | `GET api/finance/invoices` (paginated, filtered, read-only) | B |
| 3 | Bill verification by permission | Role names | A permission constant plus a migration grant to the same roles | C |
| 4 | Petty-cash balance detail | Thresholds and last transaction readable by anyone | Decide on a `view_balance` gate for the detail (the float amount stays shared) | D |
| 5 | Chart of accounts screen | No endpoint | `GET api/finance/chart-of-accounts` (read-only) | I |
| 6 | Payables ageing report | Service exists, no route | Route only | J (optional) |

---

## 29. Screens to Keep

Cost verification, cost capture / my submissions, bank reconciliation, month-end close, general ledger, tax schedules, requisition types, readiness (control centre), P&L / ageing (improved in J), and the Procurement and HR operational screens.

## 30. Screens to Redesign

Finance home (→ Overview), work queue (→ My actions), receivables (B), payables view (C), petty cash workspace + requisitions (D), payment vouchers (E), payroll finance (F), project labour (G), project finance / budget vs actual / close (H), paying accounts (I).

## 31. Screens to Remove / Merge

| Item | Action |
|---|---|
| `/finance` → work-queue redirect | Replaced by Overview (A) |
| `/finance/spend-vouchers` | Stays a redirect |
| "Reset petty cash data" | Removed (D) |
| `EmptyState.vue` (petty cash), `usePettyCash.updateDisbursement` | Removed (A / D) |
| `SpendEntryView` | Merged into the Expenses section entry (E) |
| The receivables modal | Becomes an invoice / receipt page (B); the modal is retired when B lands |

---

## 32. Implementation Streams

A Shell → B W1 → C W2 → D W3/W5 → E W4 → F W6 → G W7 → H Project finance → I Setup → J Reports.

Each stream is functional against the real backend, tested, type-checked against ENG-1 and built before the next starts.

---

## 33. Test Strategy

Per stream:
- **Backend:** feature tests for any backend addition, including permission and maker/checker cases, plus the affected existing suites.
- **Frontend:**
  - vitest unit tests for pure modules (navigation, status, money, errors);
  - component tests with mocked `api` for screens;
  - permission cases (visible / hidden per permission);
  - state-transition cases.
- **Gates:** `vue-tsc` (ENG-1: no new errors vs the 256 baseline), `vite build`, and the API contract check (0 new unmatched Finance calls).
- **Where helpful:** a rehearsal-target smoke run.

---

## 34. Risks

| Risk | Mitigation |
|---|---|
| Very large single files (§4) resist change | Redesign by extraction, stream by stream; never a big-bang rewrite |
| The queue shows work the backend will refuse | Same predicates as the controllers, tested per type (A) |
| Rules duplicated in the frontend | The frontend asks the server; it never recomputes status, totals or eligibility |
| Configuration decisions change behaviour (WIP, M-Pesa, custody) | Configuration-aware UI; no hard-coding |
| No browser capture in this environment | Evidence is route + endpoint + permission + test result; screenshots when a browser is available |
| A concurrent writer in ERP-Backend | Commit by explicit path; no stash, no `add -A` |

---

## 35. Exact First Implementation Stream

**Stream A — Finance Shell:**
1. Extend the work queue (§12) with backend tests.
2. The new IA in `navigation.ts`, with tests.
3. `/finance/overview` plus the `/finance` redirect.
4. My actions (the work-queue view on shared components, filterable by type from the Overview).
5. Shared `status.ts`, `money.ts`, `apiErrors.ts` and `FinanceState.vue`, with unit tests.
6. Correct the Reconcile gate.
7. Remove `updateDisbursement`.

Stream A is reported in Report 57.

---

## 36. Final Verdict

**BASELINE COMPLETE — STREAM A APPROVED TO START.**

The backend supports the redesign with only small, read-only additions (§28). No accounting or posting logic changes. No production system is touched.
