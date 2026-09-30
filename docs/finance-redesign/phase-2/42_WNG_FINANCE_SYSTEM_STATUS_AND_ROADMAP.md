# 42 — WNG Finance: System Status, Completeness & Roadmap Audit

**Date:** 2026-09-28
**Type:** Observational status/evidence audit. No code, migrations, frontend, or Decision Register status was changed.
**Evidence base:**
- Documentation: Decision Register `03`; Phase 1 current-state documents 01–13; Phase 1.5 reports 01–09; Phase 2 briefs and gap analyses 10–26; Phase 2B implementation, closure and alignment reports 27–41.
- Repositories: `ERP-Backend` and `ERP-Frontend`, both on branch `finance/critical-stabilization-fixes`, as pushed to origin on 2026-09-28.
- Test runs executed on 2026-09-28 (§21).

---

## 1. Current-State Statement

> **Finance redesign is currently in Phase 2B (confirmed-workflow implementation). The Stabilization items and the confirmed subsets of W1–W6 are closed, with policy follow-ups. W7 is functionally complete but closure-blocked by one repository-level engineering item (frontend type-check baseline). W8 is at decision-brief stage, with no decisions and no implementation. W9 and W10 are decisions-pending. The full Finance frontend redesign has not started. None of the Phase 2B work has been released to production.**

The last point is not in any report and matters most operationally. Until 2026-09-28 every Phase 2B change sat uncommitted in both working trees. It is now committed on a feature branch and pushed, but not merged to `master`. A merge to `master` deploys automatically and runs 23 finance migrations with `migrate --force`.

## 2. Phases

| Phase | Content | Status | Evidence |
|---|---|---|---|
| Phase 1 — Current-State Audit | `current-state/01–13` | **Complete** | Dated 2026-09-22 |
| Phase 1.5 — Stabilization plan, preservation register, decision register, requirements, roles, navigation, reporting | `phase-2/01–09` | **Complete** | Register is live and maintained |
| Phase 2 (briefs) — Decision briefs and gap analyses W1–W8 | `phase-2/10–26` | **Complete up to W8's brief** | No brief exists for W9 or W10 |
| Phase 2B — Implementation waves | Wave 1 (W1), Wave 2 (W2), Wave 3 (W3+W4+W5), alignment pass, W6, W7 | **Active** | Reports 27–41 |
| Release of Phase 2B to production | — | **Not started** | Nothing merged to `master` in either repo |
| Full Finance frontend redesign | — | **Not started** | No document defines it as a phase; Report 07 holds a *proposed* target information architecture only |

- **Current workflow:** W7 (Labour Cost).
- **Most recently closed:** W6 Project Costing (Report 36, 2026-09-24).
- **Open:** W7 (Report 41: FAIL, one blocker).
- **Next planned:** W8 Logistics/Fleet Cost (decision brief only).
- **Not started:** W8, W9 and W10 implementation; production release; full UX redesign.

## 3. Master Workflow Status Matrix

The workflow numbering is the Decision Register's (W1–W10, plus STAB, ROLE, NAV and RPT rows). "Waves" are implementation batches, not workflows.

| Workflow / Area | Purpose | Decisions | Backend | Permissions | Functional Frontend | Tests | Closure Gate | Current Status |
|---|---|---|---|---|---|---|---|---|
| Financial Integrity Stabilization (STAB-1, 3–7) | Fix 7 critical integrity risks | Confirmed | Implemented | Yes | Where applicable | Pass | Wave 1/2 closures (28, 30) + alignment (33) | **CLOSED**. Pending follow-ups: STAB-1 live chart-data verification, STAB-6 backfill decision, STAB-7 historical remediation |
| STAB-2 WIP vs immediate COGS | Accounting policy | Open | Deliberately not built | — | — | — | — | **BLOCKED BY FINANCE/ACCOUNTANT DECISION** |
| Finance Foundation (Cost Collector, JournalPostingService, PaymentSettlementService, periods, tax) | Patterns to preserve (Report 01) | n/a | Existing, preserved | Existing | Existing | Full suite pass | No separate gate; covered by each closure's regression | **CLOSED** (preserved foundation) |
| W1 Client Billing / AR | Invoice check/issue, quote exception, financial position, discounts, credit notes, terms | W1-1..9 confirmed | Implemented | Yes | `ProjectReceivablesIndex`, `EnquiryFinanceModal` | Pass | Report 28 + 33 | **CLOSED**. W1-10 blocked (below) |
| W1-10 Credit-note WIP reversal | Accounting policy | Open | Not built | — | — | — | — | **BLOCKED BY FINANCE/ACCOUNTANT DECISION** |
| W2 Procurement to Payment / AP | Senior approval, evidence, staged billing, amendments, duplicates, PO return | W2-1..6 confirmed | Implemented | Yes | PO/Bill screens in Procurement | Pass | Report 30 + 33 | **CLOSED** |
| W2-7..10 PO close, services, supplier credits, emergency | Lifecycle gaps | Open | Not built | — | — | — | — | **DECISIONS PENDING** (W2-9 also needs accountant) |
| W3 Expenses | Cash purchase, advance payout, duplicate receipts, surrender return/reversal, advance recovery | Confirmed (W3-4 open) | Implemented | Yes | Petty-cash and HR screens | Pass | Report 32 | **CLOSED** |
| W4 Payment Vouchers | Voucher return/reject, senior approval mechanism | W4-1/2 confirmed; W4-3/4 open | Implemented (W4-2 inactive until threshold) | Yes | `PaymentVouchersView` | Pass | Report 32 | **CLOSED** (W4-3/4 decisions pending) |
| W5 Petty Cash | Custody, counts, thresholds, surrender ageing, advance control | Confirmed (values open) | Implemented | Yes | `PettyCashIndex`, `PettyCashControlsPanel` | Pass | Report 32 | **CLOSED** (policy values pending) |
| Payments / Settlements | One payment rail | Confirmed via STAB-6, W2–W4 | Implemented | Yes | Across screens | Pass | Via W2–W5 closures | **CLOSED** |
| Project Budget | Planning source for costing and labour | Existing control (W6-11 preserve) | Existing + `BudgetRevisionRecorder` | Existing | Budget task in Projects | Pass | Not a gated workflow | **CLOSED** (existing control preserved) |
| W6 Project Costing | Direct margin, portfolio, allocation, transfer, financial closure | Confirmed subset | Implemented | Yes | `CostAccountsView`, `CostAccountPanel`, modals | Pass | Report 36 | **CLOSED** (W6-1A, W6-7, W6-12 open; Report 36 documentation defects, §22) |
| W7 Labour Cost | Actual labour against budget lines, analytical CostLines | W7-1..23 confirmed; W7-24/25/26 open | Implemented and remediated | Yes | `ProjectLabourPanel` | Pass (84 backend, 23 frontend) | Report 40 FAIL → Report 41 FAIL | **CLOSURE FAILED — REMEDIATION REQUIRED**. The remediation is an engineering-baseline decision, not W7 functionality (§7–8) |
| W8 Logistics / Fleet Cost | Trip/vehicle cost into project costing | W8-1..5 open | None; zero Cost Collector links in Logistics | — | — | — | — | **DECISIONS PENDING** (brief 26 exists) |
| W9 Assets | Asset finance scope | W9-1 open | None | — | — | — | — | **DECISIONS PENDING** (no brief) |
| W10 Payroll Finance integration | Payroll payment, segregation, posting authority | W10-1..3 open; STAB-6 forward fix done | Payment linkage only | Existing HR permissions | `PayrollManagement`, `/finance/payroll-disbursement` | Pass | STAB-6 only | **DECISIONS PENDING** |
| Cash / Banking | Reconciliation, paying accounts, bank position | RPT-1 open | Reconciliation and payment sources exist; no standing bank position | Yes | `ReconciliationView`, `PayingAccountsView` | Pass | No gate | **DECISIONS PENDING** (RPT-1) |
| Journal / Ledger | GL, trial balance, exports | STAB-1 data check pending; NAV-3 open | Existing | Yes | `GeneralLedgerView` | Pass | No gate | **DECISIONS PENDING** |
| Financial Reporting | P&L, TB, AR/AP ageing, BS, CF, profitability | RPT-1/2 open; equity/opening-balance policy not in register | P&L/TB/ageing incomplete; BS/CF missing; portfolio margin built (W6-2) | Yes | `FinancialReportsView`, AP ageing in Procurement | Pass | No gate | **BLOCKED BY FINANCE/ACCOUNTANT DECISION** (Balance Sheet/equity) and DECISIONS PENDING (RPT-1/2) |
| Period Close | Accounting periods | No open decision found | Existing | Yes | `AccountingPeriodsView` | `AccountingPeriodControlTest` pass | No Phase 2B gate | **IMPLEMENTED — NOT VERIFIED** (no redesign gate) |
| Tax / statutory | VAT/WHT schedules, eTIMS claim gate | No open register row | Existing | Yes | `TaxSchedulesView` | Pass | No Phase 2B gate | **IMPLEMENTED — NOT VERIFIED** (no redesign gate) |
| Finance permissions / governance | Real role mapping | ROLE-1/2/3 open | Permissions defined per workflow | Many deliberately unassigned (reopen, exceptions, senior approvals) | — | Permission tests pass | — | **BLOCKED BY WNG DECISION** |
| Finance configuration | Thresholds, terms, deadlines | Values open | `finance_settings` mechanism | — | **No settings UI** (Report 32 follow-up 3) | — | — | **DECISIONS PENDING** |
| Navigation / information architecture | Finance navigation | NAV-1/2/3 open | n/a | n/a | Current rail (§16) | `financeNavigation.spec` pass | — | **DECISIONS PENDING** |
| Full Finance UX redesign | Coherent product | Not defined | n/a | n/a | n/a | n/a | n/a | **NOT STARTED** |
| Production release of Phase 2B | Ship the work | — | — | — | — | — | — | **NOT STARTED** |

## 4. W1–W6 Verification

| Workflow | Decisions | Backend / Frontend | Audit | Tests | Closure | Known follow-ups | Later regression risk |
|---|---|---|---|---|---|---|---|
| W1 | W1-1..9 confirmed | Both present (verified in Report 28 Part B and Report 33) | Check/return/exception logged | `InvoiceReviewWorkflowTest`, `ClientFinancialPositionTest`, `enquiryFinanceModal.spec` pass | Report 28 | W1-10; term values; overpayment escalation age; old blended "Client Outstanding" label not yet removed from existing screens | Low; full suite green |
| W2 | W2-1..6 confirmed | Both present | Amendments and corrections history | Procurement suite + `wave2Controls.spec` pass | Report 30, frontend verified in Report 33 | W2-7..10; thresholds | 2 new type errors in `useOrderWorkflow.ts` from uncommitted W2-era work (§8) |
| W3 | Confirmed; W3-4 open | Both present | Review history, reversals | `Wave3*` suites pass | Report 32 | Evidence matrix; salary-advance GL treatment | Low |
| W4 | W4-1/2 confirmed | Both present | `spend_voucher_reviews` | Pass | Report 32 | Threshold; W4-3/4 | Low |
| W5 | Confirmed; values open | Both present | Custody/count records | Pass | Report 32 | Threshold, deadline and limit values; variance GL treatment; settings UI | Low |
| W6 | Confirmed subset | Both present | Transfers/allocations/closure logged | Cost Collector suites + `wave6Controls.spec` pass | Report 36 | W6-1A, W6-5 checklist, W6-6 authority, W6-7, W6-11, W6-12 | **Touched by W7:** `CostTransferService` (race fix, `correct()`, bcmath) and `CostAccountService` (bcmath, shared category expression, `actual_labour`). The full suite (1,427) is green, so no regression was detected |

Before 2026-09-28, none of W1–W6 existed in any commit. W6 was last verified by the full suite that day.

## 5–6. (Status vocabulary applied in §3.)

## 7. W7 — Current Truth

**W7 now has** (repository-verified; Report 41):
- **Budget-line capture:** the server copies role, category, unit and rate from the approved Project Budget line. Budget revision is handled: consumption follows the budget-line ID and the recorded rate is kept.
- **Review lifecycle:** Record → PO verify → Return for Correction → Resubmit, with immutable return history → PO verify → Finance rate resolution (unbudgeted labour only) → Finance verify.
- **Costing integration:** Finance verify writes an analytical CostLine (`postsIndependently=false`; the company ledger delta is proven zero).
- **Corrections (W7-13):** a W6-4 reversing pair via `CostTransferService::correct()`, and reclassification via `transfer()`.
- **Project Costing:** W6 labour row, with the monetary matrix proven. Portfolio gets `actual_labour`, reconciled, with a flat query count.
- **Controls:** closed-project guards on every transition (backend + UI); payroll privacy (allow-listed resource); an audit event for every transition.
- **Concurrency:** true forked-process race tests.
- **People:** Employee Records as the personnel source. Technical Labour selection is retired in in-use modules; per WNG instruction, work orders, overtime and Technical Labour are left out as not in use.
- **Tests:** 84 backend tests and 23 frontend tests.

**The one current blocker:** the frontend repository type-check (`vue-tsc`) fails.

> **W7 is functionally complete. It is closure-blocked by a repository engineering baseline, not by W7 functionality.**

Evidence: every Report 40 functional and evidence item passes executable tests (Report 41 §34). The type-check has **0** errors in any W7-touched file.

## 8. Type-Check Issue — Evidence for WNG

| Fact | Evidence |
|---|---|
| Current errors | **258** (2026-09-28) |
| At committed HEAD (pre-Phase-2B frontend) | **256** |
| W7-related | **0** |
| New since HEAD | **2**, both in `procurement-stores/shared/composables/useOrderWorkflow.ts` (action union lacks `'correct'`/`'senior-approve'`), from W2-era uncommitted work |
| Documented pre-W7 measurement | **Yes.** Report 31, line 278 (2026-09-23): *"vue-tsc: 256 errors project-wide, all pre-existing — 0 in any file changed or added this wave."* |
| Formally *accepted* baseline | **No.** No decision records acceptance. CI (`deploy.yml`) does not run type-check |
| How earlier closures treated it | W1–W3 (Reports 28–33) and W6 (Report 36) all **closed without a clean type-check**. Report 31 measured the debt and Report 36 did not run type-check. The requirement was first applied as a blocking criterion by the W7 closure directives (Reports 40/41) |
| Engineering rule text (register line 12) | Requires Backend + Frontend Tests; it does **not** name type-check. The type-check criterion came from the W7 directives |
| Nature | Repository-wide technical debt concentrated in universal-task, production/work orders, projects, printing and logistics. **Not** finance-redesign-introduced (except the 2 procurement errors) |

**Correction to Report 41:** §27 stated that *"no document establishes"* a baseline. More precisely, a baseline *measurement* is documented (Report 31), but no baseline *acceptance* exists.

**The decision WNG needs to make** (no rule is changed here). Either:
- **(a)** formally adopt the Report 31 / committed-HEAD set (256) as the baseline, with a zero-new-errors rule for finance work. This matches how W1–W6 were closed. It would also require fixing or accepting the 2 procurement errors. Or
- **(b)** require a clean repository-wide type-check before any further workflow closure. That is a separate engineering project, largely outside Finance.

## 9. W8 Status

- **Status:** decision brief only (Report 26, 2026-09-23). W8-1..5 are all **AWAITING WNG CONFIRMATION**. No architecture reconciliation (the W6/W7 pattern of Reports 34/37), no decision confirmation, no implementation, no frontend, no tests.
- **Defined scope:** how logistics cost reaches a project (trip-based vs other, W8-1); maintenance-log/bill reconciliation (W8-2); internal vehicle cost method (W8-3); multi-project trip allocation basis (W8-4); maintenance treatment (W8-5).
- **Existing data (per the brief):** `TripRequest.project_id` and per-leg distance already exist. Logistics has zero Cost Collector integration. There are no fuel cost records.
- **Prerequisites from W6/W7:**
  - Cost Collector analytical CostLines with `postsIndependently` semantics (W7 pattern).
  - W6-3 allocation (multi-project split).
  - W6-4 transfer (corrections).
  - W6-5 closure guard.
  - The W6 `cost_completeness.logistics` flag (currently `not_included`).
  - Driver labour is W7's question (drivers are Employees).
  - W7 closure should precede W8 so the analytical-CostLine and correction patterns W8 would reuse are closed.

## 10. Remaining Workflows After W7/W8

| Workflow | Purpose | Dependencies | Decisions | Implementation | Accountant input | Frontend work | Recommended sequence |
|---|---|---|---|---|---|---|---|
| W9 Assets | Asset finance scope (register → depreciation?) | Balance Sheet/equity policy; ledger | W9-1 open; no brief | None | **Yes** (depreciation, capitalisation) | Yes, if built | After W8; brief first |
| W10 Payroll | Backfill, three-person segregation, posting authority | ROLE-1/2 | W10-1..3 open | STAB-6 forward fix only | Partly (posting authority) | Likely small (payroll screens exist) | After W8, parallel with W9 decisions |
| Deferred items inside closed workflows | W1-10, W2-7..10, W3-4, W4-3/4, W6-1A, W6-7, W6-12, W7-23..26 | Various | Open | Not built | Several | Some | As decisions arrive; none block W8 |
| Reporting completion | Balance Sheet, Cash Flow, bank position, KPI dashboard | Equity policy, W8, W9, RPT-1/2 | Open | Partial | **Yes** | Yes | Late; after W8 and the equity decision |

No further workflow numbers exist in the architecture. None are invented here.

## 11. Open WNG Business Decisions

**Blocking now**
- None of the register's business rows blocks W7. The single current blocker is the **type-check baseline**, an engineering-governance decision that is *not* a register row (§8).

**Blocking a future workflow or a release**
- **W8-1..W8-5:** block W8 entirely.
- **W9-1:** blocks W9.
- **W10-1, W10-2, W10-3:** block W10.
- **ROLE-1, ROLE-2, ROLE-3:** block assigning real holders to the implemented permissions (reopen, advance exception, senior approvals, labour capabilities), and hence operational go-live of those controls. They also shape redesign navigation.
- **NAV-1, NAV-2, NAV-3:** block the redesign information architecture.
- **RPT-1, RPT-2:** block the bank-position and KPI-dashboard designs.
- **W2-7, W2-8, W2-10:** block PO close/cancel, service confirmation and the emergency route.
- **W4-3, W4-4:** block voucher escalation and delegation.

**Non-blocking / can be deferred**
- **Policy values:** W2-1 and W4-2 thresholds and senior approver; W5-5 float thresholds; W5-8 deadline; W5-9 limits; W5-7 count frequency; W1-7 term values; W1-9 escalation age.
- **Detail rules:** W3-4 evidence matrix; W5-4 field simplification; W6-5 closure checklist; W6-6 reopen authority; W6-11 baseline reporting; W6-12 as-of-date reporting; W7-23 role scoping; the W2-5 rule refinements.
- **Other:** STAB-6 backfill; the unbudgeted-labour standing-rate question (Report 41 §31).

## 12. Open Finance / Accountant Decisions

| ID | Concerns | Workflow | Blocks | Can safely proceed without it |
|---|---|---|---|---|
| STAB-2 | WIP vs immediate COGS | Costing/GL | WIP accounts, cost-of-sales timing | All analytical costing (current behaviour kept) |
| STAB-1 (data) | Live chart vs documented chart | GL | Confirmed account mapping values | Code already routes via `ChartAccountMap` |
| STAB-7 (historical) | Remediating past triple-posting | Petty cash/GL | Historical correction | Forward fix is live in code |
| W1-10 | Credit-note share of COS/WIP | W1 | Negative WIP release | Credit notes themselves |
| W2-9 | Supplier credit treatment | W2 | Supplier credit notes | Bills/payments |
| W3-2 / W3-8 | Salary-advance payout and recovery GL | W3/W10 | Advance journals | Payout (Payment) and recovery tracking |
| W5-7 | Cash-count variance GL | W5 | Variance journals | Count records |
| W6-1A | Overhead pool and basis | W6 | Fully loaded margin | Direct margin |
| W6-7 | Commitment release vs write-off | W6 | Write-off postings | Closure mechanism |
| **W7-24** | GL/WIP project dimension for labour | W7 / STAB-2 | Labour GL reclassification | Analytical labour costing (implemented) |
| **W7-25** | Standard vs actual payroll variance | W7/W10 | Variance reporting/accounting | Analytical labour costing |
| **W7-26** | Employer statutory cost in rates | W7/W10 (HR + Finance) | Rate composition policy | Rates as budgeted |
| Equity / opening balances (**not a register row**, Report 08) | Equity postings | Reporting/W9 | Balance Sheet, TB equity section | Everything else |

## 13. Technical Debt

**Finance redesign blockers**
1. **Frontend type-check baseline undecided** (§8). Blocks W7 closure under the current directive.
2. **Phase 2B unreleased.** 23 backend migrations and all Phase 2B code are unmerged. Every further workflow adds to one large-risk release. Report 41 did not treat this as closure debt; it is a delivery risk.

**Repository-wide technical debt**
- 256 pre-existing type errors, outside Finance except 1 in `FinanceNavigation.vue` and the 2 procurement errors.
- Build warnings that are real runtime defects: constant reassignment in `ReceiveStockModal.vue` (`quickCreate`) and `ResolveMaterialModal.vue` (`newMaterial`) will throw if those paths run.
- CI runs no tests and no type-check; `deploy.yml` only deploys.
- No backend tests at all for Production or Teams.

**Cleanup that can wait**
- Technical Labour residue in unused modules (overtime, compensatory leave, work orders, `TechnicalLabourController`, `TechnicalLabourPanel`, `useTechnicalLabour`). WNG has placed these out of scope.
- `teams_members` has no `employee_id` FK; identity is by name.
- An orphan view: `RequisitionStatement.vue`.
- An unreachable route: `/finance/spend` (SpendEntryView, NAV-1).
- A legacy redirect: `/finance/spend-vouchers`.
- Three different money helpers (truncate / float round / half-up).
- Float formatting left in `CostAccountService` (percentages, materials element breakdown).
- The old blended "Client Outstanding" label not yet removed from existing screens (W1-3 note).
- AP ageing lives in Procurement and reads cached bill balances.
- No Chart of Accounts screen (Report 07 gap 5).
- No Finance settings UI.

## 14. Backend Completeness

| Aspect | Status |
|---|---|
| Domain model | Complete for STAB, W1–W7 confirmed subsets; nothing for W8–W10 |
| API coverage | Complete for implemented workflows; reporting partial (P&L, TB, AR/AP ageing, financial position, portfolio margin); no BS/CF |
| Workflow state machines | Explicit for invoices, POs, amendments, vouchers, surrenders, labour; locked transitions |
| Authorization | Server-enforced permissions everywhere checked; real role holders undecided (ROLE-1..3) |
| Auditability | GovernanceAuditLog plus per-domain histories (amendments, corrections, reviews, returns, transfers) |
| Accounting integrity | One-economic-cost-once enforced (STAB-7, W7-12); journals via one poster; open accountant policies deliberately not invented |
| Analytical CostLine architecture | Mature: planned/committed/accrued/actual, `postsIndependently`, allocations, transfers, closure |
| GL integration | Live for invoices, supplier bills/payments, petty cash, payroll, vouchers; labour deliberately analytical; logistics none |
| Project Costing | Direct margin with completeness flags; labour included after W7; logistics/overhead not included |
| Error handling | Validation exceptions → 422; GL failures visible and retryable (STAB-4) |
| Idempotency | Source-key idempotency in Cost Collector; idempotent settlement and verification |
| Concurrency | Row locks plus unique constraints; true race tests for W7/W6-transfer only |
| Closure controls | Financial closure/reopen enforced across cost paths |
| Reporting APIs | Partial (§3, Financial Reporting) |

No percentage is given; the workflow count does not support an objective one.

## 15. Frontend Completeness

- **Functional frontend coverage: present** for every implemented workflow (STAB, W1–W7). Each has operable screens verified by unit tests (§21).
- **UX/UI quality: not a coherent product yet.** Finance screens were added workflow by workflow into the pre-existing rail. Project finance is split across two surfaces (client money in `EnquiryFinanceModal`, cost in `CostAccountPanel`). AP sits in Procurement. There is no settings UI and no Chart of Accounts screen. Terminology is inconsistent ("Portfolio budgets" vs portfolio margin; "Payment vouchers" vs `spend-vouchers`).
- **Full redesign: not started.**

> **Functional frontend implementation is not the same as full Finance UX redesign.**

## 16. Current Finance Information Architecture

The Finance rail (`src/modules/finance/navigation.ts`, routes in `src/router/finance.ts`, shell `FinanceShell.vue`):

| Section | Pages |
|---|---|
| Work queue | My finance queue (`/finance/work-queue`, the landing page) |
| Costs & budgets | Record Expense (`/finance/costs?tab=capture`); My submissions (`?tab=mine`); Cost verification (`/finance/costs/verification`); Project cost sheet (`?tab=account`, hosting `CostAccountPanel` + `ProjectLabourPanel` + W6 modals); Portfolio budgets (`/finance/costs/accounts`, with the margin column) |
| Payments & cash | Cash requisitions (`/finance/petty-cash/requisitions` + new/show/edit); Payment vouchers (`/finance/payment-vouchers`); Petty cash register (`/finance/petty-cash`); Payroll (`/finance/payroll-disbursement`) |
| Client billing | Billing & receivables (`/finance/project-receivables`, hosting `EnquiryFinanceModal`) |
| Controls & reports | Control centre (`/finance/setup`); Request forms; Money accounts; Bank matching (`/finance/reconciliation`); Tax review; Close month (`/finance/periods`); Account book (`/finance/ledger`); Reports (`/finance/reports`) |

- **Dashboards:** no Finance KPI dashboard. The landing page is the work queue.
- **Approval queues:** the work queue, cost verification, voucher approvals, and the direct-payment approval queue inside petty cash.
- **Reports:** a single `FinancialReportsView`; AP ageing is in Procurement.
- **Settings:** a readiness/control centre, request forms and money accounts. No finance-settings or Chart of Accounts screen.
- **Duplicated entry points:** "Record Expense" and the unreachable `/finance/spend` both aim at spend capture. Project cost is reachable from Finance but not embedded in the project workspace, even though the panel was built for both. AP is reachable via the work queue and Procurement only (NAV-2). Account summary vs trial balance (NAV-3).
- **Dead / obsolete:** `/finance/spend` (routed, not in the rail); `/finance/spend-vouchers` (redirect); `RequisitionStatement.vue` (referenced nowhere).

## 17. Frontend Redesign Readiness

### READY FOR UX DISCOVERY / INFORMATION ARCHITECTURE ONLY

- **Contracts are not frozen.** W7 changed contracts on 2026-09-27/28. W8 will change `cost_completeness`, portfolio and statement payloads.
- **W8–W10 are unimplemented**, and the redesign's core decisions are unmade: NAV-1..3, RPT-1..2, ROLE-1..2.
- **Discovery is safe now and already has inputs.** Report 07's proposed target structure and gap list, Report 08's reporting map, and today's working functional screens are enough to start user research and IA work without backend change.

## 18. Recommended Frontend Redesign Trigger

Full redesign should begin **when all three of these are true**:

1. **W8 is closed.** It is the last workflow that changes the Project Costing and portfolio contracts, which are the centre of any Project Finance workspace.
2. **NAV-1..3, RPT-1..2 and ROLE-1..2 are decided.** They define the navigation, the dashboard and role-based views.
3. **A backend contract freeze is declared** for W1–W8 endpoints, with Phase 2B released to production so the redesign builds on live behaviour.

W9 and W10 should be either decided or explicitly scoped out of the redesign's first release. Waiting for them indefinitely is not recommended: their UI surface is small or not yet defined.

## 19. Future Finance UX Scope

**FUTURE UX SCOPE — NOT IMPLEMENTATION.** Areas the system supports and the redesign must unify:
- Finance Home: the work queue plus KPI tiles (RPT-2)
- Accounts Receivable
- Accounts Payable, brought into Finance (NAV-2)
- Project Finance workspace: budget, costing, labour, closure, client money
- Petty Cash and Payment Vouchers
- Cash & Banking: reconciliation and bank position (RPT-1)
- Payroll Finance
- Journal/Ledger, including Chart of Accounts
- Period close and Tax
- Approvals
- Reporting
- Finance controls/settings, including thresholds UI and permission-holder visibility
- Logistics cost, **only after W8**

## 20. Project Finance Workspace Readiness

| Metric | Status | Source |
|---|---|---|
| Project value (approved quote/contract) | **AVAILABLE NOW** | `ClientFinancialPositionService` (`financial-position`) |
| Approved budget | **AVAILABLE NOW** | Planned CostLines / Cost Account |
| Invoiced | **AVAILABLE NOW** | Financial position |
| Collected | **AVAILABLE NOW** | Financial position |
| Receivable (invoice outstanding) | **AVAILABLE NOW** | Financial position |
| Actual direct cost | **AVAILABLE NOW** | Cost Account (materials, procurement, expenses) |
| Labour | **PARTIALLY AVAILABLE** | Built and tested; W7 not closed and not released |
| Logistics | **BLOCKED BY FUTURE WORKFLOW** (W8) | `cost_completeness.logistics = not_included` |
| Commitments | **AVAILABLE NOW** | `committed` nature |
| Variance | **AVAILABLE NOW** | Cost Account `remaining` (signed) |
| Margin | **PARTIALLY AVAILABLE** | Direct, provisional. Fully loaded is blocked by W6-1A (overhead) and W8 |
| Financial closure | **AVAILABLE NOW** | W6-5/6 |

The data is largely present, but it is served by **two separate endpoints and surfaces**: financial position (client money) and the cost account (cost). No single Project Finance payload exists. A proper Project Finance redesign is feasible after W8, subject to W6-1A for fully loaded margin.

## 21. Test Health (executed 2026-09-28)

**Backend** (DDEV, PHP 8.4, MariaDB 11.8):
- Full suite: **1,427 tests, 9,421 assertions, 0 failures, 0 errors, 0 skipped**.
- Finance-relevant subsets, from the same run: Cost Collector 185; Petty Cash 150; Journal/ledger 56; Payments 34; Payroll 26; Budget 28; W7 84 (400 assertions). All pass.
- W7-specific: `W7LabourCostTest` 79/341 and `W7LabourConcurrencyTest` 5/59, both passing.
- Report 36's "576 backend tests" was a subset (Cost Collector + Finance + permission registry), not the full suite.

**Frontend:**

| Check | Result |
|---|---|
| Finance / W7 tests | `wave7Labour.spec` 23 pass; other finance specs pass |
| Full unit suite | **27 files, 166 tests, all pass** |
| Type-check | **FAILS: 258 errors** (256 pre-existing at HEAD, 2 from procurement W2-era work, 0 from W7) |
| Production build | **Succeeds**: 1,891 modules; warnings classified in Report 41 §28 |

Build success is not type-check success.

## 22. Documentation Health — Items Requiring Reconciliation

1. **Report 36 §10** names components and routes that **do not exist** (`ProjectCostingView.vue` at `/finance/projects/:id/costing`, `PortfolioMarginView.vue` at `/finance/portfolio`, `ProjectFinancialClosurePanel.vue`). The real ones are `CostAccountsView`, `CostAccountPanel` and `ProjectFinancialClosureModal`. It also states they were "deployed to `master`", which is false. §9's "576 backend tests" is a subset, not the full suite.
2. **Report 33 §6** labels W5-4 "Multiple Currency Floats" and W5-6 "Top-Up Approval Segregation". The register defines W5-4 as a simplified request form and W5-6 as a single company float.
3. **The register's STAB-3 row** still says W2-4 has "no frontend UI yet built (Backend implemented / UI incomplete)", contradicting the W2-4 row (FULLY IMPLEMENTED, 2026-09-24).
4. **The register introduction** (lines 6–10: "No row in this register has been decided… every row defaults to AWAITING") is stale.
5. **Phase 1.5 statements** now stale: Report 02 ("nothing described here has been executed") and Report 04 ("~55 business decisions… none yet answered").
6. **Report 39** still carries "IMPLEMENTATION COMPLETE — READY FOR INDEPENDENT W7 CLOSURE GATE". The register records it as rejected, but the file itself is unmarked and could mislead an agent reading it alone.
7. **Report 41 §27** says no document establishes a type-check baseline. Report 31 documents a 256-error measurement (§8 here).
8. **WNG's W7-10 scope instruction** (work orders, overtime and Technical Labour are not in use; leave them out) is recorded only in Report 41, not in the register.
9. **Several reports use "deployed" or "FULLY IMPLEMENTED"** for work that was unreleased and, until 2026-09-28, uncommitted. The register's engineering rule measures implementation, not release; a release status column is absent.

## 23. Roadmap From Today

1. **A. Decide the frontend type-check baseline** (WNG/Engineering, §8).
2. **B. W7 re-closure pass** against that decision, expecting PASS given Report 41 §34.
3. **C. Release Phase 2B:** production DB backup, staging trial, merge both branches together. *This stage is added versus the proposed sequence:* releasing before W8 keeps migration risk bounded and gives the redesign live behaviour to build on.
4. **D. W8 decisions:** confirm W8-1..5, then an architecture reconciliation (Reports 34/37 pattern).
5. **E. W8 backend + functional frontend.**
6. **F. W8 closure gate.**
7. **G. Remaining decisions and small workflows:**
   - W9 and W10 decisions (brief first for W9).
   - ROLE-1..3, NAV-1..3, RPT-1..2.
   - In parallel throughout: accountant decisions (§12) and deferred items inside closed workflows.
8. **H. Backend contract freeze** for W1–W8 (W9/W10 in or explicitly out).
9. **I. Finance UX discovery / IA.** *May run in parallel from now* as research only (§17), and must be complete by H.
10. **J. Full Finance frontend redesign.**
11. **K. Final Finance integration/regression**, including the reporting completion that depends on the equity policy.
12. **L. Finance module closure.**

## 24. Three Different Things

| | Status |
|---|---|
| **A. Backend implementation** | Complete for STAB and the W1–W7 confirmed subsets; nothing for W8–W10; reporting partial |
| **B. Functional frontend** | Present for every implemented workflow, test-verified; not released |
| **C. Full Finance UX/UI redesign** | Not started; only a proposed target IA exists (Report 07) |

## 25. Management Summary

- **Where are we?** Seven of the ten planned finance workflows have been built (confirmed parts). Six are formally closed; the seventh (Labour) is built and tested but not yet signed off.
- **What is completed?** The critical integrity fixes, client billing, procurement-to-payment, expenses, payment vouchers, petty cash and project costing, each with working screens and passing tests.
- **What is open?** Labour (W7) sign-off, plus Logistics (W8), Assets (W9) and Payroll (W10), which await business decisions. Several accounting-policy questions await the accountant.
- **What comes next?** A decision on the code-quality standard that is holding up Labour's sign-off. Then releasing the finished work safely, then Logistics.
- **What is blocking us?**
  - One engineering standard: the frontend "type-check". Its roughly 256 errors predate the finance work. Earlier workflows were closed without it; W7 is the first to be held to it.
  - Separately, **none of the finished work is live yet.**
- **When does the full frontend redesign begin?** After Logistics (W8) is closed, the navigation, report and role decisions are made, and the backend is frozen and released. UX research can start now.
- **What must be true for Finance to be complete?**
  - Every confirmed workflow closed and released, including W8, and W9/W10 decided or scoped out.
  - The accountant policies decided, or explicitly accepted as out of scope.
  - Role holders assigned to the new controls.
  - Reporting completed to the agreed scope.
  - The redesigned Finance frontend delivered and regression-verified.

## 26. Status Dashboard

| Area | Decisions | Backend | Functional Frontend | Verification | Full UX Redesign | Overall |
|---|---|---|---|---|---|---|
| Stabilization | Confirmed (STAB-2 open) | Done | Done | Closed | Not started | CLOSED (STAB-2 blocked by accountant) |
| W1 Receivables | Confirmed (W1-10 open) | Done | Done | Closed | Not started | CLOSED |
| W2 Procurement/AP | Confirmed (W2-7..10 open) | Done | Done | Closed | Not started | CLOSED |
| W3 Expenses | Confirmed | Done | Done | Closed | Not started | CLOSED |
| W4 Payment Vouchers | Confirmed (W4-3/4 open) | Done | Done | Closed | Not started | CLOSED |
| W5 Petty Cash | Confirmed (values open) | Done | Done | Closed | Not started | CLOSED |
| W6 Project Costing | Confirmed subset | Done | Done | Closed (Report 36 doc defects) | Not started | CLOSED |
| W7 Labour | Confirmed (W7-24..26 open) | Done | Done | Report 41 FAIL (type-check baseline only) | Not started | CLOSURE FAILED — REMEDIATION REQUIRED |
| W8 Logistics | Pending | None | None | — | Not started | DECISIONS PENDING |
| W9 Assets | Pending | None | None | — | Not started | DECISIONS PENDING |
| W10 Payroll | Pending | STAB-6 only | Existing screens | — | Not started | DECISIONS PENDING |
| Reporting | Pending + accountant | Partial | Partial | — | Not started | BLOCKED BY FINANCE/ACCOUNTANT DECISION |
| Roles / Navigation / Config | Pending | Mechanisms exist | Current rail; no settings UI | — | Not started | BLOCKED BY WNG DECISION |
| Production release | — | Unreleased | Unreleased | — | — | NOT STARTED |

## 27. Immediate Next Action

**Resolve the W7 closure baseline issue:** WNG/Engineering decides whether the documented 256-error type-check set (Report 31 / committed HEAD) is the accepted baseline with a zero-new-errors rule, or whether a clean repository type-check is required.

---

## CURRENT FINANCE REDESIGN POSITION

**Current Phase:** Phase 2B — confirmed-workflow implementation. The work is committed and pushed on `finance/critical-stabilization-fixes`; none of it is released to production.
**Current Workflow:** W7 Labour Cost. Functionally complete; Report 41 re-closure = FAIL on one blocker.
**Last Closed Workflow:** W6 Project Costing, confirmed subset (Report 36, 2026-09-24).
**Current Blocker:** The frontend repository type-check fails (258 errors: 256 pre-existing, 2 procurement, **0 from W7**), with no formally accepted baseline. Earlier closures proceeded without one.
**Next Workflow:** W8 Logistics / Fleet Cost. Decision brief only (Report 26); W8-1..5 awaiting WNG.
**Full Frontend Redesign Status:** Not started. Functional frontends exist for STAB and W1–W7; a proposed IA exists (Report 07). Ready for UX discovery/IA only.
**Frontend Redesign Trigger:** W8 closed, NAV-1..3 / RPT-1..2 / ROLE-1..2 decided, and a backend contract freeze declared with Phase 2B released to production.
**Immediate Next Action:** Resolve the W7 closure baseline issue (a WNG/Engineering decision on the type-check baseline).
