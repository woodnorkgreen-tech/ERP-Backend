# 34 — Phase 2B Workflow 6 (Project Costing) Decision & Architecture Report

**Document Reference:** `docs/finance-redesign/phase-2/34_PHASE_2B_W6_PROJECT_COSTING_DECISION_ARCHITECTURE.md`  
**Date:** 2026-09-24  
**Author:** WNG ERP Engineering & Finance Architecture Pair  
**Phase:** Phase 2B — Workflow 6: Project Costing (Decision Reconciliation & Confirmed-Subset Specification)  
**Status:** **AUTHORITATIVE ARCHITECTURE & GAP SPECIFICATION — W6 CONFIRMED SUBSET READY FOR IMPLEMENTATION**

---

## 1. Executive Summary

This reconciled architecture and decision report establishes the authoritative baseline for **Workflow 6 (Project Costing)** within WNG ERP Finance Phase 2B. Following the completion of Wave 3 (`32_PHASE_2B_WAVE_3_CLOSURE_REPORT.md`) and the Backend + Frontend Alignment Pass (`33_PHASE_2B_BACKEND_FRONTEND_ALIGNMENT_REPORT.md`), this document reconciles technical feasibility against the authoritative business decision status in `03_WNG_FINANCE_DECISION_REGISTER.md`.

### Core Governance & Architectural Findings:
1. **Decision Register Primacy:** In accordance with Phase 2B governance rules, where technical proposals or design options differ from `03_WNG_FINANCE_DECISION_REGISTER.md`, the **Decision Register decision status wins unconditionally**. Engineering does not convert open business policy choices into technical defaults.
2. **Preservation of Sound Core Foundations:** The verified, production-hardened core costing engine is preserved intact: `CostCollectorService`, `CostLine` repository, `StoresCostProducer`, `ProcurementCostProducer`, Petty Cash costing (post STAB-7), `PaymentSettlementService`, `JournalPostingService`, append-only cost history, Direct Project Margin, and the "one-economic-cost-once" invariant.
3. **Explicit Labour & Logistics Completeness Presentation:** An unavailable project cost allocation is **not economically equivalent to zero cost**. Project Costing must never represent missing labour or logistics as `0.00`. Instead, unintegrated categories are explicitly returned and displayed as **Not Included** with an accompanying status and reason.
4. **Direct Project Margin Caveat:** Direct Project Margin is retained using verified direct costs (materials, supplier bills, direct expenses). However, because labour (W7) and automated logistics (W8) are not yet integrated, the UI and reporting must visibly communicate this partial direct completeness state. It must **never** be mislabelled as "True Profit", "Final Profit", or "Fully Loaded Margin".
5. **Overhead Allocation (W6-1A) Remains Open:** Overhead allocation methodology remains **AWAITING FINANCE/ACCOUNTANT CONFIRMATION**. No overhead driver (revenue %, direct cost %, labour hours) is implemented. Fully Loaded Margin remains flagged as `fully_loaded_available = false`.
6. **Implementation Boundary:** Only the confirmed subset of W6 requirements is implementation-ready. All open policy items (Financial Closure blocker rules, Late-Cost reopen authority, true accounting write-offs, WIP capitalisation) remain visibly open and unforced.

---

## 2. Current Project Costing Architecture (What Works Today)

The WNG ERP project costing system operates through a decoupled two-tier architecture:
1. **The Analytical Subledger (`CostCollector` / `cost_lines`):** Captures multi-nature operational and financial events (`planned`, `committed`, `accrued`, `actual`) tagged with project enquiry ID, budget category, element, material catalogue ID, and origin transaction metadata.
2. **The General Ledger (`journal_entries` / `journal_lines`):** Records double-entry financial impacts using official chart-of-accounts codes (1xxx, 2xxx, 4xxx, 5xxx).

```
+-----------------------------------------------------------------------------------+
|                            OPERATIONAL SOURCES                                    |
|   +-------------------+  +-------------------+  +-------------------------------+ |
|   | Stores Issues     |  | Purchase Orders   |  | Petty Cash Surrender Items    | |
|   | & Returns         |  | & Supplier Bills  |  | & Direct Disbursements        | |
|   +---------+---------+  +---------+---------+  +---------------+---------------+ |
+-------------|----------------------|----------------------------|-----------------+
              |                      |                            |
              v                      v                            v
+-----------------------------------------------------------------------------------+
|                        COST PRODUCER LAYER (Domain Services)                      |
|       StoresCostProducer    ProcurementCostProducer     PettyCashCostProducer     |
+------------------------------------+----------------------------------------------+
                                     |
                                     v
+-----------------------------------------------------------------------------------+
|                       COST COLLECTOR SERVICE (Ingestion Hub)                      |
|                                                                                   |
|  * Validates Project ID, Budget Category, Nature, Amounts, Tax                    |
|  * Dispatches to CostLine repository (Status: VERIFIED / SUBMITTED)               |
|  * Optional GL Posting Funnel (Controlled via postsIndependently flag)            |
+------------------------------------+----------------------------------------------+
                                     |
                    +----------------+----------------+
                    |                                 |
                    v                                 v
+------------------------------------+   +------------------------------------------+
|       ANALYTICAL SUBLEDGER         |   |             GENERAL LEDGER               |
|            (cost_lines)            |   |       (journal_entries / lines)          |
|                                    |   |                                          |
|  * Nature: planned, committed,     |   |  * Dr 5000 Cost of Sales / Inventory     |
|    accrued, actual                 |   |  * Cr 2000 AP / Cash / GRN Accrual       |
|  * Element & Material breakdown    |   |  * Dimension: project_id, dept_id        |
+-------------------+----------------+   +--------------------+---------------------+
                    |                                         |
                    +--------------------+--------------------+
                                         |
                                         v
+-----------------------------------------------------------------------------------+
|                         COST ACCOUNT SERVICE (Margin Engine)                      |
|                                                                                   |
|   Direct Margin = Billed Revenue (Invoices) - Cost of Sales (Actual / Released)   |
|   Completeness: Flags missing Labour (W7) & Logistics (W8) as "Not Included"      |
|   Alerts: Cost Overrun (> tolerance), Margin Warning (< 40%), Escalation (< 35%)  |
+-----------------------------------------------------------------------------------+
```

### Components Operating Correctly:
- **`CostCollectorService::post()` & `postFromSource()`:** Idempotent, fail-soft ingestion of project costs.
- **`CostLine` Model:** Categorization by `budget_category` (`materials`, `labour`, `logistics`, `expenses`, `subcontractor`, `equipment`), `nature`, and `status`.
- **`StoresCostProducer`:** Real-time generation of `ACTUAL` cost lines when inventory is issued to a job number.
- **`ProcurementCostProducer`:** Lifecycle transitions from PO `COMMITTED` lines to Bill `ACTUAL` lines.
- **`CostAccountService::forEnquiry()`:** Project cost account card displaying budget vs. spend by category, element breakdown for materials, exception spending (rework), coverage metrics, and real-time margin alerts.

---

## 3. Direct Cost Engine vs General Ledger Interaction

Project costing in WNG ERP acts as a **specialized project subledger**, not a parallel accounting system.

| Attribute | Analytical Cost Collector (`cost_lines`) | General Ledger (`journal_entries` / `lines`) |
|---|---|---|
| **Primary Identifier** | `cost_lines.id` / `ref` (`CL-xxxxxxx`) | `journal_entries.id` / `entry_no` (`JE-xxxxxxx`) |
| **Granularity** | Line item (specific element, material, supplier, unit cost) | Account totals (Dr/Cr) |
| **Natures Handled** | `planned`, `committed`, `accrued`, `actual` | `actual` financial transactions only |
| **Balance Requirement**| Single-sided cost attribution | Balanced double-entry ($\sum \text{Dr} = \sum \text{Cr}$) |
| **Posting Flag** | Controlled by `CostContext::$postsIndependently` | Direct journal dispatch |
| **Linkage** | Holds `journal_entry_id` once posted | Holds `dimension_project_id` on line entries |

### Subledger Coordination Logic:
- When a cost producer runs with `$context->postsIndependently = true`, `CostCollectorService` calls `JournalPostingService` to record the official double-entry journal.
- When `$context->postsIndependently = false` (enforced for petty cash surrender items and stores issues that possess dedicated domain posting services), `CostCollectorService` **creates the analytical `CostLine` without firing an independent journal**, ensuring complete alignment without duplicate ledger postings.

---

## 4. Exact Baseline Test Status (Current Truth)

Before finalizing this architecture, the entire backend cost and finance feature test suite was executed against an isolated, freshly migrated `db_test` instance inside the DDEV environment:

### Backend Test Execution Evidence:
- **Command:** `ddev exec ./vendor/bin/phpunit tests/Feature/CostCollector/ tests/Feature/Finance/CostLineTaxPostingTest.php tests/Feature/Finance/ClientFinancialPositionTest.php`
- **Result:** **PASS — 246 tests, 3,303 assertions, 0 failures, 0 errors** (Duration: 3m 59s).
- **Key Test Suites Verified:**
  1. `CostAccountTest.php`: **23 tests, 101 assertions** (All passing — verifies element grouping, material drilldown, overrun/margin alerts, project grid search, unbudgeted spend).
  2. `CostCollectorApiTest.php` & `CostCollectorServiceTest.php`: **Passing** (Verifies token auth, permission gates, fail-soft resilience, idempotent ingest).
  3. `MaterialToCostChainTest.php` & `StoresCostProducerTest.php`: **Passing** (Verifies material requisition $\rightarrow$ issue $\rightarrow$ cost line lifecycle).
  4. `ProcurementCostProducerTest.php`: **Passing** (Verifies PO commitment $\rightarrow$ GRN accrual $\rightarrow$ Bill settlement).
  5. `PettyCashCostProducerTest.php`, `PettyCashCostListenerTest.php`, `PettyCashCostPostingFailureTest.php`: **Passing** (Verifies STAB-4 and STAB-7 forward guards).
  6. `ClientFinancialPositionTest.php`: **Passing** (Verifies 6-figure commercial separation).
  7. `CostLineTaxPostingTest.php`: **Passing** (Verifies KRA PIN, ETR reference, and tax isolation on cost lines).
- **Frontend Vitest Baseline:** 24 test files, 133 tests passed (`wave2Controls.spec.ts`, `enquiryFinanceModal.spec.ts`, etc.).

---

## 5. Inventory & Stores Integration (What Feeds Cost)

Stores movements feed project costing automatically via `StoresCostProducer.php` and `StoresCostSubscriber.php`:
1. **Material Dispatch / Issue Execution (`InventoryLog` created):**
   - Action: `issue`
   - Cost Valuation: Sourced from `InventoryLog::unit_cost` (weighted average or actual receipt cost).
   - `StoresCostProducer::createFromIssue()` records a `CostLine` (`nature: actual`, `budget_category: materials`, `status: verified`, tagged with element, library material ID, and inventory log reference).
2. **Returns & Offcuts:**
   - Unused materials returned to stock create an `InventoryLog` (`action: return`).
   - `StoresCostProducer::createFromReturn()` records a negative `ACTUAL` `CostLine` consuming the previous issue line, immediately reducing direct material cost.
3. **Double-Posting Protection:**
   - The stores movement GL entry is posted via `StoresPostingService` (Dr 5010 Materials Expense / Cr 1410 Inventory Asset).
   - The resulting `CostLine` has `postsIndependently = false`, preventing duplicate GL postings.

---

## 6. Procurement & Supplier Billing Integration

Procurement costs transition through a disciplined three-stage commitment lifecycle managed by `ProcurementCostProducer.php`:

```
   [ Approved PO Item ]  ------>  CostLine(nature: COMMITTED, status: VERIFIED)
            |
            v
   [ Goods Receipt Note ] ------>  CostLine(nature: ACCRUED, consumes: COMMITTED)
            |
            v
   [ Verified Supplier Bill ] -->  CostLine(nature: ACTUAL, consumes: ACCRUED/COMMITTED)
```

1. **PO Approval:** Creates a `COMMITTED` cost line. Tracks encumbrance; zero GL impact.
2. **PO Amendment (W2-4):** Recalculates commitments via `repostForPurchaseOrder()`, preserving item identities and updating the committed total.
3. **Goods Receipt (GRN):** Creates an `ACCRUED` cost line and relieves the `COMMITTED` line. GL posts Dr 1410/5010 / Cr 2020 GRN Accrual.
4. **Supplier Bill Verification (Three-Way Match):** Converts `ACCRUED` to `ACTUAL` (or directly from `COMMITTED` for services), stamps `settled_by_bill_id`, and posts GL clearing of Cr 2020 to Cr 2000 AP.
5. **Direct Supplier Bills:** Bypassing PO creates an immediate `ACTUAL` cost line and AP liability.

---

## 7. Petty Cash & Expense Integration (Post STAB-7)

Under the confirmed STAB-7 forward fix, petty cash adheres to the single economic cost invariant:
1. **Advance Disbursement (`PettyCashAdvancePoster`):** Money leaves the float: Dr 1300 Staff Advance / Cr 1020 Petty Cash Float. **Zero project cost is recognized at disbursement.** An advance is an open employee balance sheet receivable, not an incurred project expense.
2. **Expense Incurrence & Surrender (`postPettyCashSurrender()`):**
   - Employee presents itemized receipts for Job `WNG-xxxx`.
   - Finance verifies the surrender.
   - **Exactly one GL Clearing Entry fires (`JE-PCS-*`):**
     - Dr 5xxx Project Expense / Cost of Sales (per item expense code)
     - Cr 1300 Staff Advance (clearing the advance)
     - Cr 1020 Petty Cash Float (if reimbursement exceeds advance) or Dr 1020 (if unspent cash returned)
   - Analytical `CostLine` records are created with `postsIndependently = false` and stamped with `journal_entry_id = $clearingEntry->id`.
3. **Direct Disbursements (No Requisition):**
   - For shop-counter cash purchases (`payments.transaction_classification = 'cash_purchase'`), `PettyCashCostProducer::postFor()` fires at disbursement time, generating one `ACTUAL` cost line and one GL entry.

---

## 8. Payment Vouchers & Payment Rail Separation

WNG ERP enforces strict separation between cost recognition and debt settlement:
> **Payment settles a balance sheet liability; payment never creates a project cost.**

- **Payment Vouchers (`spend_vouchers`):** Raised strictly to settle an existing, verified `CostLine` or `Bill`.
- **Validation Guard:** `SpendVoucherController::store()` requires `cost_line_id` or `bill_id`. It cannot invent new project costs.
- **GL Posting:** When executed via `PaymentSettlementService::settle()`, it posts:
  - Dr 2000 Accounts Payable (or Accrued Expense)
  - Cr 1010 Bank / 1030 M-Pesa
- **Impact on Project Cost / Margin:** Zero. The expense was recognized at bill verification or stores issue. Payment discharges the liability.

---

## 9. Revenue Recognition & Margin Engine Architecture

Project margin is computed within `CostAccountService::marginAgainstJournals(ProjectEnquiry $enquiry)`.

### Commercial Revenue Determination:
- Sourced exclusively from issued, non-void `project_invoices` where `journal_entry_id IS NOT NULL`.
- Unallocated client receipts and draft quotations are excluded from the margin numerator.
- Formula:
  $$\text{Billed Revenue} = \sum \text{project\_invoices.total\_amount} \quad (\text{status} \neq \text{'void'} \land \text{journal\_entry\_id IS NOT NULL})$$

### Cost of Sales Determination:
- **Tier 1 (WIP Release Active):** If `journal_lines` exist on 5xxx accounts linked to `ProjectInvoice` via `WorkInProgressReleaseService`, the engine uses `released_cost`.
- **Tier 2 (Fallback - Current Live State):** Under STAB-2 (no WIP accounts on chart), the engine falls back to:
  $$\text{Direct Actual Cost} = \sum \text{cost\_lines.net\_amount} \quad (\text{nature} = \text{'actual'} \land \text{status} = \text{'verified'})$$
- Planned and committed lines are strictly excluded from Cost of Sales.

---

## 10. Single-Project Margin Formula & Behavior (with Direct Margin Caveat)

### Mathematical Formula:
$$\text{Direct Project Margin (KES)} = \text{Billed Revenue} - \text{Direct Actual Cost}$$

$$\text{Direct Margin \%} = \begin{cases} 
\left(\frac{\text{Direct Project Margin}}{\text{Billed Revenue}}\right) \times 100 & \text{if Billed Revenue} > 0 \\
0.00 & \text{if Billed Revenue} = 0 
\end{cases}$$

### Direct Margin Completeness Caveat:
Direct Project Margin reflects only currently integrated direct cost categories (materials, supplier bills, direct site expenses). Because labour (W7) and automated fleet/logistics (W8) are not yet integrated:
- The calculation must **never** be presented as "True Profit" or "Final Profit".
- The API response and UI must return an explicit completeness indicator:
  ```json
  "margin": {
    "margin_type": "direct_project_margin",
    "billed_revenue": "1000000.00",
    "direct_actual_cost": "645000.00",
    "direct_margin_amount": "355000.00",
    "direct_margin_percent": 35.5,
    "cost_completeness": {
      "materials": { "status": "included", "amount": "420000.00" },
      "subcontractors_bills": { "status": "included", "amount": "180000.00" },
      "expenses": { "status": "included", "amount": "45000.00" },
      "labour": { "status": "not_included", "amount": null, "reason": "Workflow 7 project labour allocation not yet implemented" },
      "logistics_automated": { "status": "not_included", "amount": null, "reason": "Workflow 8 fleet/logistics cost allocation not yet implemented" },
      "overhead": { "status": "not_included", "amount": null, "reason": "W6-1A overhead allocation awaiting Finance/Management confirmation" }
    }
  }
  ```

### Active Alerting Triggers (`CostAccountService::alerts()`):
1. **Cost Overrun Alert:** Triggers if $\text{Direct Actual Cost} > \text{Planned Budget} \times (1 + \text{tolerance})$. Requires `cost_overrun_alert_percent` in `finance_settings`.
2. **Margin Warning Alert:** Triggers if $\text{Direct Margin \%} < \text{margin\_warning\_percent}$ (seeded default: 40%).
3. **Margin Escalation Alert:** Triggers if $\text{Direct Margin \%} < \text{margin\_escalation\_percent}$ (seeded default: 35%).

---

## 11. Portfolio Margin Architecture & Why It Is Missing Today

### Current Implementation Gap:
`CostAccountController::index()` aggregates planned vs. actual spend per project across `cost_lines`, but **never invokes `marginAgainstJournals()`**. Consequently, the portfolio screen displays spend totals but zero billed revenue, zero margin KES, and zero margin percentages.

### Target W6 Portfolio Margin Architecture (Option A Confirmed):
A batched aggregation query (`CostAccountService::portfolioMargin()`) will be built to:
1. Eager-load posted `project_invoices` and verified `cost_lines` grouped by `project_enquiry_id`.
2. Compute Billed Revenue, Direct Actual Cost, Committed Cost, Direct Margin KES, and Direct Margin % using the exact same formula as single-project costing.
3. Include Project Title, Client Name, Project Officer, Contract/Quote Value, and Provisional vs. Final status.
4. Include explicit **cost-completeness indicators** showing that Labour and Automated Logistics are not included.
5. Strictly **exclude Fully Loaded Margin** and **never silently treat missing Labour/Logistics as zero**.

---

## 12. Shared Project Cost Allocation Gap (W6-3)

### The Business Need:
Operational teams frequently incur costs that serve multiple projects simultaneously (e.g. a single KES 45,000 transport lorry carrying equipment for Job A, Job B, and Job C). Currently, `cost_lines.project_enquiry_id` is an atomic, single foreign key.

### Technical Architecture & Parent CostLine Behavior:
1. **Core Accounting Invariant:**
   $$\sum \text{allocated\_amount} = \text{parent\_cost\_line.net\_amount}$$
2. **Preventing Double-Counting of the Parent Line:**
   - If a parent `CostLine` of KES 45,000 is split into allocations of KES 20,000 (Job A), KES 15,000 (Job B), and KES 10,000 (Job C), the parent line must **not** continue counting toward project costs.
   - **Architectural Rule:** The parent `CostLine` acts as the financial anchor linked to the GL journal, but its `project_enquiry_id` is retired or flagged as `is_allocated = true`.
   - **Verification Test:** Unit tests must prove that total company analytical project cost equals exactly **KES 45,000**, preventing the catastrophic error of Parent (KES 45,000) + Allocations (KES 45,000) = KES 90,000.
3. **Data Model (`cost_line_allocations`):**
   - Fields: `id`, `parent_cost_line_id` (FK `cost_lines`), `target_project_enquiry_id` (FK `project_enquiries`), `allocated_amount` (decimal 15,2), `percentage` (decimal 5,2), `basis_notes` (text), `created_by` (FK `users`), timestamps.
4. **GL Treatment:** Zero duplicate GL entries. The GL entry remains the single parent transaction.

---

## 13. Cost Transfer & Misallocation Reclassification Gap (W6-4)

### The Business Need:
When an actual cost is mistakenly coded to the wrong project (e.g. Job `WNG-0104` instead of `WNG-0140`), editing historical records is forbidden.

### Reclassification Pair Model:
1. **Reversing Pair Invariant:**
   $$\Delta \text{Project A Cost} + \Delta \text{Project B Cost} = 0 \quad (\text{Total Company Expense Unchanged})$$
2. **Execution:**
   - **Line 1 (Credit/Reduction):** Project A, Nature: `actual`, Status: `verified`, Amount: `-$X`, Reference: `CL-TRF-OUT-xxxx`, linked to origin line via `reclassification_of_line_id`.
   - **Line 2 (Debit/Addition):** Project B, Nature: `actual`, Status: `verified`, Amount: `+$X`, Reference: `CL-TRF-IN-xxxx`, linked to origin line.
3. **Audit Trail:** Mandatory logging of `source_project_id`, `destination_project_id`, `amount`, `reason`, `transferred_by`, timestamps.
4. **GL Dimension Sync:** If GL project dimensions are active, an inter-project dimension transfer journal is recorded:
   - Dr 5010 Project Materials (Project B) / Cr 5010 Project Materials (Project A). Company-wide P&L is completely unaffected.

---

## 14. Project Financial Closure & Status Separation (W6-5)

### Status Separation:
A project reaches **Operational Completion** when deliverables are installed and handed over. It reaches **Financial Closure** only after all commercial and financial liabilities are reconciled.

### Distinction Between Confirmed Requirement vs. Open Blocker Policy:
- **Confirmed by WNG (2026-09-23):** A distinct Project Financial Closure process is required.
- **Awaiting WNG/Finance Confirmation:** Which pre-closure conditions are **HARD BLOCKERS**, which are **WARNINGS (override permitted)**, and which are **INFORMATIONAL ONLY**.

### Proposed Financial Closure Verification Checklist (Awaiting WNG Classification):

| Checklist Item | Description | Proposed Default | WNG Decision Required |
|---|---|---|---|
| **1. Remaining Amount to Invoice** | Approved quote value minus total amount invoiced to client | WARNING | Blocker vs Warning vs Info |
| **2. Invoice Outstanding** | Issued invoices not yet fully paid by client | WARNING | Blocker vs Warning vs Info |
| **3. Unallocated Client Credit** | Unallocated cash receipts or deposits remaining on client account | WARNING | Blocker vs Warning vs Info |
| **4. Open PO Commitments** | Approved Purchase Orders not yet fully billed or cancelled | BLOCKER | Blocker vs Warning vs Info |
| **5. Unreceived / Open POs** | Purchase Orders with pending goods delivery | BLOCKER | Blocker vs Warning vs Info |
| **6. Unverified Supplier Bills** | Supplier Bills awaiting three-way match or finance verification | BLOCKER | Blocker vs Warning vs Info |
| **7. Unpaid Supplier Liabilities** | Verified supplier bills with unpaid balances | WARNING | Blocker vs Warning vs Info |
| **8. Outstanding Petty Cash Advances** | Project-tagged petty cash advances not yet surrendered | BLOCKER | Blocker vs Warning vs Info |
| **9. Pending Project Expenses** | Expense claims or direct disbursement requests pending approval | BLOCKER | Blocker vs Warning vs Info |
| **10. Unresolved Cost Transfers** | Proposed cost transfers (W6-4) awaiting approval | BLOCKER | Blocker vs Warning vs Info |
| **11. Unresolved Credit Notes** | Unapplied or unallocated client credit notes | WARNING | Blocker vs Warning vs Info |
| **12. Other Finance Exceptions** | Failed GL postings or open reconciliation flags on the project | BLOCKER | Blocker vs Warning vs Info |

---

## 15. WIP vs Immediate COGS Accounting Policy Status (STAB-2 / W6-6)

### Status: AWAITING FINANCE / ACCOUNTANT CONFIRMATION
- **Option A:** Capitalise direct costs as WIP (`1420 Work In Progress`) until billed, then release to Cost of Sales (`5010`).
- **Option B (Current Behavior):** Expense project costs immediately to Cost of Sales upon purchase or stores issue.
- **Engineering Position:** Engineering will not create WIP accounts or alter COGS timing until WNG Finance confirms Option A in writing. The margin engine's fallback to verified actual costs remains active and compliant.

---

## 16. Cost Overrun & Commitment Cleanup Architecture (W6-7)

### Status: REQUIRES FURTHER ANALYSIS — SEPARATE MECHANISMS
Two fundamentally different concepts must not be confused:

### 1. Commitment Cleanup (Operational — Verified & Preserved)
- Occurs when an approved Purchase Order is fulfilled for less than the approved value (e.g. PO approved for KES 100,000; final supply and bill is KES 85,000; residual KES 15,000 commitment remains).
- **Mechanism:** `ProcurementCostProducer::releaseFor()` cancels the remaining `COMMITTED` cost line.
- **Accounting Impact:** Zero expense. Zero GL write-off. It simply unencumbers the project budget. This existing mechanism will be preserved and covered by regression tests.

### 2. Financial Write-Off (Accounting Policy — Awaiting Accountant Sign-Off)
- Occurs when an actual asset, inventory, or unbillable project expenditure is formally written off as an economic loss.
- **Accounting Impact:** Dr 5090 Project Loss / Cr 1410 Inventory (or AR).
- **Engineering Rule:** Do **NOT** build a generic `Write Off Project Cost` action in W6. Genuine write-offs remain blocked pending formal accountant policy.

---

## 17. Multi-Currency in Project Costing (W6-8)

- Base operating currency is **KES**.
- Foreign currency supplier bills or invoices convert to KES at the transaction date exchange rate from `currency_rates`.
- Single-project and portfolio margins are computed and reported in KES.

---

## 18. Historical Re-costing & Cost Freeze Policy (W6-9)

1. **Active Projects:** Cost lines can be added dynamically as operational movements occur.
2. **Financially Closed Projects:**
   - Ingestion of new `CostLine` records is strictly blocked.
   - Any late-arriving bill requires a formal **Late Cost Exception** (W6-6).
3. **Late-Cost Reopen Authority (W6-6):** The authority permitted to approve reopening remains **AWAITING WNG CONFIRMATION**. The system will provide an unassigned permission (`finance.costs.reopen`), but no role is granted this permission until WNG confirms the role holder.

---

## 19. Provisional Margin vs Final Margin Status (W6-10)

Reporting must clearly distinguish margin maturity:

| Project Status | Margin Classification | Definition & Basis |
|---|---|---|
| **Active / In Delivery** | **Provisional Margin** | Project active or financially open; costs and revenue remain subject to change. |
| **Delivered / Pending Close** | **Provisional Margin** | Operational work completed; awaiting final vendor bills or closure verification. |
| **Financially Closed** | **Final Margin** | **Final based on the confirmed cost categories and financial-close controls applicable at that time.** (Never claimed as absolute while cross-workflow dependencies like W7/W8 remain unintegrated). |

---

## 20. Customer Billing Visibility vs Internal Costing Separation (W6-11)

- Client documentation (Quotations, Invoices, Delivery Notes, Statements) must **never display internal cost lines, supplier names, purchase prices, or margin percentages**.
- Internal project cost cards (`CostAccountPanel.vue`) and portfolio views (`PortfolioMarginView.vue`) are restricted strictly to authorized internal roles (`finance.costs.read`, `finance.costs.portfolio`).

---

## 21. Client-Specific Rate Card Architecture (W6-12)

- Quotations utilize client-specific rate cards and library prices.
- Project Costing tracks **actual economic costs incurred**, regardless of rate cards.
- Variances between quoted rate card amounts and actual costs are surfaced in element-level budget variance reporting.

---

## 22. Workflow 7 — Labour Cost Dependency Analysis

### Status: NOT INTEGRATED (Blocked Pending Workflow 7)
- `AttendanceRecord` (biometric clocks) and `JobCard` / `TechnicalLabour` track hours but lack confirmed project foreign keys.
- Payroll posts company-wide debits to `5020 Direct Labour` / `6010 Salaries Expense` without project subledger attribution.
- **Architectural Correction:** Project Costing will **NOT** display Labour as `0.00`. Instead, the API and UI will represent Labour as:
  ```text
  Labour: Not Included (Status: Unintegrated, Reason: Workflow 7 project labour allocation not yet implemented)
  ```
- No timesheets, crew-day models, standard labor rates, or payroll allocations are built in W6.

---

## 23. Workflow 8 — Logistics & Fleet Cost Dependency Analysis

### Status: NOT INTEGRATED (Blocked Pending Workflow 8)
- `TripRequest` and `DeliveryStop` track operational logistics but contain zero automated calls to `CostCollectorService`.
- **Architectural Correction:** Automated logistics will **NOT** be displayed as `0.00`. It will be explicitly represented as:
  ```text
  Logistics (Internal Fleet): Not Included (Status: Unintegrated, Reason: Workflow 8 fleet/logistics cost allocation not yet implemented)
  ```
- External transport already captured through valid supplier bills or petty cash receipts will continue appearing under direct costs as they currently do.

---

## 24. Overhead Allocation & Fully Loaded Margin Architecture

### Status: AWAITING WNG / FINANCE CONFIRMATION (W6-1A)
- The concept involves apportioning indirect company expenses across revenue-generating projects.
- **Engineering Position:** No overhead driver (revenue %, direct cost %, labour hours) is implemented.
- Fully Loaded Margin remains flagged as `fully_loaded_available = false` with an explanatory caveat.

---

## 25. Direct Project Margin vs Fully Loaded Margin Distinction

| Dimension | Direct Project Margin (W6 Confirmed Scope) | Fully Loaded Margin (Future Target) |
|---|---|---|
| **Revenue Basis** | Issued, posted client invoices | Issued, posted client invoices |
| **Cost Basis** | Confirmed direct costs (materials, supplier bills, site expenses) | Direct costs + allocated indirect company overhead |
| **Completeness State** | Explicitly marked as partial (Labour & Automated Logistics not included) | Full absorption costing |
| **Reporting Labels** | **Direct Project Margin** (Never "True Profit" or "Final Profit") | Fully Loaded Margin |
| **System Status** | **LIVE AND OPERATIONAL** | **BLOCKED PENDING W6-1A** |

---

## 26. Single Economic Cost Principle Verification

WNG ERP enforces that one real shilling is recognized as project cost exactly once:
1. **Materials:** PO (Commitment) $\rightarrow$ GRN (Accrual) $\rightarrow$ Issue (Actual Cost) $\rightarrow$ Supplier Bill (AP Liability). Each step relieves the prior stage.
2. **Petty Cash:** Advance (Balance Sheet Asset) $\rightarrow$ Surrender (Single Cost Line + Single Clearing Journal).
3. **Vendor Payments:** Bill (Cost recognized) $\rightarrow$ Payment Voucher (Liability discharged). Zero duplicate cost line.

---

## 27. Cost Line Mutability, Reversals & Audit Trail

- `CostLine` records are **append-only**.
- Verified cost lines cannot be updated or deleted.
- Adjustments use compensatory reversing lines (`status = STATUS_REVERSED` or `reclassification_of_line_id`).
- All changes are logged in `action_logs` and `GovernanceAuditLog`.

---

## 28. Cost Collector Resilience & Fail-Soft Architecture

- Ingestions execute inside database transactions (`DB::transaction()`).
- Fail-soft architecture ensures operational dispatches proceed even if analytical tagging requires administrative review.
- Idempotency is enforced using source document keys (`source_type`, `source_id`).

---

## 29. Client Financial Position vs Project Cost Separation

Verified in Wave 1 and maintained in `ClientFinancialPositionService`:

```
+-----------------------------------------------------------------------------------+
|               CLIENT COMMERCIAL POSITION (ClientFinancialPositionService)         |
|  * Approved Quote / Contract Value: KES 1,500,000                                 |
|  * Invoiced to Date:                KES 1,000,000                                 |
|  * Cash Received to Date:           KES   800,000                                 |
|  * Invoice Outstanding:             KES   200,000                                 |
|  * Unallocated Client Credit:       KES         0                                 |
|  * Remaining to Invoice:            KES   500,000                                 |
+-----------------------------------------------------------------------------------+
                                         |
                                         | Commercial vs Cost Firewall
                                         v
+-----------------------------------------------------------------------------------+
|                     PROJECT COST POSITION (CostAccountService)                    |
|  * Direct Material Actual Cost:     KES   420,000                                 |
|  * Direct Subcontractor / Bills:    KES   180,000                                 |
|  * Direct Site Expenses:            KES    45,000                                 |
|  * Direct Labour:                   Not Included (Awaiting W7)                    |
|  * Direct Fleet / Logistics:        Not Included (Awaiting W8)                    |
|  * Total Direct Cost Captured:      KES   645,000                                 |
|  * Direct Margin (KES):             KES   355,000  (Billed 1,000,000 - Cost 645k) |
|  * Direct Margin (%):               35.5% (Warning Flag Triggered)                |
|  * Completeness Flag:               PARTIAL DIRECT (Labour/Logistics Not Included)|
+-----------------------------------------------------------------------------------+
```

---

## 30. Financial Dimension Attribution (Project, Department, Cost Center)

Financial transactions carry three independent attribution dimensions:
1. `dimension_project_id` (Job Number / Project Enquiry)
2. `dimension_department_id` (Operations, Production, Creative, Admin)
3. `cost_center_id` (Regional / Business Unit categorization)

---

## 31. Permissions, Roles & Segregation of Duties for Costing

| Permission Key | Description | Intended Role | Status |
|---|---|---|---|
| `finance.costs.read` | View project cost accounts and category drilldowns | Project Officer, Accounts, Costing, Management | Active |
| `finance.costs.portfolio` | View portfolio-wide project margin summaries | Management, Finance Lead | Proposed (W6) |
| `finance.costs.allocate` | Execute multi-project shared cost allocations | Accounts, Costing Lead | Proposed (W6) |
| `finance.costs.transfer` | Reclassify / transfer misallocated costs | Finance Lead | Proposed (W6) |
| `finance.costs.close` | Execute project financial closure | Finance Lead, Management | Proposed (W6) |
| `finance.costs.reopen` | Approve late-cost additions to closed projects | **UNASSIGNED PENDING WNG CONFIRMATION** | Unassigned |

---

## 32. Database Schema Status & Required Additions for W6

### Additive Relational Tables for W6 Confirmed Scope:
1. **`cost_line_allocations`:**
   - `id`, `parent_cost_line_id` (FK `cost_lines`), `target_project_enquiry_id` (FK `project_enquiries`), `allocated_amount` (decimal 15,2), `percentage` (decimal 5,2), `basis_notes` (text), `created_by` (FK `users`), timestamps.
2. **`project_financial_closures`:**
   - `id`, `project_enquiry_id` (FK `project_enquiries`), `status` (`closed`, `reopened`), `final_margin_percent` (decimal 5,2), `final_billed_revenue` (decimal 15,2), `final_direct_cost` (decimal 15,2), `closed_by` (FK `users`), `closed_at` (timestamp), `reopen_reason` (text), `reopened_by` (FK `users`), timestamps.
3. **`cost_line_transfers`:**
   - `id`, `source_cost_line_id`, `destination_cost_line_id`, `reason`, `transferred_by`, timestamps.

---

## 33. API Contract Specifications for Target W6 Endpoints

### 1. Portfolio Profitability Grid (`GET /api/costs/portfolio`)
```json
{
  "data": [
    {
      "enquiry_id": 101,
      "job_number": "WNG-ACC-001",
      "project_title": "Safaricom Activation",
      "client_name": "Safaricom PLC",
      "project_officer": "Jane Doe",
      "financial_status": "active",
      "contract_value": "1500000.00",
      "billed_revenue": "1000000.00",
      "direct_actual_cost": "645000.00",
      "direct_committed_cost": "50000.00",
      "direct_margin_amount": "355000.00",
      "direct_margin_percent": 35.5,
      "margin_tier": "provisional",
      "completeness": {
        "labour": "not_included",
        "logistics": "not_included",
        "overhead": "not_included"
      },
      "alerts": {
        "overrun": false,
        "margin_warning": true,
        "margin_escalation": false
      }
    }
  ],
  "portfolio_totals": {
    "total_billed_revenue": "45000000.00",
    "total_direct_cost": "28500000.00",
    "average_direct_margin_percent": 36.67
  }
}
```

### 2. Shared Cost Allocation (`POST /api/costs/lines/{id}/allocate`)
```json
{
  "allocations": [
    { "target_project_enquiry_id": 101, "amount": "25000.00", "notes": "Job A staging share" },
    { "target_project_enquiry_id": 102, "amount": "20000.00", "notes": "Job B staging share" }
  ]
}
```

### 3. Cost Transfer / Reclassification (`POST /api/costs/lines/{id}/transfer`)
```json
{
  "destination_project_enquiry_id": 105,
  "amount": "15000.00",
  "reason": "Stores dispatch incorrectly tagged to WNG-0101 instead of WNG-0105"
}
```

### 4. Financial Closure Pre-flight & Execution (`GET /api/costs/projects/{id}/closure-check` & `POST /api/costs/projects/{id}/close`)

---

## 34. Frontend Architecture & UI Component Plan for W6

1. **`PortfolioMarginView.vue` (`src/views/finance/PortfolioMarginView.vue`):** Executive project margin grid displaying Direct Margin, contract value, billed revenue, provisional/final status, and explicit cost-completeness badges.
2. **`CostAllocationModal.vue` (`src/components/finance/costing/CostAllocationModal.vue`):** Multi-project split modal with live sum-validation.
3. **`CostTransferModal.vue` (`src/components/finance/costing/CostTransferModal.vue`):** Reclassification modal with mandatory reason capture.
4. **`ProjectFinancialClosureModal.vue` (`src/components/finance/costing/ProjectFinancialClosureModal.vue`):** Closure checklist displaying verified conditions and warnings.

---

## 35. Decision Register Reconciliation (W6-1 to W6-12)

The table below reconciles Report 34 directly against `03_WNG_FINANCE_DECISION_REGISTER.md`:

| Decision ID | Summary Description | Status in Decision Register | Phase 2B Action |
|---|---|---|---|
| **W6-1** | Direct vs Fully Loaded Margin | **CONFIRMED (Option C)** | Implement Direct Margin; keep Fully Loaded blocked |
| **W6-1A** | Overhead Allocation Basis | **AWAITING FINANCE/ACCOUNTANT CONFIRMATION** | Blocked; no formula implemented |
| **W6-2** | Portfolio Margin View | **CONFIRMED (Option A)** | Implement in W6 Confirmed Subset |
| **W6-3** | Shared Cost Multi-Project Allocation | **CONFIRMED** | Implement in W6 Confirmed Subset |
| **W6-4** | Project Cost Transfer / Reclassification | **CONFIRMED** | Implement in W6 Confirmed Subset |
| **W6-5** | Project Financial Closure Step | **CONFIRMED (Checklist rules open)** | Implement mechanism; checklist rules open |
| **W6-6** | Late Costs Post Closure Exception | **CONFIRMED (Reopen authority open)** | Implement mechanism; authority unassigned |
| **W6-7** | Commitment Release vs Write-Off | **REQUIRES FURTHER ANALYSIS** | Verify commitment release; no write-off build |
| **W6-8** | Profitability Functional Ownership | **CONFIRMED** | Governance model documented |
| **W6-9** | Margin Visibility Scoping | **CONFIRMED** | Scoped visibility; payroll detail protected |
| **W6-10** | Provisional vs Final Margin Status | **CONFIRMED** | Implement in W6 Confirmed Subset |
| **W6-11** | Budget Revision History Baseline | **EXISTING CONTROL PRESERVED** | Preserve append-only; baseline report open |
| **W6-12** | Historical As-Of Date Profitability | **NOT SUPPORTED (Priority Awaiting WNG)** | Do NOT implement now; priority open |

---

## 36. End-to-End Confirmed-Subset Implementation Matrix

The following matrix identifies the authoritative confirmed scope for W6 implementation:

| ID | Decision Status | Implement Now? | Backend | Frontend | Accounting Impact | Open Dependency |
|---|---|---|---|---|---|---|
| **W6-1** | CONFIRMED (Option C) | **Yes (Direct Only)** | `CostAccountService` direct margin | `CostAccountPanel.vue` Direct Margin tag | None (Direct cost) | W6-1A for Fully Loaded |
| **W6-1A** | AWAITING CONFIRMATION | **No** | Flag `fully_loaded_available=false` | Badge "Overhead Not Configured" | None | Finance/Accountant sign-off |
| **W6-2** | CONFIRMED (Option A) | **Yes** | `portfolioMargin()` batched query | `PortfolioMarginView.vue` | None | None |
| **W6-3** | CONFIRMED | **Yes** | `cost_line_allocations` + split service | `CostAllocationModal.vue` | None (Analytical split) | None |
| **W6-4** | CONFIRMED | **Yes** | `cost_line_transfers` reversing pair | `CostTransferModal.vue` | Optional dimension reclassification | None |
| **W6-5** | CONFIRMED (Checklist open) | **Yes (Mechanism only)** | `ProjectFinancialClosureService` | `ProjectFinancialClosureModal.vue` | Locks project subledger | WNG confirmation of blocker vs warning rules |
| **W6-6** | CONFIRMED (Authority open) | **Yes (Mechanism only)** | Late-cost exception recorder | Reopen exception form | Stamped late-cost entry | WNG confirmation of reopen authority |
| **W6-7** | REQUIRES FURTHER ANALYSIS | **Verify Only** | Test existing `releaseFor()` | Commitment action check | None (Operational release) | Accountant policy for write-offs |
| **W6-8** | CONFIRMED | **Documented** | None (Process ownership) | None | None | None |
| **W6-9** | CONFIRMED | **Yes** | Scoped policy checks (`costs.portfolio`) | Scoped menu visibility | None | ROLE-1 / ROLE-2 |
| **W6-10**| CONFIRMED | **Yes** | Provisional vs Final margin flags | Provisional / Final badge | None | W6-5 closure |
| **W6-11**| EXISTING CONTROL | **Preserve Only** | Append-only revision preserved | None | None | Baseline report priority |
| **W6-12**| NOT SUPPORTED (Priority Open)| **No** | None | None | None | Build priority confirmation |

---

## 37. Decisions WNG / Finance Still Needs to Make Before Full W6 Completion

The following 8 specific policy and accounting decisions remain open and must be confirmed by WNG / Finance before full Project Costing completion:

1. **W6-1A — Overhead Allocation Basis:** Confirm which GL accounts constitute the allocable overhead pool, the allocation period, and the approved allocation driver (Revenue %, Direct Cost %, or Labor Hours).
2. **W6-5 — Financial Closure Checklist Rules:** Formally classify each of the 12 pre-closure checklist items as **HARD BLOCKER**, **WARNING (override permitted)**, or **INFORMATIONAL ONLY**.
3. **W6-6 — Late-Cost Exception Authority:** Specify which named functional role (e.g. Managing Director, Finance Director) holds authority to approve costs arriving after project financial closure.
4. **W6-7 — True Financial Write-Off Policy:** If WNG requires an in-system mechanism to write off unrecoverable project assets or costs, Finance and the company accountant must provide the official GL write-off policy.
5. **W6-11 — Original vs. Current Budget Reporting:** Confirm whether a dedicated "Original Approved Budget vs. Current Budget Baseline" comparison report is required.
6. **W6-12 — Historical As-Of Profitability Build Priority:** Confirm whether historical as-of-date profitability reconstruction is a near-term build priority.
7. **STAB-2 — Work In Progress (WIP) Policy:** Formally confirm whether project costs should capitalise as WIP until invoiced (Option A) or expense immediately to Cost of Sales (Option B - current behavior).
8. **W1-10 — Credit Note Cost Matching Policy:** Formally confirm whether client credit notes should reverse a proportionate share of recognized Cost of Sales / WIP.

### Cross-Workflow Dependencies (Governed Separately):
- **Workflow 7 (Labour Costing):** Project labour timesheets, artisan rates, and crew-day allocations are deferred to W7.
- **Workflow 8 (Logistics & Fleet Costing):** Automated vehicle mileage rates, fuel allocations, and trip costing are deferred to W8.

---

## 38. Definitive Implementation Readiness Conclusion

### Authoritative Verdict:
> ### **W6 CONFIRMED SUBSET READY FOR IMPLEMENTATION**

### Next Phase Action:
Proceed to **Phase 2B W6 Confirmed-Subset Implementation — Backend + Frontend Concurrently**, strictly restricted to the confirmed items:
1. **W6-2:** Portfolio Margin batched aggregation and list view (`PortfolioMarginView.vue`).
2. **W6-3:** Shared Cost Multi-Project Allocation (`cost_line_allocations` + `CostAllocationModal.vue`).
3. **W6-4:** Project Cost Transfer Reversing Pair (`cost_line_transfers` + `CostTransferModal.vue`).
4. **W6-5 & W6-10:** Project Financial Closure Pre-flight Engine & Provisional vs. Final Margin Status (`ProjectFinancialClosureModal.vue`).
5. **W6-6:** Late-Cost Exception recording mechanism (with authority permission unassigned).
6. **W6-7:** Regression test verification of existing commitment release mechanisms.
7. **W6-9:** Scoped visibility for portfolio and project margin data.

*Document reconciled and signed off for W6 confirmed-subset implementation.*
