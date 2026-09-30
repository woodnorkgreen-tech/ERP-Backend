# 08 — Cross-Module Integration Map

Part of the WNG ERP Finance & Accounts Phase 1 audit, dated 2026-09-22. Covers audit section 19.

---

## Integration table

| Source Module | Trigger | Finance Record Created/Updated | Accounting Effect | Status Sync |
|---|---|---|---|---|
| **Projects (Client billing)** | Invoice issued (`EnquiryController::issueProjectInvoice`) | `ProjectInvoice` status → issued; `JournalEntry` via `ReceivablesPostingService::postInvoiceIssued` | **Yes** — same DB transaction as the status flip, so an invoice cannot end up "issued" without its journal entry | One-way: a Finance posting failure blocks the status change (422, not silently ignored) |
| **Projects (client receipt/allocation)** | Receipt verified, then allocated | `EnquiryPayment` → `postClientReceipt`/`postInvoiceAllocation` | Yes, on verification, deliberately not on capture | Same atomic pattern |
| **Projects (WIP → Cost of Sales)** | Same invoice-issue transaction | `WorkInProgressReleaseService::releaseForInvoice()` | Yes — released proportionally to how much of the agreed price has been billed, to avoid revenue/matching-cost landing in different months | Runs in the same transaction as the revenue posting |
| **ProcurementStores (Materials/Stores issue & return)** | Stock issued/returned against a project | `StoresFinancePosting` outbox row → job → `CostLine` via `StoresCostProducer` | Yes, once verified and posted | Outbox pattern with explicit terminal states and a guarded retry — good design. **ASSUMPTION/REQUIRES TECHNICAL CONFIRMATION:** the job's own comment says it "now runs synchronously: there is no background worker to perform a later attempt" despite implementing `ShouldQueue` — confirm `QUEUE_CONNECTION=sync` (or equivalent) actually holds in production; if a real queue driver is ever enabled without a worker, stock movements would stop reaching Finance with no visible error until someone checks the outbox table |
| **ProcurementStores (Supplier bills & payments)** | Bill created/verified/paid | `postSupplierInvoice()`/`postSupplierPayment()` | Yes | Purchase-to-Pay gate — legacy bills grandfathered, so some pre-gate bills may not carry the same posting guarantee |
| **HR (Payroll)** | Payroll run locked/marked paid | `JournalEntry` via `PayrollFinancePostingService::postAccrual()` | Yes — splits gross pay by department's labour classification into direct labour (COS) vs salaries (overhead), and separately posts employer statutory contributions (NSSF/Housing Levy), explicitly because these were "computed and recorded nowhere" before | Confirmed by a dedicated integrity test (`PayrollIntegrityTest`) |
| **Logistics (Vehicle maintenance)** | Maintenance log costed and "approved" | **None.** Only sets `status='approved'`/`approved_by_id`/`approved_at` on `VehicleMaintenanceLog` | **No.** The model has no `bill_id`/`cost_line_id`/`journal_entry_id` field at all | **None — the clearest duplicate-data-entry finding in this audit.** The log's own success message says "Sent to finance for approval," but nothing about approving it creates a Bill, CostLine, or journal entry. For the vendor to actually get paid and for the cost to reach the P&L, someone must **re-type the same service provider, cost breakdown, and amount** into Procurement → Bills as a second, disconnected entry. No report can confirm whether every "approved" maintenance log has a matching bill |
| **Assets** | Any asset lifecycle event | None | **No integration exists at all** — a full-module search for Finance/JournalPostingService/CostLine/CostCollector across `app/Modules/Assets/**` returned zero matches | **Duplicate-entry risk**: equipment cost is recorded once in Procurement/Bills to pay for it, and, if anyone bothers, a second time in the Assets register, with nothing tying the two together. Consistent with the codebase's own documented list of not-yet-built features (no depreciation) |
| **Finance internal (manual cash movement)** | Bank transfer/adjustment | `CashMovement` + `JournalEntry` | Yes, immediately | `void()` reverses atomically — included for completeness, not itself cross-module |
| **PettyCash (advance/surrender)** | Requisition disbursed/surrendered | `JournalEntry` via `postPettyCashAdvance`/`postPettyCashSurrender` | Yes | Ledger-as-truth design already in place |
| **ClientService** | Enquiry/lead intake, handover surveys | None Finance-specific beyond what Projects already covers | N/A | No duplicate entry found — ClientService reads the same `ProjectEnquiry`/`Client` records Projects and Finance use, rather than keeping its own copies |

## Summary of duplicate/disconnected data entry found

**ISSUE (HIGH) — Logistics vehicle maintenance → Finance.** Cost data is captured once in
Logistics with no path to a Bill/CostLine/journal entry; must be manually re-entered by
Finance/Procurement to actually post and pay.

**ISSUE (MEDIUM) — Assets → Finance/Procurement.** No linkage between an asset's acquisition cost
(paid via Procurement/Bills) and its record in the Assets register.

Both **REQUIRE WNG CONFIRMATION** on priority — this may be an accepted manual step for low-volume
categories (WNG may not buy vehicles/equipment often enough to justify automation) rather than an
oversight, but as coded today there is no system record connecting the two sides, and no report
can answer "have all approved maintenance costs actually been paid" without manually cross-checking
two modules line by line.

**Classification:** Logistics→Finance link → **REDESIGN** (the maintenance cost-capture UI already
exists; it just needs to hand off to `CostCollectorService`/`BillController` instead of dead-ending
at a status field). Assets→Finance → **REQUIRES WNG CONFIRMATION** (needs a business decision on
whether asset depreciation/valuation is in scope before building the link).

---

## Questions for WNG

**Management**
1. Should vehicle maintenance and other Logistics-approved costs be forced through the same
   Bill/Cost Collector pipeline as every other supplier cost?
2. Is asset (equipment/vehicle) depreciation and GL valuation something WNG wants tracked in this
   system, or does that stay in the external accounting package permanently?

**SOP/Process**
3. Is there a current manual process for reconciling an "approved" Logistics maintenance log
   against the eventual Procurement bill/payment? If so, how often is it actually performed?

**Technical**
4. Confirm the production `QUEUE_CONNECTION` setting and whether a queue worker process is actually
   running — `ProcessStoresFinancePosting`'s own code comment assumes synchronous (no-worker)
   execution, and stock-cost postings could silently queue up unprocessed if that assumption stops
   holding.

---

*Consolidated risk ratings appear in `10_FINANCE_RISK_REGISTER.md`.*
