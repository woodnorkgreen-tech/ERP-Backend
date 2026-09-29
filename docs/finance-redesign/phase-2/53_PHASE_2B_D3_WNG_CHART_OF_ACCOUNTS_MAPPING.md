# Report 53 — Phase 2B: D3 WNG Chart of Accounts Decision & Mapping

**Date:** 2026-09-28
**Status:** D3 ARCHITECTURE DECISION = **CONFIRMED** · D3 ACCOUNT MAPPINGS = **PENDING ACCOUNTANT APPROVAL**
**Predecessor:** Report 52 (real-data rehearsal, PARTIAL: blocked by D3)
**Appendices:** 53A (expense-code proposal, 109 codes) · 53B (full audit of all 123 accounts)
**Machine-readable proposal:** `database/finance/wng-coa-mapping-proposal.json`
**Evaluator:** `php artisan finance:account-mapping` (read-only)

Nothing in this report touched the live source (`woodnork_erpsystem`) or the live target (`woodnork_erp`). All chart evidence comes from the local rehearsal copy `wng_source_rehearsal`, which was restored from the Report 52 snapshot. There were no postings, no opening balances, no cutover, no queue workers, no W8 and no frontend redesign.

---

## 1. Executive Summary

WNG chose **Option A**: keep its existing chart of 120 QuickBooks accounts. The redesigned Finance backend, workflows, controls, reporting and UI all stay. Finance now adapts to the chart through a **semantic account map**. The chart is not renumbered and reference codes are not forced into it.

This report delivers:

- **A registry of every account the redesign needs.** `FinanceAccountFunctions` defines 37 posting functions. Each one records its reference code, its meaning and the workflows that use it. Every hard-coded account constant in the posting services now points at the registry.
- **A mapping proposal for the WNG chart.** There are 37 functions, 15 extra catalogue reference accounts and 9 paying accounts. Each one is classified with the five required labels. **None is approved.**
- **A read-only evaluator** (`finance:account-mapping`). It checks the proposal against a real chart and derives the expense-code proposal. It emits config lines **only** for approved entries.
- **Three map bypasses fixed.** Before this change, the WNG chart would have been silently mis-posted or mis-linked by these:
  - the uncoded-cost fallback, which picked the first expense account by code (on WNG, **ADM-001**);
  - `PaymentSourceSeeder`, which bypassed the map and **wiped links set by Finance**;
  - `FinanceTaxSeeder`, which had the same fault.
- **Readiness now covers all 37 functions.** Before, it checked a hard-coded list of 13.
- **R-1 and R-3 are implemented.** R-2 stays open, and custody is assigned to no role.
- **D2 is closed.** There is no historical PO/GRN/Bill data to migrate. Orphans and schema narrowings are recorded exactly as far as the evidence supports.

**The central finding is that the chart WNG exported is incomplete.** The 120 accounts contain no income account (other than Return Inwards), no VAT, no WHT, no payroll liabilities, no inventory asset, no accruals and no client deposits. Yet WNG's own petty-cash history posts to `Sales:Printing`, `Payroll liabilities:Net Pay`, `Payroll liabilities:Helb Repayment`, `SHIF`, `Inventory Asset` and `Direct Labor`. None of these is among the 120. So QuickBooks holds accounts that were never exported. **The accountant must supply the full chart export before most ACCOUNTANT_DECISION items can be closed.**

## 2. Confirmed D3 Decision

| Item | Decision |
|---|---|
| Chart | **WNG's existing chart is retained** (Option A). The accounts stay unchanged: no renumbering, merging or deletion. |
| Finance | The redesigned backend, workflows, controls, reporting and UI are kept. |
| How Finance and the chart meet | Finance posts by **function**. `ChartAccountMap::local()` translates the function's reference code into WNG's code using the map in `config/finance_accounts.php`. |
| Approval authority | Account-level mappings are the **accountant's** call. Engineering proposes them; nothing is active until approved. |
| Status | ARCHITECTURE CONFIRMED · MAPPINGS PENDING ACCOUNTANT APPROVAL |

## 3. Chart of Accounts Audit

The full per-account audit is in **Appendix 53B**. It covers all 123 accounts with code, name, category, the parent implied by the name, flags, origin, use in history and the proposed mapping.

- **123 accounts in the rehearsal chart.** 120 are WNG QuickBooks mnemonic codes, all created 2026-03-06. The other 3 were **inserted by target migrations**, not by WNG:
  - `2160` Net Payroll Payable and `7550` Salaries & Wages, from `2026_08_24_000001_link_payroll_runs_to_finance`;
  - `7150`, from `2026_09_07_000002_widen_the_non_project_purchase_catalogue`.
- **Code families:**
  - AR-*, AP-*, and banks: CASH, PETTY, EQB, NCBA, STB, KCB, FMB, FK, NIC, SBM;
  - STD-001 Short Term Debtors;
  - equity: OBE, RE, SC, DIV;
  - COS-001..020, OPE-001..032, PE-001..007, FIN-001..005, ADM-*, INS-*, UTIL-*, BD-001, INV-001, VP-001;
  - RI-001, the **only** revenue account.
- **Metadata is absent.** `account_type`, `normal_balance` and `parent_id` are empty on all 120 WNG accounts. The hierarchy exists **only in the names** (for example "Cost of Sales:Materials").
- **The ERP makes no use of the chart.** No source table references `chart_of_accounts`. `posting_rules` is empty. The only evidence of use is the free-text `account` field on petty-cash history, which Report 52 excluded from migration.

## 4. Redesigned Finance Account Requirements

The **37 posting functions** are defined in `app/Modules/Finance/Support/FinanceAccountFunctions.php`. Each function records its reference code, meaning, callers and workflows.

| Group | Functions (reference code) |
|---|---|
| Cash & receivables | bank_default 1010, petty_cash_float 1030, accounts_receivable 1100, staff_advances 1300, input_vat 1330 |
| Stock & WIP | inventory 1200, wip_direct_materials 1211, wip_direct_labour 1212, wip_subcontractors 1213, wip_transport_logistics 1214, wip_equipment_site 1215, wip_project_utilities 1216, wip_project_facilitation 1217, wip_venue_statutory 1218, wip_rework_warranty 1219 |
| Liabilities | accounts_payable 2100, output_vat 2110, wht_payable 2120, paye_payable 2130, statutory_payable 2140, accrued_expenses 2150, net_payroll_payable 2160, client_deposits 2200 |
| Equity | opening_balance_equity 3900 |
| Revenue | project_revenue 4100 |
| Cost of sales (WIP release) | cos_direct_materials 5100 … cos_rework_warranty 5900 (9 functions) |
| Expense | inventory_adjustments 6800, salaries_expense 7550, bank_charges 7800 |

The expense catalogue also names **15 reference accounts** that no posting service hard-codes:

- assets and liabilities: 1310, 1320, 1340, 1600, 2300;
- overheads: 6100, 6200, 6400, 6600, 6700, 7100, 7150, 7200, 7400, 7600.

These accounts are reached only through an expense code's `default_debit_gl`.

`UNCODED_COST_FALLBACK` is set to `wip_direct_materials`. It is the account a cost with no expense code falls back to. Before this change, the code picked the first expense account it found.

## 5. Mapping Table

The status of every row is **proposed**, and no row is approved. "(cand.)" marks a candidate that still needs confirmation.

| Function | Ref | Proposed WNG account | Classification |
|---|---|---|---|
| bank_default | 1010 | EQB-001 Equity Bank | SUITABLE EXISTING |
| petty_cash_float | 1030 | PETTY-001 Petty cash | EXACT MATCH |
| accounts_receivable | 1100 | AR-001 Accounts Receivable (A/R) | EXACT MATCH |
| staff_advances | 1300 | new sub-account under STD-001 Short Term Debtors | NEW SUB-ACCOUNT |
| input_vat | 1330 | — (not in export) | ACCOUNTANT DECISION |
| inventory | 1200 | — (QuickBooks "Inventory Asset", not exported) | ACCOUNTANT DECISION |
| wip_direct_materials | 1211 | COS-008 (cand.) | ACCOUNTANT DECISION (WIP timing) |
| wip_direct_labour | 1212 | PE-007 (cand.) | ACCOUNTANT DECISION (WIP timing) |
| wip_subcontractors | 1213 | COS-020 (cand.) | ACCOUNTANT DECISION (WIP timing) |
| wip_transport_logistics | 1214 | COS-016 (cand.) | ACCOUNTANT DECISION (WIP timing) |
| wip_equipment_site | 1215 | — | ACCOUNTANT DECISION |
| wip_project_utilities | 1216 | — | ACCOUNTANT DECISION |
| wip_project_facilitation | 1217 | COS-006 (cand.) | ACCOUNTANT DECISION (WIP timing) |
| wip_venue_statutory | 1218 | — | ACCOUNTANT DECISION |
| wip_rework_warranty | 1219 | COS-018 (cand.) | ACCOUNTANT DECISION (WIP timing) |
| accounts_payable | 2100 | AP-001 Accounts Payable (A/P) | EXACT MATCH |
| output_vat | 2110 | — | ACCOUNTANT DECISION |
| wht_payable | 2120 | — | ACCOUNTANT DECISION |
| paye_payable | 2130 | — (QuickBooks "Payroll liabilities:*", not exported) | ACCOUNTANT DECISION |
| statutory_payable | 2140 | — | ACCOUNTANT DECISION |
| accrued_expenses | 2150 | — | ACCOUNTANT DECISION |
| net_payroll_payable | 2160 | — ("Payroll liabilities:Net Pay"); the 2160 row in the chart comes from a migration | ACCOUNTANT DECISION |
| client_deposits | 2200 | — | ACCOUNTANT DECISION |
| opening_balance_equity | 3900 | OBE-001 Opening Balance Equity | EXACT MATCH |
| project_revenue | 4100 | — ("Sales:*", not exported) | ACCOUNTANT DECISION |
| cos_direct_materials | 5100 | COS-008 Cost of Sales:Materials (cand.) | SUITABLE EXISTING |
| cos_direct_labour | 5200 | PE-007 Wages-Direct Labour (cand.) | SUITABLE EXISTING |
| cos_subcontractors | 5300 | COS-020 Subcontractors - COS (cand.) | SUITABLE EXISTING |
| cos_transport_logistics | 5400 | COS-016 Transport & Delivery (cand.) | SUITABLE EXISTING |
| cos_equipment_site | 5500 | — | ACCOUNTANT DECISION |
| cos_project_utilities | 5600 | — | ACCOUNTANT DECISION |
| cos_project_facilitation | 5700 | COS-006 Field Facilitation (cand.) | SUITABLE EXISTING |
| cos_venue_statutory | 5800 | — | ACCOUNTANT DECISION |
| cos_rework_warranty | 5900 | COS-018 Other - COS (cand.) | SUITABLE EXISTING |
| inventory_adjustments | 6800 | INV-001 Inventory Shrinkage (cand.) | SUITABLE EXISTING |
| salaries_expense | 7550 | PE-006 Personnel Expenses:Salaries (cand.) | SUITABLE EXISTING |
| bank_charges | 7800 | FIN-003 Bank charges (cand.) | SUITABLE EXISTING |

**Function totals:** 4 EXACT, 10 SUITABLE, 1 NEW SUB-ACCOUNT, 22 ACCOUNTANT DECISION.

**Catalogue reference accounts:**

| Ref | Meaning | Proposed WNG account | Classification |
|---|---|---|---|
| 7200 | Administration airtime & internet | OPE-031 | EXACT MATCH |
| 7600 | Staff welfare | OPE-029 | EXACT MATCH |
| 6100 | Workshop electricity | OPE-012 (cand.) | SUITABLE EXISTING |
| 6200 | Machinery repairs & maintenance | OPE-023 (cand.) | SUITABLE EXISTING |
| 6400 | Small tools & consumables | OPE-006 (cand.) | SUITABLE EXISTING |
| 6700 | Cleaning & waste disposal | OPE-014 (cand.) | SUITABLE EXISTING |
| 7100 | Office rent | OPE-022 (cand.) | SUITABLE EXISTING |
| 7150 | Office supplies & stationery | OPE-030 (cand.) | SUITABLE EXISTING |
| 7400 | Office transport | OPE-032 (cand.) | SUITABLE EXISTING |
| 6600 | PPE & safety | — (nearest OPE-006) | ACCOUNTANT DECISION |
| 1310, 1320, 1340, 1600, 2300 | Supplier advances, deposits, prepaid, leasehold capex, loans | — | ACCOUNTANT DECISION |

**Paying accounts.** These are CONFIGURATION CHANGE ONLY: each one is set on the payment source's `gl_account_id`, not in the code map.

| Source | Proposed account |
|---|---|
| PC-MAIN | PETTY-001 |
| BANK-MAIN | EQB-001 |
| BANK-ALT | NCBA-001 |
| BANK-STANBIC | STB-001 |
| BANK-KCB | KCB-001 |
| BANK-FAMILY | FMB-001 |
| AP | AP-001 |
| MPESA | ACCOUNTANT DECISION |
| CARD | ACCOUNTANT DECISION |

Four WNG cash accounts have no paying source: CASH-001, FK-001, NIC-001 (USD) and SBM-001.

## 6. Exact Matches

These rows need only the accountant's sign-off and one map line each:

- petty_cash_float → PETTY-001
- accounts_receivable → AR-001
- accounts_payable → AP-001
- opening_balance_equity → OBE-001
- catalogue 7200 → OPE-031
- catalogue 7600 → OPE-029

## 7. Suitable Existing Accounts

These rows need the accountant to confirm the meaning. Points to check:

- **bank_default → EQB-001:** confirm Equity is the operating account.
- **bank_charges → FIN-003:** WNG books FIN-005 M-Pesa charges separately. The redesign posts all fees to one account. The accountant should confirm that, or split fees by paying account (a small follow-up change).
- **inventory_adjustments → INV-001 "Shrinkage":** count **gains** would also post here. Confirm one account for both directions.
- **salaries_expense → PE-006:** the `7550` row in the chart comes from a migration and duplicates PE-006.
- **cos_\* WIP-release targets:** these only matter if WIP is kept (§9).
- **Catalogue overheads (6100–7400):** the reference chart treats workshop electricity and repairs as **production overhead**, while WNG books them as opex. This affects gross margin.

## 8. New Sub-Accounts Recommended

- **staff_advances → a new "Staff Advances" account under STD-001 Short Term Debtors.** Mixing staff imprest with trade debtors would hide what staff owe.

Nothing is created. Creating the account is WNG's choice, done in QuickBooks and then in the ERP chart.

## 9. Accountant Decisions Required

1. **The full QuickBooks chart export.** It must include revenue ("Sales:*"), VAT, WHT, payroll liabilities ("Payroll liabilities:*", SHIF), "Inventory Asset" and "Direct Labor". Most items below depend on it.
2. **VAT status.** Is WNG VAT-registered? If so, name the input and output VAT control accounts. VP-001 VAT Penalty suggests it is registered.
3. **WHT payable account.**
4. **Payroll liabilities:** PAYE, statutory deductions (NSSF, SHIF, housing levy) and net pay.
5. **Inventory treatment:** perpetual stock with an inventory asset, as the redesign does, or expense on purchase?
6. **WIP timing** (the largest decision). Should project cost sit in WIP until job completion, as the redesign does, or hit cost of sales on capture?
   - If WIP is kept, WNG needs WIP asset accounts.
   - If WIP is not kept, the WIP functions map to the COS candidates.
   - This changes **when** cost reaches the P&L. It affects 86 expense codes (43 on 1211 alone).
7. **Accrued expenses / goods received not invoiced**, used by GRN accruals and voucher allocation.
8. **Client deposits:** are they held as a liability until invoiced?
9. **Project revenue account(s).**
10. **Cost-of-sales equipment, utilities and venue/statutory costs.** No such accounts exist today.
11. **Balance-sheet catalogue accounts:** 1310, 1320, 1340, 1600 and 2300. There are no fixed-asset accounts, although ADM-003 depreciation exists. FK-001 Faulu is recorded as an **asset**; is it actually a loan?
12. **Paying accounts:** the MPESA settlement account and the CARD settlement account.
13. **P&L sectioning.** Approve the `account_type` backfill proposal:
    - COS-* and PE-007 as `direct_cost`;
    - OPE-026 Set Up Casuals: confirm;
    - everything else as `opex`.

## 10. Hard-Coded Account Assumptions — Inventory & Refactor

| Location | Before | After |
|---|---|---|
| `ReceivablesPostingService` | literal 1100/4100/2110/2200 | registry constants (values unchanged), resolved through the map |
| `StockMovementPostingService` | 1200/6800/3900 | registry constants |
| `PayrollFinancePostingService` | 7550/5200/2130/2140/2160 | registry constants |
| `JournalPostingService` | 1330/2120/1200/2150/2100/1300/7800/1030/1010 literals | registry constants |
| `JournalPostingService` uncoded fallback | **first expense account by code** (on WNG: ADM-001, an arbitrary admin account) | `UNCODED_COST_FALLBACK` (1211) through the map. If that is unmapped, the account is null, so the posting is **refused** with a clear message. It never picks an arbitrary account. |
| `SpendVoucherController` | literal 2100/2150 | registry constants |
| `WorkInProgressReleaseService::RELEASE_MAP` | literal pairs | registry constants |
| `PaymentSourceSeeder` | looked up the reference code **directly**, bypassing the map, and **overwrote** `gl_account_id` with null on a foreign chart | resolves through `ChartAccountMap::local()`; keeps an existing link when it cannot resolve |
| `FinanceTaxSeeder` | same bypass and wipe | same fix |
| `FinanceReadinessController` | hard-coded list of 13 codes | all 37 functions from the registry; each missing one is named "key (ref → local)" |
| `config/finance_accounts.php` | identity entries (1030→1030 …) that looked like decisions | removed and replaced by a pointer to the proposal. The map is now empty, which behaves identically. |
| `SimulateFinanceWorkflowsCommand` | literal codes | **left as-is**: a dev-only simulator, never run against WNG data |
| Migrations `2026_08_24_000001`, `2026_09_07_000002` | insert 2160/7550/7150 into **any** chart | **reported, not changed.** Editing a shipped migration is unsafe. The inserted rows are harmless but duplicate WNG accounts (§12). The accountant may deactivate them after mapping. |

## 11. Mapping Architecture

```
posting service ──uses──▶ FinanceAccountFunctions::X  (reference code, meaning, workflows)
                               │
                               ▼
                 ChartAccountMap::local(ref) ──▶ config('finance_accounts.map')[ref] ?? ref
                               │
                               ▼
                 chart_of_accounts row (must exist, be postable and active) ── else REFUSE
```

- **Proposal:** `database/finance/wng-coa-mapping-proposal.json`. Every entry has a status of `proposed`.
- **Evaluator:** `php artisan finance:account-mapping [--connection=] [--output=] [--emit-map]`.
  - It is read-only.
  - It checks that every proposed or candidate account exists, is postable and is active in the chart.
  - It derives the expense-code proposal.
  - `--emit-map` prints config lines for **approved entries only**. Today it prints "No approved entries".
- **Readiness:** `/finance/readiness` lists every function that does not resolve. It blocks go-live readiness until all 37 do.
- **Payment sources and tax records** carry their own `gl_account_id`. Those are configuration, set on the Finance screens, and the seeders no longer overwrite them.
- **Approval path:**
  1. The accountant marks entries `approved` in the proposal.
  2. `--emit-map` produces the lines.
  3. An engineer adds the lines to `config/finance_accounts.php`.
  4. Readiness goes green.

  No step infers an approval.

Evaluator result on the rehearsal chart:

- every proposed or candidate account exists; 0 problems;
- **2/37 functions resolve today**, and only by accident, through the migration-inserted 2160 and 7550;
- 0 entries approved.

## 12. COA Data-Quality Findings (reported only; no account modified)

1. **The export is incomplete.** These 8 names are used in history but missing from the chart: Direct Labor, Inventory Asset, Office Improvement, "Operating Expenses:Garbage Collection" (compare OPE-014 "Garbage Collections"), Payroll liabilities:Helb Repayment, Payroll liabilities:Net Pay, Sales:Printing, SHIF.
2. **No account metadata.** 99 expense accounts have no `account_type`, so the P&L would show them all as *unclassified*. No account has a `normal_balance` or `parent_id`.
3. **Hierarchy lives only in names.** 56 accounts encode their parent in the name.
4. **Postable parents.** 5 parent accounts are postable: ADM-001, COS-002, FIN-001, OPE-001 and PE-002. Postings can land on a header.
5. **Duplicates:**
   - OPE-010 and OPE-011 ("Dues subacriptions" and "Dues subscriptions");
   - BD-001 and FIN-002 (both bad debts);
   - migration-created 7150 and 7550 duplicating OPE-030 and PE-006.
6. **Foreign-currency accounts** AR-002 (EUR) and NIC-001 (USD) exist, but the ERP is KES-only.
7. **FK-001 Faulu Kenya is classed as an asset.** Verify whether it is a loan.
8. **Depreciation (ADM-003) exists with no fixed-asset account.**

## 13. Expense-Code Proposal

The full list is in **Appendix 53A**, covering all 109 codes. Each code's reference `default_debit_gl` is translated through the proposal.

| Classification | Codes |
|---|---|
| ACCOUNTANT DECISION | 86 (43 on 1211 materials WIP, dominated by the WIP timing decision) |
| SUITABLE EXISTING | 10 |
| EXACT MATCH | 4 |
| NEW SUB-ACCOUNT | 1 |
| CAPTURE_TIME (account chosen per transaction, not per code) | 8 |

Expense codes stay **inactive** until their account resolves. `ExpenseCodeSeeder` already activates a code only when its account resolves to a postable one. So approving the map is enough to activate the codes; no change to the codes themselves is needed.

## 14. W1–W7 Mapping Requirements

| Workflow | Functions it needs before it can post on the WNG chart | Blocked by |
|---|---|---|
| W1 Receivables / invoicing | accounts_receivable, project_revenue, output_vat, client_deposits | revenue, VAT and deposit decisions (§9 items 1, 2, 8, 9) |
| W2 Payables / bills | accounts_payable, input_vat, wht_payable, accrued_expenses, expense-code debits | VAT, WHT, accruals, WIP timing |
| W3 Payments / vouchers | bank_default, payment-source links, bank_charges, accounts_payable, accrued_expenses | payment-source configuration; accruals |
| W4 Petty cash | petty_cash_float, staff_advances, expense-code debits | Staff Advances sub-account; WIP timing |
| W5 Stock | inventory, inventory_adjustments, opening_balance_equity, wip_direct_materials | inventory treatment, WIP timing |
| W6 Payroll | salaries_expense, cos_direct_labour, paye_payable, statutory_payable, net_payroll_payable | payroll liabilities (§9 item 4) |
| W7 Labour | wip_direct_labour (or cos_direct_labour), accrued_expenses / net_payroll_payable | WIP timing; payroll liabilities |

**No workflow can post end-to-end on the WNG chart until its ACCOUNTANT DECISION items close.** This is by design: each one refuses and names the missing function, rather than posting to a guessed account.

## 15. R-1 — Project Officer Labour Access (implemented)

`RolePermissions`: Project Officer now holds `finance.labour.view`, `finance.labour.record` and `finance.labour.po_verify`. It does **not** hold `finance.labour.finance_verify`.

The grant is scoped by the existing `ProjectFinancialAccess`:

- `canRecordLabour` and `canPoVerifyLabour` also require that the officer is **assigned** to the project (project_officer_id, assigned_po, assigned_users or a task assignment);
- only Finance verify bypasses assignment, and Project Officers do not hold it.

The frontend is gated by permission (`navigation.ts`, `CostAccountPanel.vue`), so no UI change was needed.

The test covers three cases for a Project Officer:

- they can record and PO-verify on an assigned project;
- they are refused on an unassigned project;
- they never pass Finance verify.

## 16. R-3 — Accounts Reports (implemented)

The Accounts role now holds `finance.reports.view`. This opens the Finance reports, cash movements and the labour-classification view. The test confirms that Accounts holds it.

## 17. R-2 — Petty-Cash Custody (remains open)

`finance.petty_cash.manage_custody` is **not assigned** to any role other than Super Admin, and a test enforces this.

Roles that could technically hold it, because they already work the petty-cash screens:

- **Accounts**
- **Admin**
- **Manager**

Naming the custodian is an operational decision for WNG.

## 18. D2 Closeout

**NO HISTORICAL PO/GRN/BILL DATA TO MIGRATE.** Report 52 found 0 rows in `purchase_orders`, `purchase_order_items`, `goods_receipt_notes`, `goods_receipt_note_items`, `bills` and `bill_payments`. These workflows start fresh at cutover, and no historical documents are fabricated. MIG-D2 is **CLOSED** in the Decision Register.

## 19. Historical Orphans — Inherited Exceptions

These are preserved as-is and documented as inherited data-quality exceptions:

- 12 hard-deleted users (ids 1, 3–9, 11, 14, 17, 20), referenced 21 times;
- 151 `production_elements` references;
- 10 `quote_approvals` references.

**No users are fabricated and no ownership is reassigned.** The load's explicit allow-list carries them. They violate no invariant the target load enforces, because FK checks are verified relative to the source. This is recorded as MIG-O1.

## 20. Schema-Narrowing Sign-off (evidence-bounded)

The sign-off covers **120 narrowings verified on the rehearsed snapshot only**:

- every existing value fits;
- the tightest fits are `budget_additions.title` at 173/191 and `venue` at 164/191;
- 0 enum values fall outside their set.

This is **not** generalised beyond that data. The schema gate re-checks on every run and refuses rather than truncates. This is recorded as MIG-S1.

## 21. Tests

| Suite | Result |
|---|---|
| `WngChartMappingTest` (new; 11 tests, 52 assertions) | **PASS** |
| Full backend regression (private `db_srcmig_target_test`) | **1,485 passed**, 5 failed (10,201 assertions). The 5 failures are `W7LabourConcurrencyTest` only: its forked workers read `.env` and cannot reach a private test DB (known harness limit). |
| `W7LabourConcurrencyTest` (5; runs only on `db_test`) | **5 passed** (59 assertions) on db_test, with the D3 code |
| Frontend | **unchanged by D3.** The previous results stand: 169 tests pass, type-check 256 = the ENG-1 baseline (0 new), build OK. |

What `WngChartMappingTest` proves:

- every function is a real reference-chart account;
- on a WNG-style chart, functions do **not** resolve without the map, and **do** resolve with it;
- readiness names the unresolved functions ("accounts_receivable (1100)") and returns all 37;
- an unmapped posting **refuses** with a clear message;
- an uncoded cost never falls back to an arbitrary account, and a mapped 1211→COS-008 posts correctly;
- the reference-chart fallback is unchanged;
- the seeders link through the map and never wipe a link set by Finance;
- R-1 scoping holds;
- R-3 holds, and custody is held by no role other than Super Admin;
- the evaluator validates the proposal and emits only approved lines;
- the committed proposal covers every function and **approves nothing**.

**Net result: 1,490 of 1,490 backend tests pass.** R-1 and R-3 changed the permission matrix, and no existing test regressed.

## 22. Remaining Decisions

| Owner | Decision |
|---|---|
| Accountant | The §9 items 1–13. Item 1 (the full chart export) and item 6 (WIP timing) matter most. |
| WNG operations | R-2 petty-cash custodian |
| Accountant / WNG | Whether to deactivate the migration-inserted 2160, 7150 and 7550 after mapping |
| Engineering (after approval) | Split bank charges by paying account, if the accountant wants M-Pesa fees kept separate |

## 23. Exact Next Action

1. **Send the accountant** this report, Appendix 53A, Appendix 53B and the proposal JSON.
2. **The accountant supplies the complete QuickBooks chart export** (§9 item 1). Engineering re-runs `finance:account-mapping` against it and updates the candidates.
3. **The accountant decides** WIP timing, VAT/WHT, payroll liabilities, inventory, accruals, deposits and revenue. They then mark approved entries `"status": "approved"` in the proposal.
4. **Engineering applies the approvals:**
   - `finance:account-mapping --emit-map`, then copy the lines into `config/finance_accounts.php`;
   - set the payment-source and tax `gl_account_id` links;
   - backfill `account_type` and `normal_balance` per the approved P&L sectioning.
5. **Re-run the Report 52 rehearsal pipeline and smoke test** on the local copy. It passes when readiness shows 37/37 resolved.

Until then, the STOP rules hold: no live source or target, no postings, no opening balances, no cutover, no queue workers, no W8 and no frontend redesign.

## 24. Verdict

**D3 MAPPING PROPOSAL READY FOR ACCOUNTANT APPROVAL**

The architecture decision is confirmed and implemented. The code no longer assumes the reference chart anywhere that WNG data can reach, and every account-level mapping is proposed with its evidence. No mapping is approved, and none has been claimed as approved.
