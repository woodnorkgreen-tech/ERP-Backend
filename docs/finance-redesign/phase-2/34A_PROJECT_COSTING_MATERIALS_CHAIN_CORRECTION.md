# 34A — Project Costing: Materials Chain Correction

**Addendum to:** `34_PHASE_2B_W6_PROJECT_COSTING_DECISION_ARCHITECTURE.md`, §6 ("Procurement & Supplier Billing Integration"), step 4.
**Raised by:** Report 60 (Stream C), §§ on "Bill → ACTUAL" and Known Issues item 1.
**Written with:** Report 61 (Stream D), 2026-09-29.
**Status:** Correction of a documentation statement. No code change was needed: the code already does what this addendum describes, and the tests below prove it.

Report 34 is left unchanged as the historical record. This addendum supersedes §6 step 4 and the third box of the §6 diagram.

---

## 1. What Report 34 says

> 4. **Supplier Bill Verification (Three-Way Match):** Converts `ACCRUED` to `ACTUAL` (or directly from `COMMITTED` for services), stamps `settled_by_bill_id`, and posts GL clearing of Cr 2020 to Cr 2000 AP.

It also draws the chain as `Approved PO → COMMITTED`, `GRN → ACCRUED`, `Verified Supplier Bill → ACTUAL`.

## 2. Why that is wrong

If verifying a PO-backed bill created an ACTUAL project cost, the same material would be charged to the job twice. The first charge would come at bill verification. The second would come when Stores issues the material to the job, which already records ACTUAL cost (`StoresCostProducer`, `source_ref = stock-issue`). The implemented W6 chain avoids this by recognising ACTUAL only at the Stores issue.

## 3. The correct chain, for PO-backed stock/material purchases

| Event | Project cost (Cost Collector) | Ledger |
|---|---|---|
| PO approved | **COMMITTED** line (encumbrance) | none |
| GRN accepted | **ACCRUED** line; the COMMITTED line it consumes is relieved | Dr Inventory (ref. 1200; WNG `IA-001`) / Cr Accrued / GRNI (ref. 2150) |
| Stores issue to a project | **ACTUAL** line, once; the matching ACCRUED line is retired (`releaseAccrual`, matched by `details.library_material_id`) | Dr the expense code's debit account (Project WIP under `capitalise`) / Cr Inventory |
| Stores return from a project | Negative ACTUAL (legs swapped) | Dr Inventory / Cr WIP |
| Supplier bill verified (three-way match) | **No new cost.** The accrual is stamped `settled_by_bill_id` | Dr Accrued (2150) / Cr Accounts Payable |
| Supplier payment | **No cost.** | Dr Accounts Payable / Cr the paying account |

In short:

- **PO → COMMITTED.**
- **GRN → ACCRUED.**
- **Stores issue → ACTUAL.**
- **Bill verification** settles the procurement accrual and AP relationship and creates no second ACTUAL project cost.
- **Payment** settles AP and creates no project cost.

## 4. Direct supplier bills are different

A **direct bill** has no PO, so no commitment, receipt or Stores issue stands behind it. Once verified, it becomes the project's **ACTUAL** analytical cost **exactly once**, posted to its own expense code rather than to the accrual. The bill *is* the cost event.

## 5. Evidence (implementation and tests)

| Claim | Implementation | Test(s) |
|---|---|---|
| PO → COMMITTED, GRN → ACCRUED, bill adds no second cost, payment adds none | `ProcurementCostProducer`, `BillController::verify` | `PayablesWorkspaceTest::test_po_commits_grn_accrues_bill_adds_no_second_cost_and_payment_adds_none` |
| GRN lands in Inventory, not WIP | `JournalPostingService::postCostLine` (accrual legs) | `SettlementAccountTest::test_goods_received_land_in_stock_not_in_project_wip`, `::test_goods_received_but_not_invoiced_becomes_a_liability` |
| Issue relieves Inventory; WIP is debited once across receipt and issue | `StoresCostProducer` (`stock-issue`) | `SettlementAccountTest::test_a_stores_issue_relieves_inventory_not_the_bank`, `::test_the_full_material_cycle_touches_project_wip_exactly_once`, `::test_a_stores_return_puts_the_value_back_into_inventory` |
| Issue retires the receipt accrual | `CostCollectorService::releaseAccrual` | `SettlementAccountTest::test_issuing_material_retires_the_receipt_accrual` |
| Bill verification moves the accrual to AP and closes it | `BillController::verify`, `settled_by_bill_id` | `SupplierLedgerRailTest::test_verifying_a_matched_invoice_moves_the_accrual_onto_accounts_payable`, `::test_verifying_a_bill_closes_the_grn_accrual_it_supersedes`, `::test_verification_posts_once_however_many_times_it_runs` |
| Payment relieves AP, adds no cost | Bill payment posting | `SupplierLedgerRailTest::test_paying_a_posted_invoice_relieves_the_payable_and_credits_the_source`; `PayablesWorkspaceTest::test_partial_then_full_payment_settles_the_bill_and_adds_no_project_cost` |
| Verified direct bill → ACTUAL once | `BillController::recordDirectBillCost` | `PayablesWorkspaceTest::test_a_verified_direct_bill_becomes_the_projects_actual_cost_once`; `SupplierLedgerRailTest::test_a_direct_bill_posts_to_its_own_expense_code_not_the_accrual` |
| Finance can read the chain | `InventoryFinanceController` (Report 61) | `InventoryFinanceTest` |

## 6. Open edge case: service and non-stock lines received through a GRN

A PO line with **no catalogue material** (a service, or a non-stock item forced through a GRN) creates an ACCRUED line with no `library_material_id`. As a result:

- no Stores issue can ever match it, so it is **never retired to ACTUAL**;
- its value is debited to the **Inventory** account and **stays there**, although nothing is on the shelf;
- bill verification still settles the liability side (Dr 2150 / Cr AP), but the Inventory debit remains.

Report 61 reports these lines separately as "non-stock receipts". They appear as a named contributor to the Stores-vs-ledger inventory difference and as the "non-stock" part of "received, not yet issued". The screens report them; they do not treat them as stock.

**How to treat them is not decided here.** Options include a service confirmation that retires the accrual to ACTUAL, or posting non-stock receipts straight to the expense/WIP account. That choice is a WNG/accountant decision, tied to W2-8 (Service Confirmation), for a later controlled task.

In the rehearsal target database (read-only, Report 61 §48), both GRN accruals (KES 13,000) are of this kind: rehearsal PO lines with no catalogue material.
