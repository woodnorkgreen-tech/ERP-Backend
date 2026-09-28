# 12 — Consolidated Questions for WNG

Every question below requires a business, accounting, or governance decision that cannot be
resolved by reading the code — technical questions answerable from the repository are not
included here (they're answered in the relevant section document instead). Deduplicated across
all Phase 1 audit sections; each question references where it came from for full context.

---

## Management Decisions

1. **Chart of accounts confirmation.** Confirm the 123-account production chart and its "no WIP
   accounts" structure (per `chart-of-accounts-mapping.md`, read 2026-09-07) is still accurate
   today, or supply the current export if it has changed. *(→ 04, §4.1)*
2. **WIP accounting policy.** Decide the WIP-vs-immediate-COGS policy for project cost (capitalise
   work-in-progress across a month-end, or expense on purchase as QuickBooks apparently did) — this
   is a genuine accounting-policy call that blocks completing the chart-of-accounts mapping.
   *(→ 04, §4.1)*
3. **"Fully paid" definition.** Should the receivables "Fully paid"/"Settled" headline continue to
   mean "cash received ≥ quote" (current behaviour), or require every shilling matched to a
   specific invoice first? These can legitimately disagree today. *(→ 05 Part A.5, risk H4)*
4. **Credit notes vs Cost of Sales.** Is it acceptable, as standing policy, that a credit note does
   not yet reverse its share of Cost of Sales — per-job margin stays slightly overstated between a
   credit note and a future fix — or does this need to be prioritized now? *(→ 05 Part A.4)*
5. **Client billing ownership.** Is "Client billing" (invoicing/receivables) meant to be owned and
   staffed by Finance/Accounts day-to-day, or is it really a Projects/Client-Service function Finance
   only reviews? This affects whether relocating its backend controller into Finance is worth doing.
   *(→ 03, ISSUE-4)*
6. **Payroll's place in Finance.** Should Payroll remain a single "Payroll Disbursement" step inside
   the Finance menu, or should Finance staff have visibility into the fuller HR payroll engine
   (runs, payslips, tax bands)? *(→ 03, §2.5)*
7. **Accounts/Admin headcount.** Segregation of duties for every Finance transaction type currently
   depends on having ≥2 distinct people active in the `Accounts`/`Admin` roles at all times, not on
   role boundaries. How many people actually hold these roles today, and is that expected to stay
   ≥2 permanently? *(→ 07, §14, risk H9)*
8. **Expenditure-exception authority.** Who should hold `FINANCE_EXPENDITURE_EXCEPTION_APPROVE`
   (authorizing a project to spend beyond its approved budget) — currently only a Super Admin
   account can do this at all? *(→ 07, §14)*
9. **Payroll's GL-posting authority.** Is it acceptable that HR, not Accounts/Finance, holds the
   authority to post payroll's journal entries to the general ledger? *(→ 07, §14)*
10. **Petty-cash wipe endpoint.** Is there a legitimate business reason to keep a full petty-cash
    data-wipe endpoint (`clear-all`) reachable in production at all, or should it be removed/
    converted to an offline tool? *(→ 07, §16, risk C6)*
11. **Approver-reject workflow.** Is a formal "approver rejects a Payment Voucher" workflow
    (distinct from the requester withdrawing it) actually needed, or is the current design —
    approve, or ask the requester to cancel — intentional and sufficient? *(→ 07 §22.3.a; risk M22)*
12. **Labour cost blind spot.** Every project's reported margin currently **excludes labour cost
    entirely** because labour is only tracked at the company/department level, not per job. Is
    management aware current project margin figures are systematically overstated, and is starting
    to capture labour against jobs (timesheets or crew-day allocation) a priority? *(→ 05 Part E.3,
    risk H7)*
13. **Portfolio margin visibility.** Do you currently rely on the cross-project "Budget versus
    actual, every project" screen to identify underperforming jobs? If so, be aware it currently
    cannot — margin is only visible one project at a time. *(→ 05 Part E.5)*
14. **Logistics cost integration.** Should vehicle maintenance and other Logistics-approved costs be
    forced through the same Bill/Cost Collector pipeline as every other supplier cost, closing the
    duplicate-entry gap, or is manual re-entry an accepted control step here? *(→ 08, risk H11)*
15. **Asset depreciation.** Is asset (equipment/vehicle) depreciation and GL valuation something WNG
    wants tracked in this system, or does that stay in the external accounting package permanently?
    *(→ 08)*
16. **Company-wide financial dashboard.** Is a single company-wide financial KPI dashboard (revenue,
    cash, outstanding, at a glance) wanted at all, or is today's model — financial numbers live only
    inside Finance's own reports — acceptable? *(→ 09, §18.1, risk M28)*
17. **Salary advance disbursement process.** For salary advances, who currently decides *how* the
    cash is actually handed to the employee (till, bank transfer, owner's pocket) once HR approves
    the request — and is that decision ever recorded anywhere today outside this ERP? *(→ 05 Part
    C.5, risk H6)*
18. **Payroll single-person operation.** Is it acceptable, as policy, for the same one or two
    Finance/HR staff to hold every step of payroll (prepare, lock, pay) as long as they don't
    personally touch the exact same run twice — or does WNG want a genuine three-different-people
    rule enforced by the system, as with Payment Vouchers? *(→ 05 Part C.4, risk M13)*
19. **Bank position visibility.** Does WNG want a day-to-day "what does the ERP think is in each
    bank account right now" view, given today the ERP only computes a ledger-derived bank balance
    inside a formal reconciliation statement? *(→ 06 Part B.2)*
20. **Equity/opening balances priority.** Now that the ERP is intended to become WNG's real general
    ledger, is recording Opening Balance Equity / Share Capital / Retained Earnings in-system a
    near-term priority, or will equity continue to be tracked outside the ERP for now? *(→ 06 Part
    A.6, risk M16)*

## Finance / Accountant Decisions

21. **Chart-of-accounts mapping sign-off.** Sign off on (or correct) the ~30-account mapping
    proposal already drafted, commented-out, in `config/finance_accounts.php` — specifically the
    eleven control accounts the existing doc says don't yet exist in WNG's chart (raw-material
    inventory, input/output VAT, WHT payable, client deposits, staff/supplier advances, refundable
    deposits, prepayments, leasehold improvements, loans payable). *(→ 04, §4.2, risk C1)*
22. **`2200`/`2110` existence.** Do chart-of-accounts codes `2200` (Client Deposits) and `2110`
    (Output VAT Payable) exist and are they postable in WNG's live chart today? This single fact
    determines whether every invoice issued right now is actually reaching the ledger or silently
    failing at issuance. *(→ 09, §17.3, risk H10 — directly related to Q21/C1)*
23. **`7150 Office Supplies & Stationery`.** Inserted directly into WNG's production chart by a
    migration — is this intended to stay permanently, or should it be retired once its two expense
    codes map to a real account? *(→ 04, §4.2)*
24. **`task_budget_data` status.** Confirm whether the JSON budget capture table is still Finance's
    active planning tool, or has been functionally superseded by `cost_lines` (nature=planned) —
    needed to decide whether it's safe to freeze. *(→ 04, §3.1-I)*
25. **Payment terms.** Does WNG need standard, reusable payment-terms templates (e.g. "Net 30",
    "Due on receipt") on client invoices, or is a free-form due date per invoice sufficient?
    *(→ 05 Part A.3)*
26. **Invoice discounts.** Should client invoices support a distinct, auditable discount line/field,
    or is pricing an already-discounted unit price (current behaviour) acceptable? *(→ 05 Part A.3)*
27. **Overpayment treatment.** When a client overpays and the excess sits unallocated indefinitely,
    what is the required treatment — refund, credit against a future invoice, or leave as-is until
    someone acts? Is there a target age after which an unallocated receipt should be escalated?
    *(→ 05 Part A.3)*
28. **Trial Balance vs Account Summary usage.** Do you use both "Account summary" (General Ledger)
    and "Trial balance" (Financial Reports) regularly, or only one? *(→ 03, ISSUE-3)*
29. **AP ageing reliability.** Is the AP ageing figure on the Reports page one you rely on for
    supplier payment planning, and does it reflect the same numbers as Procurement's own
    bill-ageing view? *(→ 03, ISSUE-13; 09 Q on Payables Ageing)*
30. **Legacy petty-cash permissions.** Are the `_LEGACY` petty-cash permissions (create/update/void)
    still assigned to any active role, or can they be retired? *(→ 03, ISSUE-9)*
31. **PO multi-billing.** Today a Purchase Order can only carry **one** Bill. Does WNG's supplier
    billing practice ever require multiple partial invoices against a single PO (e.g. staged
    deliveries each invoiced separately)? If yes, this is a real functional gap. *(→ 05 Part B)*
32. **Approved-PO edit authority.** Who, by role, should be permitted to edit an **approved**
    Purchase Order's items/amount, and under what circumstances (new PO vs amendment)? The current
    system permits it with no restriction at all. *(→ 05 Part B.7, risk C2/C3)*
33. **Reconciling petty-cash surrender posting.** Can you confirm, from the general ledger, whether
    a recently reconciled petty-cash surrender's expense account was debited once or twice for the
    same receipt? This is directly checkable from existing data without any code change and would
    resolve risk H5. *(→ 05 Part C.1)*
34. **Cash-purchase distinction.** Does Finance need to distinguish "cash purchase at a shop
    counter" from any other cash petty-cash payment for control or reporting purposes, or is
    `payment_method = cash` sufficient as it stands? *(→ 05 Part C.7)*
35. **Fund custodian definition.** Is the "custodian" WNG needs on the Fund Custody dashboard the
    person who *recorded* a top-up (current behaviour), or the person who *physically holds* that
    cash right now — and can those ever legitimately differ in practice? *(→ 05 Part C.1)*
36. **KRA input-VAT evidence sufficiency.** Is a supplier-invoice reference number (separate from
    the eTIMS invoice number) actually required supporting evidence for a defensible input-VAT
    claim, or is the eTIMS number and supplier PIN sufficient on their own? *(→ 06 Part C.3, risk
    M19)*
37. **KRA-appointed VAT withholding agent status.** Is WNG a KRA-appointed VAT withholding agent?
    The system has **no withholding-VAT (WVAT) implementation at all**. If this obligation applies,
    it needs to be built from scratch. *(→ 06 Part C.1)*
38. **Non-resident WHT.** For non-resident supplier payments, the system deliberately withholds
    nothing automatically and does not flag these as an exception anywhere. What is WNG's actual
    non-resident WHT rate/policy, and should the system alert whenever a non-resident supplier is
    paid with no WHT applied? *(→ 06 Part C.1)*
39. **Seeded tax rates.** Please confirm the currently-seeded rates are correct and complete:
    standard VAT 16% (recoverable/non-recoverable), zero-rated, exempt, out-of-scope; resident
    professional/management/training fees WHT at 5%, resident contractual payments at 3%. All are
    effective-dated from 2020-01-01 as an engineering floor, not a legal claim about when each rate
    actually took effect — are the real effective dates known, and should historical rows be
    back-dated? *(→ 06 Part C.1)*
40. **VAT/WHT filing deadline.** Confirm the configured VAT/WHT return due day (currently defaulted
    to the 20th of the following month) matches KRA's current filing calendar for both return
    types. *(→ 06 Part C.2)*
41. **Transport/logistics cost recording.** How is transport/logistics cost currently recorded for a
    project — as its own tagged category, or folded into general petty-cash "expenses"? If there is
    a manual workaround already in use, describe it so it can be assessed against the code.
    *(→ 05 Part E.4, risk M14)*
42. **WHT under-withheld recovery.** When a WHT category is marked "aggregate monthly" and the
    system flags an "under-withheld" exposure because several small payments to one supplier
    crossed the monthly threshold, what is the actual process for recovering that shortfall from
    the supplier, and should this system record that follow-up? *(→ 06 Part C.1)*
43. **Quote entry convention.** Is the current practice for entering a client quote always "type one
    VAT-inclusive total" (the assumption baked into the quote-margin advisory calculation)? If
    quotes are sometimes entered net-of-VAT instead, that advisory signal is wrong in the opposite
    direction. *(→ 05 Part E.7)*
44. **Value-based voucher approval.** Should spend/payment vouchers have a value-based second-
    approval threshold (e.g., anything above KES X needs a second sign-off beyond the current single
    approver)? *(→ 07, §13.1, risk LM9)*
45. **Voucher rejection procedure today.** When an approver disagrees with a spend voucher, what
    should happen procedurally today, given there is no "reject" action available to them? *(→ 07,
    §13.1)*
46. **Accounts role tiering.** Should `Accounts` be split into a "clerk" tier (create/record only)
    and a "lead" tier (approve/post/reverse/close periods), to make segregation of duties a
    permission boundary rather than a same-person runtime check? *(→ 07, §14, risk H9)*
47. **"Outstanding" figure authority.** When you read "Outstanding" on the Financial Reports (AR
    Ageing) screen versus "Outstanding" on the Client Invoices & Receivables screen, which do you
    currently treat as the authoritative client-owes-us figure? *(→ 09, §17.3, risk H10)*
48. **Pre-gate bills reconciliation.** For Payables Ageing: are there supplier bills predating the
    Purchase-to-Pay gate that you know are not fully reflected in the ledger's Accounts Payable
    balance, even though they show correctly in the Payables Ageing report? *(→ 09, §17.1)*
49. **Petty-cash advance posting failure handling.** When a petty-cash advance is disbursed but its
    GL journal fails to post, what should happen operationally — hold the disbursement, or let it
    proceed and flag it for same-day correction? Today it silently proceeds with only a server log.
    *(→ 06 Part B.3, risk C5)*
50. **Reconciliation match history.** Is it acceptable that re-matching, unmatching, or ignoring a
    bank statement line permanently erases the record of its previous match, or is that history
    something an external auditor/KRA review would expect to see? *(→ 06 Part B.5, risk H8)*
51. **Inter-account transfers.** Does Finance ever need to move cash directly between two
    WNG-held accounts (e.g., bank to mobile money) as a single "transfer" transaction, or is
    recording it as two separate movements sufficient? *(→ 06 Part B.1)*
52. **Bill/PO evidence attachment.** For KRA input-VAT defensibility: is it acceptable today that
    supplier bills and purchase orders carry no attached invoice scan/PDF? If an auditor asks to
    see the original invoice image, can Finance currently produce it from outside the ERP, or does
    WNG need the ERP itself to hold that file? *(→ 05 Part F.2, risk M25)*

## SOP / Process Decisions

53. **Payment channel visibility.** When a supplier invoice is paid through a linked petty-cash
    requisition versus paid directly from the bank, are the same people expected to see both in one
    place today, or are they treated as separate workflows by different staff? *(→ 05 Part D.1)*
54. **Unreachable spending entry point workaround.** When a purchase order is the right way to pay
    (per `SpendEntryView`'s own text), what is the actual first click a staff member makes today,
    given that page is unreachable from the menu? *(→ 03, ISSUE-1, risk H1)*
55. **Petty Cash "Project Budgets" tab removal.** Was this removal (noted in-code) communicated to
    end users, and did anyone push back on losing that breakdown? *(→ 03, §2.4)*
56. **Work-queue reassignment.** Should the Finance work-queue's `reassign` capability (currently
    backend-only, Admin-restricted, no UI) be exposed to Finance staff/leads, or should
    reassignment stay a manual Admin action outside the app? *(→ 07, §13.4, risk LM7)*
57. **Single-person payroll fallback.** If WNG's HR function is ever a single person, how should a
    payroll run be locked and paid — via a Super Admin self-approval override every time, or should
    a second person (even outside HR) be designated as the payroll checker? *(→ 07, §13.5, risk M13)*
58. **Logistics-to-Finance reconciliation.** Is there a current manual process for reconciling an
    "approved" Logistics maintenance log against the eventual Procurement bill/payment? If so, how
    often is it actually performed? *(→ 08, risk H11)*
59. **Fund custody exception follow-up.** How often is the Fund Custody dashboard's self-reported
    "reconciliation difference" actually investigated when it appears? *(→ 09, §18.2)*

## Technical Decisions

*(For WNG's IT/dev lead to prioritize — not business decisions, but engineering choices that gate
Phase 2 sequencing.)*

60. **Fix priority.** Confirm the priority to fix the two CRITICAL Purchase-Order findings
    (no post-approval edit guard, commented-out delete guard with cascading deletes reaching paid
    Bills) and the account-code/`ChartAccountMap` bypass (risk C1), ahead of lower-severity
    numbering-race and duplicate-reference items. *(→ 05 Part B.7; 04 §4.2)*
61. **Deprecated posting paths.** Is there a known, in-progress migration plan for
    `postPayment()`/`postSpendVoucher()`/`postSupplierPayment()`'s deprecated-vs-dead status, and
    what is the intended single end-state posting path? *(→ 04, ISSUE-11)*
62. **Settlement-link authority.** Decide whether `spend_voucher_allocations` or
    `payment_allocations` is the authoritative settlement-link table going forward. *(→ 04, §4.4)*
63. **Migration folder discovery.** Is `PettyCash/Database/Migrations/` still discovered by the
    module's migration loader, or is it legacy/dead? *(→ 03, ISSUE-10)*
64. **Bill controller `update()`.** Does `BillController` genuinely have no `update()` route
    handler as flagged by a prior internal review — deliberate immutability rule, or a gap that
    should get a real handler (or explicit route removal)? *(→ 06 Part D.4, risk LM1)*
65. **Raw-SQL bypass check.** Confirm whether any code path writes directly to
    `journal_entries`/`journal_lines` via raw SQL outside `JournalPostingService`, to close the
    residual assumption that period-close enforcement covers every writer. *(→ 07, §15)*
66. **Bill verification permission migration.** Should `BillController::canVerify()` be migrated
    from its hard-coded role array to a dedicated `Permissions::` constant? *(→ 07, §13.3, risk M21)*
67. **Payroll permission split.** Should `HR_MANAGE_PAYROLL` be split into stage-specific
    permissions (prepare/lock/pay)? *(→ 07, §13.5, risk M13)*
68. **Labour-attribution roadmap.** Is there a plan (or existing ticket) for timesheet/crew-
    assignment capture that would let labour be attributed to a specific project — the prerequisite
    for closing the labour-cost blind spot (risk H7)? *(→ 05 Part E.3)*
69. **Portfolio margin computation approach.** Should the portfolio cost-accounts view compute
    margin per row for the whole portfolio in one query, or is a lazier per-row lookup acceptable
    given current project volumes (affects pagination performance)? *(→ 05 Part E.5)*
70. **`grandTotal` reliance elsewhere.** Confirm whether any other financial report or export
    relies on `task_budget_data.budget_summary.grandTotal` as an authoritative figure, beyond the
    two consumers already found. *(→ 05 Part E.6)*
71. **Queue worker confirmation.** Confirm the production `QUEUE_CONNECTION` setting and whether a
    queue worker process is actually running — `ProcessStoresFinancePosting` assumes synchronous,
    no-worker execution; if that assumption stops holding, Stores cost postings could silently
    queue up unprocessed. *(→ 08)*
72. **VAT schedule cross-footing.** Is there an existing or planned reconciliation job that
    cross-foots the VAT Input/Output Schedules against the Trial Balance's own VAT account
    balances? *(→ 09, §17.5)*
73. **Petty-cash project report source.** Should `PettyCashReportService::generateProjectReport()`'s
    per-project total be replaced by a read from the unified Cost Collector ledger, if it isn't
    already sourced from there? *(→ 09, §17.5)*
74. **Reconciliation-of-reconciliations.** Is a scheduled job comparing `PettyCashBalance.
    current_balance` against the GL's petty-cash/staff-advance account balance something Finance/
    Engineering want built now, or is manual recalculation sufficient at current transaction
    volume? *(→ 06 Part B.3, risk C5)*
75. **Retroactive payroll `Payment` backfill.** Should the fix for payroll's missing `Payment`
    record (risk C7) also apply retroactively — backfilling `Payment` rows for already-paid
    historical `PayrollRun`s — or only prospectively from the next pay run? *(→ 05 Part D.3)*
76. **Money-movement table consolidation appetite.** Is there appetite to consolidate `Payment`,
    `BillPayment`, `ClientReceipt`, and `CashMovement` toward one canonical money-movement table
    over time, or is keeping four purpose-specific tables an acceptable permanent design?
    *(→ 05 Part D.1)*
77. **Ledger source filter scope.** Should the ledger screen's "source" filter be expanded to match
    all real posting sources, or is the current two-option filter intentionally scoped to what
    Finance actually searches by day-to-day? *(→ 06 Part A.5, risk M17)*
78. **Balanced-entry funnel gap.** Was there an intentional reason `postSupplierInvoice()` and
    `reverseEntry()` were left outside the `postBalancedEntry()` funnel (risk C4), e.g. a migration
    in progress — or should this be scheduled as a fix given it is the one gap that could
    theoretically let an unbalanced entry reach the ledger? *(→ 06 Part A.2)*

---

*Every question above traces back to a specific FACT/ISSUE in the section documents referenced.
Phase 2 should not proceed on any REQUIRES WNG CONFIRMATION item in `11_KEEP_REDESIGN_MERGE_
REMOVE_MATRIX.md` until the corresponding question here is answered.*
