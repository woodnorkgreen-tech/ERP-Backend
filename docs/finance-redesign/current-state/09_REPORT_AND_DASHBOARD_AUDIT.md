# 09 — Financial Reporting & Dashboard Audit

Part of the WNG ERP Finance & Accounts Phase 1 audit, dated 2026-09-22. Covers audit sections 17
(Financial Reporting) and 18 (Finance Dashboard).

---

## SECTION 17 — Financial Reporting Audit

### 17.1 Inventory of what actually exists

| Report | Data Source | Calculation | Filters | Export | Reliability Concern |
|---|---|---|---|---|---|
| **Profit & Loss** | `journal_lines`⋈`journal_entries`⋈`chart_of_accounts`, category IN (revenue, expense) — `ProfitAndLossService::summary()` | Revenue/expense signed by `normal_balance`; `net_profit` summed independently from `category` so it can't drift from the section breakdown | `from`, `to` (required) | CSV (streamed) + JSON, one server call | Self-declared non-statutory: no depreciation, opening balances, or equity. Revenue only reflects invoices that actually posted — see 17.3 |
| **Trial Balance** ("Account summary") | Same 3-table join, all categories — `JournalEntryController::trialBalance()` | `SUM(debit)`, `SUM(credit)` per account; `is_balanced` via `bccomp` | `from`, `to` (optional) | No CSV on this endpoint | Same non-statutory caveat. Equity silently vanishes when it has no postings, which today is always — see 17.2 |
| **Receivables Ageing (AR)** | `project_invoices`, not the ledger | Per-invoice `balance = net_total − verified_paid`, bucketed by days overdue | `as_of`, `bucket` | CSV + JSON | Counts every issued invoice regardless of whether it ever posted to the GL — see 17.3 |
| **Payables Ageing (AP)** | `bills` table (ProcurementStores), not the ledger | Reads `Bill.balance`/`Bill.status`, deliberately reused verbatim from the pending-bills screen | `as_of`, `bucket` | CSV + JSON | Lives in a different module/route than the other two Finance reports; same structural risk as AR |
| **General Ledger / Journal Entries** | `journal_entries`⋈`journal_lines` | Paginated list, filterable | source/status/from/to/account/project/search | List only (separate export endpoint) | `source` filter exposes only 2 of 6+ real posting sources — see `06_ACCOUNTING_POSTING_ANALYSIS.md` |
| **Account Statement (Account book)** | `journal_lines` for one account | Opening + running + closing balance | `from`, `to`, pagination | None | Running-balance math re-seeds correctly across pages — none found |
| **Journal Export (external accounting)** | `LedgerExportService::documentJournals()` | Document-batched journals | `from`, `to` (required) | CSV/JSON, one service call | Built explicitly so "the file and the screen can never disagree" |
| **VAT/WHT schedules** (5 views) | `TaxScheduleController`/`TaxScheduleService` | Tax-treatment-driven schedules | Period-based | CSV + JSON | Not independently re-cross-footed against the Trial Balance's own VAT accounts in this pass — flagged as a follow-up, not confirmed broken |
| **Bank Reconciliation** | `ReconciliationController` — imported statement vs `journal_lines`/`cash_movements` | Match/auto-match/candidates workflow, not a static report | Per statement | Statement import only | Out of this pass's depth; matches move into the ledger, not a side table |
| **Petty Cash reports** (Summary/Detailed/By-Project/Trend/Fund Custody) | `PettyCashReportService`, `FundCustodyService` | Aggregates over disbursements/top-ups; Fund Custody self-reconciles and surfaces a `reconciliation_difference` | Date range | CSV per top-up and overall | `generateProjectReport()` sums petty-cash disbursements per project directly — a candidate to disagree with the CostCollector-based per-project total if a disbursement's cost-line linkage is incomplete (not confirmed broken — 17.5) |
| **Cost Accounts / Portfolio budgets** (closest to Budget vs Actual) | `CostAccountController` | Planned/committed/actual per allocation line | Per project | Not checked | **No report joins this to revenue** — no Project Profitability report exists anywhere; margin can only be reconstructed by hand from two separate screens |

**FACT — reports that do NOT exist:** Balance Sheet, Cash Flow Statement, a consolidated Project
Profitability report, a formal Budget-vs-Actual report (only the per-cost-account planned/
committed/actual view exists), a Bank/Cash position report distinct from reconciliation.
`LedgerCoverage::excludes()` self-documents most of these as intentional/staged, not accidental.

### 17.2 CONFIRMED — Trial Balance's Equity section vanishes silently

**ISSUE — RISK: MEDIUM.** The backend inner-joins accounts to posted lines; the frontend then
`filter()`s out any category with zero rows entirely, with no placeholder. Since nothing posts to
Equity yet (by design, per `LedgerCoverage::excludes()`), the Equity section is guaranteed empty and
therefore guaranteed to disappear every time the report runs — a reader unaware of this cannot tell
"excluded by design" from "this section doesn't exist." The flat, ungrouped "Account summary" tab
on the General Ledger page does not have this problem, since it doesn't pre-declare categories —
the bug is specific to the grouped presentation.

**RECOMMENDATION.** Render every category with a "No postings this period" placeholder instead of
filtering it out, at minimum for Equity. **CLASSIFICATION: KEEP&IMPROVE.**

### 17.3 CONFIRMED (new finding) — Three different "what does the client still owe" numbers

**ISSUE — RISK: HIGH.**
1. **Receivables Ageing** — sums `net_total − verified_paid` per issued invoice.
2. **Client invoices & receivables workspace "Outstanding"** — `remaining = quote_amount −
   total_paid`, where `total_paid` includes every verified receipt against the whole enquiry,
   including deposits not yet allocated to any invoice.
3. **The ledger's Accounts Receivable account** — reflects only invoices whose posting actually
   ran, gated on the period being open and on the **Client Deposits (2200)**/**Output VAT Payable
   (2110)** accounts existing in WNG's live chart. The posting service's own docblock states these
   two accounts "have no counterpart in WNG's live chart at the time of writing" — and `postInvoice
   Allocation()` explicitly refuses to touch an invoice that never posted, noting "those invoices
   are settled outside the ledger, as they always were."

These three numbers answer three legitimately different business questions, but two of the three UI
surfaces both simply label their figure "Outstanding" with no qualifier. If 2200/2110 are genuinely
still missing from WNG's live chart, **no invoice issued today posts to the ledger at all** (caught
gracefully as a 422, not a 500 — but functionally the GL's AR balance would sit at zero or stale
indefinitely while the ageing report and payment-progress screen both show real, growing numbers).
This directly corroborates the CRITICAL chart-of-accounts finding in `04_CURRENT_DATA_MODEL.md`.

**RECOMMENDATION.** (a) confirm with Finance whether 2200/2110 exist in the live chart today —
**REQUIRES WNG CONFIRMATION**, not inferable from code; (b) relabel the two "Outstanding" figures to
name their basis explicitly; (c) treat the ledger AR balance as informational-only until (a) is
resolved. **CLASSIFICATION: REDESIGN** (labelling/legibility problem — each individual calculation
is internally correct for what it measures).

### 17.4 CONFIRMED — Journal source filter exposes 2 of 6+ real posting sources

See `06_ACCOUNTING_POSTING_ANALYSIS.md` Part A.5 for the full evidence table. **CLASSIFICATION:
KEEP&IMPROVE.**

### 17.5 Lower-confidence leads (flagged, not confirmed)

- **ASSUMPTION.** `PettyCashReportService::generateProjectReport()` sums petty-cash disbursements
  per project directly from petty-cash tables — may or may not tie to CostCollector's per-project
  actual cost total. Recommend a follow-up reconciliation check.
- **ASSUMPTION.** VAT Input/Output schedules were not cross-footed against the Trial Balance's own
  VAT account balances in this pass; both should tie out by construction but were not independently
  re-verified.

---

## SECTION 18 — Finance Dashboard Audit

### 18.1 There is no company-wide or Finance-specific dashboard with financial KPI cards

**FACT.** Checked the top-level `Dashboard.vue` (post-login landing page),
`ProjectsDashboardService::kpis()` (13 KPIs, all operational counts), and
`ClientServiceDashboardService::getDashboardStats()` (client/lead counts) — **none contains a
single financial figure.** There is no place in the product today where a manager sees "revenue
this month," "cash position," or "total outstanding" as a glanceable card; those numbers exist only
inside Finance's own report screens (Section 17), which require navigating to Finance → Controls &
reports and running a query.

**ISSUE — RISK: MEDIUM.** A genuine gap against the audit brief's expectation of dashboard KPIs —
there is very little dashboard surface to audit because it largely doesn't exist yet outside
Finance's own module. **REQUIRES WNG CONFIRMATION:** is a company-wide financial KPI dashboard
actually wanted, or is today's model (KPIs live inside each module) the intended design?

### 18.2 The one genuine Finance dashboard: Fund Custody

`FundCustodyDashboard.vue`, backed by `FundCustodyService::overview()`, is a properly built KPI
dashboard:

| Dashboard KPI | Definition | Calculation | Problem |
|---|---|---|---|
| All funds received | `funds_received_all_time` | Sum of petty-cash top-ups, all-time | None found |
| All funds consumed | `funds_consumed_all_time` | Payments + transaction costs | None found |
| Available by top-up | `funds_remaining` | Traceable remaining float per batch | None found |
| Spendable balance | `spendable_after_commitments` | Float less approved-not-yet-paid commitments | Correctly flagged red when negative |
| Reconciliation difference | `reconciliation_difference` | `PettyCashBalance.current_balance` vs sum of top-up-batch balances | The dashboard surfaces this as an exception banner rather than hiding it — good practice, but also live evidence that petty cash's "cash balance" is tracked in **two independently-maintained places** |
| Estimated cash runway | `estimated_runway_days` | Current float ÷ period daily consumption | None found |

**CLASSIFICATION: KEEP** — well-designed and self-audits its own consistency, which none of the
other reports in this codebase do. **REQUIRES FURTHER VERIFICATION:** whether
`PettyCashBalance.current_balance` is ever compared against the **GL's** own petty-cash cash
account, as opposed to only the batch/top-up ledger — not confirmed broken, flagged as a targeted
follow-up (also see the CRITICAL disbursement-posting risk in `06_ACCOUNTING_POSTING_ANALYSIS.md`
Part B.3).

### 18.3 No hard-coded figures or date-range bugs found in the screens reviewed

**FACT.** P&L/Trial Balance default to the **prior calendar month**, deliberately, to avoid showing
an obviously incomplete current month — a documented choice, not a bug. No hard-coded numeric
placeholders were found in the components read for this pass.

---

## Questions for WNG

**Management**
1. Is a single company-wide financial KPI dashboard (revenue, cash, outstanding, at a glance)
   wanted at all, or is today's per-module model acceptable?

**Finance/Accountant**
2. Do chart-of-accounts codes `2200` (Client Deposits) and `2110` (Output VAT Payable) exist and
   are they postable in WNG's live chart today? This single fact determines whether every invoice
   issued right now is actually reaching the ledger or silently failing at issuance (17.3).
3. When you read "Outstanding" on the Financial Reports (AR Ageing) screen versus "Outstanding" on
   the Client Invoices & Receivables screen, which do you currently treat as authoritative?
4. For Payables Ageing: are there supplier bills predating the Purchase-to-Pay gate that you know
   are not fully reflected in the ledger's AP balance, even though they show correctly in the
   Payables Ageing report (which reads `Bill.balance` directly, not the ledger)?

**SOP/Process**
5. How often is the Fund Custody dashboard's self-reported "reconciliation difference" actually
   investigated when it appears?

**Technical**
6. Is there an existing or planned reconciliation job that cross-foots the VAT schedules against the
   Trial Balance's own VAT account balances?
7. Should `PettyCashReportService::generateProjectReport()`'s per-project total be replaced by a
   read from the unified Cost Collector ledger, if it isn't already sourced from there?

---

*Consolidated risk ratings appear in `10_FINANCE_RISK_REGISTER.md`.*
