# 08 — Reporting Gap Analysis

Phase 1.5, Part F. Source: `docs/finance-redesign/current-state/09_REPORT_AND_DASHBOARD_AUDIT.md`
and `05_CURRENT_WORKFLOWS.md` (Project Finance). Guiding principle carried forward from the audit
brief: **a report should not be built from unreliable or incomplete data merely to fill a
dashboard.** Several gaps below are intentionally left as gaps until their dependency is resolved,
rather than proposed as a "quick version" that would show a confident-looking but incomplete number.

---

## Classification per report

| Report | Status | Why |
|---|---|---|
| **Profit & Loss** | **EXISTS BUT INCOMPLETE** | Real, working, and self-declared non-statutory by its own design (no depreciation, opening balances, or equity postings exist yet). Revenue only reflects invoices that actually posted to the ledger — if Critical Risk C1 (chart-of-accounts mapping) is currently causing invoice postings to fail, P&L revenue would understate reality without any visible warning. |
| **Trial Balance** | **EXISTS BUT INCOMPLETE** | Same non-statutory caveat as P&L. The Equity section is structurally guaranteed to be empty (nothing posts to it yet) and currently disappears from the report entirely rather than showing as an intentional placeholder. |
| **Receivables (AR) Ageing** | **EXISTS BUT INCOMPLETE** | Real and working, but is one of three different "what does the client owe" calculations in the product that can disagree (see Workflow 1) — its reliability as *the* authoritative figure is a labelling/reconciliation question, not a missing-report problem. |
| **Payables (AP) Ageing** | **EXISTS BUT INCOMPLETE** | Real and working, but lives in a different module/route than the other Finance reports, reads the bill's own cached balance rather than the ledger, and may not reflect legacy bills that predate the current supplier-payment controls. |
| **Bank / Cash Position** | **MISSING** (as a standing figure) — **buildable now, pending a WNG decision it's wanted** | The underlying calculation (a ledger-derived balance per bank/cash account) already exists and is correct — it just only runs inside a formal bank reconciliation, never as a day-to-day tile. This is not blocked by missing data; it is blocked only by confirming WNG wants it (Decision Register). |
| **Balance Sheet** | **MISSING** — **dependent on accounting policy AND missing source data** | Cannot be produced today because nothing posts to Equity (no opening balances, no share capital, no retained-earnings closing entry) — a genuine accounting-policy decision, not a technical gap. It would also need asset scope decided (Workflow 9) before assets/depreciation could appear correctly. |
| **Cash Flow Statement** | **MISSING** — **dependent on missing source data** | No cash-flow report exists in any form. Building an accurate one depends on first closing the payroll money-movement gap (Critical Risk C7) — without it, a cash-flow statement would simply omit WNG's payroll outflow. It would also need a decision on whether to build it from the four separate money-movement tables (Payment/BillPayment/ClientReceipt/CashMovement) as they exist today, or after any future consolidation of those tables. |
| **Project Profitability (single project)** | **EXISTS BUT INCOMPLETE** | The calculation itself is correctly built from real posted transactions — a genuine strength — but is incomplete because labour and logistics/transport actuals are not yet attributed to projects (Workflows 7 and 8). The number shown is real, just not the whole cost picture. |
| **Project Profitability (portfolio-wide, "every project at once")** | **MISSING** | The per-project calculation exists; it has never been extended into a list view. No new source data is required to build this at the current level of completeness — it's an extension of an existing, working calculation, not a new one (see `01_FOUNDATION_TO_PRESERVE.md` §2). |
| **Budget vs Actual** | **EXISTS BUT INCOMPLETE** | The closest existing screen (Portfolio Budgets) shows planned/committed/actual cost figures correctly, but no revenue or margin column at all — it answers "did we spend what we planned to spend" but not "are we making money on this job." Extending it to include revenue needs no new source data; extending it to a *true* actual-cost figure still depends on the labour/logistics decision (Workflow 7/8). |

---

## Summary of dependencies, so Phase 2B knows what unblocks what

| Blocking factor | Reports affected |
|---|---|
| **Chart-of-accounts mapping confirmed against the live database (Critical Risk C1)** | P&L, Trial Balance, AR Ageing (indirectly, via the "does an invoice actually post" question) |
| **Equity/opening-balance accounting policy decided** | Balance Sheet, and Trial Balance's Equity section |
| **Labour cost attribution method decided (Workflow 7)** | Project Profitability (both single-project and portfolio), Budget vs Actual |
| **Logistics/transport cost attribution decided (Workflow 8)** | Project Profitability, Budget vs Actual |
| **Payroll money-movement fix shipped (Critical Risk C7)** | Cash Flow Statement, Bank/Cash Position (partially — payroll payments would otherwise be invisible to it) |
| **Asset accounting scope decided (Workflow 9)** | Balance Sheet |
| **No blocker — buildable now once confirmed wanted** | Bank/Cash Position (standing figure), Portfolio-wide Project Profitability, Budget vs Actual's revenue/margin columns |

## What this document recommends against

Per the guiding principle above, this document recommends **against** building any of the following
until their listed dependency is resolved, even though a partial version could technically be
assembled sooner:

- A Balance Sheet that omits Equity but presents itself as complete.
- A Cash Flow Statement that omits payroll's outflow silently.
- A Project Profitability figure (single-project or portfolio) that includes labour/logistics as a
  guessed number without clearly labelling it as an estimate, if WNG chooses an estimation-based
  option in Workflow 7.

Each of these would create the exact failure mode Phase 1 already found once (a number that "looks
precise and is fiction") — better to show a smaller, honestly-labelled report than a complete-looking
one that quietly omits something material.

---

*Full current-state detail for every report above is in
`docs/finance-redesign/current-state/09_REPORT_AND_DASHBOARD_AUDIT.md`. Dependencies are tracked as
decision items in `03_WNG_FINANCE_DECISION_REGISTER.md`.*
