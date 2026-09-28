# 07 — Finance Navigation Requirements

Phase 1.5, Part E. **No UI change is made or scheduled by this document.** It evaluates a proposed
future Finance information architecture against every screen documented in
`docs/finance-redesign/current-state/03_CURRENT_NAVIGATION_MAP.md`, and classifies each one. Where
the proposed architecture calls for something that does not exist today at all, that is called out
separately at the end.

## Proposed target structure (as given for evaluation)

- **Finance Home** — attention/work requiring action
- **Sales & Receivables** — client invoices, receipts, credit notes, outstanding receivables
- **Purchases & Payables** — supplier bills, supplier payments, payables ageing
- **Expenses & Project Costs** — expense capture, verification, project cost sheets, budget vs
  actual
- **Payments & Cash** — payment vouchers, payments, petty cash, bank/cash accounts, reconciliation
- **Accounting** — chart of accounts, general ledger, accounting periods, tax, journal history
- **Reports** — P&L, trial balance, AR ageing, AP ageing, project profitability, budget vs actual,
  other future statements

---

## Classification of every existing screen

Classifications: **KEEP IN SAME LOCATION** / **MOVE** / **RENAME** / **MERGE** / **REMOVE** /
**NEW SCREEN MAY BE REQUIRED**.

| Existing screen | Current location | Target section | Classification | Notes |
|---|---|---|---|---|
| `FinanceWorkQueueView.vue` ("My finance queue") | Work queue | Finance Home | **RENAME** (section) | The screen's content already matches "attention/work requiring action" — only the section label needs to change. |
| `CostCollectorIndex.vue` (tab=capture, "Record Expense") | Costs & budgets | Expenses & Project Costs | **RENAME** (section) | Screen itself unchanged; the whole "Costs & budgets" section maps almost 1:1 onto "Expenses & Project Costs." |
| `CostCollectorIndex.vue` (tab=mine, "My submissions") | Costs & budgets | Expenses & Project Costs | **KEEP IN SAME LOCATION** (under renamed section) | |
| `CostVerificationView.vue` ("Cost verification") | Costs & budgets | Expenses & Project Costs | **KEEP IN SAME LOCATION** (under renamed section) | |
| `CostCollectorIndex.vue` (tab=account, "Project cost sheet") | Costs & budgets | Expenses & Project Costs | **KEEP IN SAME LOCATION** (under renamed section) | |
| `CostAccountsView.vue` ("Portfolio budgets") | Costs & budgets | Expenses & Project Costs / Reports | **KEEP IN SAME LOCATION** + feeds a **NEW** formal report | Already the closest thing to "Budget vs Actual." Needs a billing/margin column added (a content fix already tracked, not a nav change) before it can also serve the Reports section's "Budget vs actual" item. |
| `RequisitionIndex.vue` ("Cash requisitions") | Payments & cash | Payments & Cash | **KEEP IN SAME LOCATION** | Target section name is unchanged for this area. |
| `PaymentVouchersView.vue` ("Payment vouchers") | Payments & cash | Payments & Cash | **KEEP IN SAME LOCATION** | (Its filing under a `cost-collector/` code folder is a code-organization note, not a navigation one.) |
| `PettyCashIndex.vue` ("Petty cash register") | Payments & cash | Payments & Cash | **KEEP IN SAME LOCATION** | |
| `PayrollDisbursement.vue` ("Payroll") | Payments & cash | Payments & Cash (pending confirmation) | **KEEP IN SAME LOCATION — REQUIRES WNG CONFIRMATION** | Workflow 10 asks whether Finance should keep only this one disbursement screen, or gain visibility into the fuller HR payroll engine. If WNG decides Payroll should not be a Finance-owned screen at all, this becomes **REMOVE** (link out to HR instead). |
| `ProjectReceivablesIndex.vue` ("Billing & receivables") | Client billing | Sales & Receivables | **RENAME** (section) + **NEW SCREEN MAY BE REQUIRED** | Today this is one page with no internal tabs covering invoices, receipts, and credit notes together. The target structure lists these as distinct items — splitting them into focused sub-screens is optional future work, not required immediately. |
| `FinanceReadinessView.vue` ("Control centre") | Controls & reports | Accounting | **MOVE** | Fits best next to Chart of Accounts as overall Finance configuration/readiness, rather than its own top-level section. |
| `RequisitionTypesView.vue` ("Request forms") | Controls & reports | Payments & Cash (Setup) | **MOVE** | Configures petty-cash/expense requisition templates specifically — fits better beside Petty Cash than under general Accounting. |
| `PayingAccountsView.vue` ("Money accounts") | Controls & reports | Payments & Cash | **MOVE** | Target structure explicitly lists "bank/cash accounts" under Payments & Cash. |
| `ReconciliationView.vue` ("Bank matching") | Controls & reports | Payments & Cash | **MOVE** | Target structure explicitly lists "reconciliation" under Payments & Cash. |
| `TaxSchedulesView.vue` ("Tax review") | Controls & reports | Accounting | **RENAME** (section) | |
| `AccountingPeriodsView.vue` ("Close month") | Controls & reports | Accounting | **RENAME** (section + label) | Already carries three different labels across route meta, nav, and page title today (per Phase 1 audit) — standardizing on "Accounting periods" would resolve both the section move and the labelling inconsistency in one pass. |
| `GeneralLedgerView.vue` ("Account book") | Controls & reports | Accounting | **RENAME** (section + label to "General Ledger") + **MERGE candidate** | Its "Account summary" tab duplicates the Reports page's "Trial balance" tab under a different label — flagged for a merge decision, not resolved here (Workflow/Decision Register item). |
| `FinancialReportsView.vue` ("Reports") | Controls & reports | Reports | **KEEP IN SAME LOCATION** + **EXTEND** | Already covers P&L/Trial Balance/AR/AP ageing. Needs two additions the target structure calls for (see gaps below). |
| `/finance/spend` (`SpendEntryView.vue`) | Orphaned (unreachable) | Finance Home (proposed) | **MOVE (reconnect) or REMOVE — pending confirmation** | Its underlying triage ("record a cost / need cash in advance / order from a supplier") is genuinely useful and would fit naturally as a quick-action launcher on a redesigned Finance Home. Reconnecting it there is one option; formally removing it is the other, if WNG confirms the triage step isn't wanted. Either is preferable to leaving it as dead, unreachable code. |
| `/finance/spend-vouchers` (legacy redirect) | — | — | **REMOVE** | Safe to remove once confirmed no external bookmarks/deep links depend on the old URL — a technical cleanup, not a business decision. |
| `FinanceContextCard.vue` (Universal Task) | — | — | **REMOVE** (pending confirmation) | Zero consumers found in Phase 1; carried forward unchanged. |
| `LabourClassificationController` (backend, no frontend consumer) | — | — | **REMOVE** (pending confirmation) | Zero consumers found in Phase 1; carried forward unchanged. |

---

## Gaps: what the proposed structure calls for that does not exist as any screen today

These are not reclassifications of an existing screen — they are things the target information
architecture names that Phase 1 confirmed have **no current equivalent anywhere in the product**:

1. **A dedicated "Purchases & Payables" presence inside Finance's own navigation does not exist at
   all today.** Supplier bills, supplier payments, and payables ageing are all real, working
   screens — but they live entirely inside the Procurement/Stores module's own navigation. A
   Finance user today can only reach them via the Work Queue (when a bill happens to need Finance's
   attention) or via the now-orphaned `SpendEntryView`'s "Order from supplier" link. **NEW SCREEN
   (or, more precisely, new navigation entry points) MAY BE REQUIRED**: this is the single largest
   structural gap between the current navigation and the target structure.
2. **Client invoices, receipts, and credit notes as separate, focused screens do not exist** — all
   three currently live inside one page (`ProjectReceivablesIndex.vue`) and a supporting modal.
   Splitting them is optional, not urgent, since the underlying data and actions are all present
   today; it is a usability improvement, not a missing capability.
3. **A Project Profitability report does not exist anywhere in the system.** Margin can currently
   only be seen one project at a time, inside the Cost Accounts drill-down — there is no report
   that lists every project's revenue, cost, and margin side by side. **NEW SCREEN MAY BE
   REQUIRED.**
4. **A formal "Budget vs Actual" report (as opposed to the existing working Portfolio Budgets
   screen) does not exist.** The existing screen is closest, but it currently shows cost-side
   figures only, with no billing or margin column. **NEW SCREEN MAY BE REQUIRED**, or the existing
   screen could be extended and reframed as this report — a design choice for Phase 2B, not decided
   here.
5. **A Chart of Accounts management/browsing screen does not exist in the frontend at all.** The
   chart is fully configured on the backend (with the mapping/translation gaps documented in the
   stabilization plan), but there is no UI for a Finance user to view, search, or manage GL
   accounts. Given that resolving Critical Risk C1 will require someone to confirm and maintain the
   account mapping, **a basic Chart of Accounts screen is likely to become necessary** even before
   the rest of this navigation redesign proceeds.
6. **Bank/Cash Position as a standing, always-visible figure does not exist** — the system only
   computes a ledger-derived balance inside a formal reconciliation statement, never as a
   day-to-day tile. This is a Reports-adjacent gap, covered in more detail in
   `08_REPORTING_GAP_ANALYSIS.md`.

---

## What this document does not do

It does not choose section names, does not reorder the navigation, does not merge or split any
screen, and does not decide whether `/finance/spend` should be reconnected or removed. All of that
is Phase 2B design work, to begin only once the Decision Register items this document feeds are
resolved.

---

*Full current-state detail for every screen above is in
`docs/finance-redesign/current-state/03_CURRENT_NAVIGATION_MAP.md`. Open questions are consolidated
in `03_WNG_FINANCE_DECISION_REGISTER.md`.*
