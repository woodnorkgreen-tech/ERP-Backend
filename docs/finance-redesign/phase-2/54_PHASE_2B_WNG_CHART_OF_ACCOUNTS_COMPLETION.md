# Report 54 — Phase 2B: WNG Chart of Accounts Completion

**Date:** 2026-09-28
**Predecessor:** Report 53 (D3 mapping proposal)
**Decision implemented:** WNG keeps its existing chart (D3 Option A) and has authorised creating the genuinely missing accounts.

**Where it ran:** the repository and the rehearsal environment only (`wng_target_rehearsal`, rebuilt from the Report 52 snapshot copy).

**What was not touched:**
- `woodnork_erpsystem` and `woodnork_erp`;
- production accounts, opening balances, journals (the rehearsal target holds **0 journal entries**);
- cutover, queue workers, W8 and the Finance frontend.

**Appendices and artefacts:**
- **54A**: every account in the completed chart. The same table is in `database/finance/wng-chart-accounts.csv`.
- Profile: `database/finance/wng-chart-profile.json`.
- Rehearsal evidence: `storage/app/wng_rehearsal/rehearsal-reports/r54-*`.

---

## 1. Executive Summary

- **29 accounts were created in the rehearsal.** Each fills a gap no existing account covers:
  - revenue;
  - VAT;
  - WHT;
  - payroll liabilities;
  - accruals;
  - client deposits;
  - inventory asset;
  - Work in Progress;
  - three job cost-of-sales families;
  - prepayments;
  - office improvement.
- **28 existing WNG accounts are reused**, and **92 are retained unchanged**.
- **All 123 pre-existing accounts are byte-identical** after completion: same ids and every column.
- **37 of 37 posting functions resolve** on the completed rehearsal chart, with no mapping manufactured to get there.

**How the pieces fit.** One reviewable file, the **WNG chart profile**, holds:
- the missing accounts;
- the function-to-account map;
- both WIP timing policies;
- the catalogue and paying-account links.

`finance:complete-chart` creates the accounts from it. It is idempotent: the rerun on real data created 0. It refuses conflicts and duplicates, and never modifies an existing account. The account map is built from the profile when `FINANCE_ACCOUNT_PROFILE=wng`.

**The migration now loads WNG's chart.** It is replace-seeded by code, with ids preserved. A new loader step re-points the target's own reference rows (expense codes) at the loaded ids.

**Two defects were found and fixed on the way:**
1. **Paying accounts would all have linked to Equity Bank.** The seeder names every bank by the generic reference 1010, so any map would have linked Stanbic, KCB, Family and Company Card to EQB-001. Each source's account is now named explicitly.
2. **Two cost families could release the same WIP balance twice** if a map sent them to one WIP account. The release now refuses that case.

**Readiness on the rehearsal target.** Every account check is green. Overall readiness stays red on two items that are Finance's decisions, not chart gaps:
- the loan-repayment catalogue code (no evidence WNG borrows);
- the M-Pesa and Company Card paying accounts, which have no ledger account yet.

**Still open:**
- the WIP timing policy;
- classification of WNG's own accounts on the P&L;
- the accountant's sign-off before the profile is activated in production;
- R-2 petty-cash custody.

## 2. Existing Accounts Retained

**No existing account was renumbered, renamed, reclassified, re-parented or deactivated.** Proof on the rehearsal target: all 123 accounts carried over from the source copy match the source on id, code, name, category, account_type, parent_id, is_postable and is_active.

These 28 existing WNG accounts are **reused** by posting functions, catalogue references or paying accounts:

| Function / use | WNG account |
|---|---|
| accounts_receivable | AR-001 Accounts Receivable (A/R) |
| accounts_payable (+ AP payment source) | AP-001 Accounts Payable (A/P) |
| petty_cash_float (+ PC-MAIN) | PETTY-001 Petty cash |
| bank_default (+ BANK-MAIN) | EQB-001 Equity Bank |
| BANK-ALT / BANK-STANBIC / BANK-KCB / BANK-FAMILY | NCBA-001 / STB-001 / KCB-001 / FMB-001 |
| staff_advances | STD-001 Short Term Debtors (§14) |
| opening_balance_equity | OBE-001 Opening Balance Equity |
| cos_direct_materials | COS-008 Cost of Sales:Materials |
| cos_direct_labour | PE-007 Personnel Expenses:Wages-Direct Labour (§12) |
| cos_subcontractors | COS-020 Subcontractors - COS |
| cos_transport_logistics | COS-016 Cost of Sales:Transport & Delivery |
| cos_project_facilitation | COS-006 Cost of Sales:Field Facilitation |
| cos_rework_warranty | COS-018 Other - COS |
| inventory_adjustments | INV-001 Inventory Shrinkage |
| salaries_expense | PE-006 Personnel Expenses:Salaries |
| bank_charges | FIN-003 Finance cost:Bank charges |
| Catalogue overheads | OPE-006, OPE-012, OPE-014, OPE-022, OPE-023, OPE-029, OPE-030, OPE-031, OPE-032 |
| Parent of the new COS accounts | COS-002 Cost of Sales (unchanged; only the new rows point at it) |

The other 92 WNG accounts are retained as they are. No posting function needs them, and they stay available to Finance.

## 3. ERP-Added Accounts Reviewed

The target migrations insert three reference-numbered accounts into any chart.

| Code | Account | Decision | Why |
|---|---|---|---|
| 2160 | Net Payroll Payable | **Remains: the canonical posting account** for `net_payroll_payable` | WNG's own "Payroll liabilities:Net Pay" (seen in history) was not in the export, so the chart holds no duplicate. |
| 7150 | Office Supplies & Stationery | **Duplicate. Not mapped; deprecation recommended.** | It duplicates OPE-030 Stationery & Printing, which the catalogue now uses. No posting reaches 7150. |
| 7550 | Salaries & Wages | **Duplicate. Not mapped; deprecation recommended.** | It duplicates PE-006 Personnel Expenses:Salaries, which `salaries_expense` now uses. |

None was deleted or deactivated. Finance may deactivate 7150 and 7550 once confirmed.

## 4. Missing Accounts Identified

The engine posts to these functions, and **no existing account performs them**. The export lacks revenue, VAT, WHT, payroll liabilities, accruals, deposits, inventory asset and WIP.

| Gap | Evidence | Created? |
|---|---|---|
| Revenue | Only RI-001 Return Inwards is revenue. History codes to "Sales:Printing". | Yes |
| Output and input VAT | VP-001 Vat Penalty exists; the eTIMS claim gate is live. | Yes (2) |
| WHT payable | Supplier withholding is live (W2). | Yes |
| PAYE and statutory deductions | The payroll accrual credits both. History shows "Payroll liabilities:*". | Yes (header + 2) |
| Accrued expenses / GRNI | GRN accrual and voucher allocation (W2, W4) | Yes |
| Client deposits | Unearned client receipts (W1) | Yes |
| Inventory asset | Stores is perpetual. History codes 20 lines to "Inventory Asset". | Yes |
| WIP (9 families) | The engine capitalises job cost; 74 expense codes post to WIP. | Yes (header + 9) |
| COS: equipment/site, project utilities, venue/permits | 13 job-required expense codes. WNG's equivalents are opex. | Yes (3) |
| Supplier advances, refundable deposits, prepaid expenses | 4 catalogue codes | Yes (header + 3) |
| Leasehold improvement (capex) | History codes to "Office Improvement"; ADM-003 depreciation exists. | Yes |
| Loans payable | No evidence (FK-001 Faulu is an asset) | **No** (§25) |

## 5. New Accounts Created

29 accounts, ids 124–152 on the rehearsal target. Codes follow WNG's convention: a mnemonic prefix plus a 3-digit number, with "Parent:Child" names. New families use unused prefixes; the COS accounts take the next free numbers 021–023. **Collisions were checked** against all 123 existing codes and the three migration codes.

| Code | Account | Category / type / balance | Parent | Serves |
|---|---|---|---|---|
| SAL-001 | Sales *(header)* | revenue / revenue / credit | — | — |
| SAL-002 | Sales:Project Revenue | revenue / revenue / credit | SAL-001 | project_revenue |
| VAT-001 | Output VAT Payable | liability / balance_sheet / credit | — | output_vat |
| VAT-002 | Input VAT Recoverable | asset / balance_sheet / debit | — | input_vat |
| WHT-001 | Withholding Tax Payable | liability / balance_sheet / credit | — | wht_payable |
| PL-001 | Payroll liabilities *(header)* | liability / balance_sheet / credit | — | — |
| PL-002 | Payroll liabilities:PAYE | liability / balance_sheet / credit | PL-001 | paye_payable |
| PL-003 | Payroll liabilities:Statutory & Other Deductions | liability / balance_sheet / credit | PL-001 | statutory_payable |
| AE-001 | Accrued Expenses | liability / balance_sheet / credit | — | accrued_expenses |
| CD-001 | Client Deposits | liability / balance_sheet / credit | — | client_deposits |
| IA-001 | Inventory Asset | asset / balance_sheet / debit | — | inventory |
| WIP-001 | Work in Progress *(header)* | asset / balance_sheet / debit | — | — |
| WIP-002 … WIP-010 | Work in Progress: Materials, Direct Labour, Subcontractors, Transport & Delivery, Equipment Hire & Site, Project Utilities, Field Facilitation, Venue & Permits, Rework & Warranty | asset / balance_sheet / debit | WIP-001 | wip_* (under `capitalise`) |
| COS-021 | Cost of Sales:Equipment Hire & Site | expense / direct_cost / debit | COS-002 | cos_equipment_site |
| COS-022 | Cost of Sales:Project Utilities | expense / direct_cost / debit | COS-002 | cos_project_utilities |
| COS-023 | Cost of Sales:Venue & Permits | expense / direct_cost / debit | COS-002 | cos_venue_statutory |
| PRE-001 | Prepayments & Deposits *(header)* | asset / balance_sheet / debit | — | — |
| PRE-002 | Prepayments & Deposits:Supplier Advances | asset / balance_sheet / debit | PRE-001 | catalogue 1310 |
| PRE-003 | Prepayments & Deposits:Refundable Deposits | asset / balance_sheet / debit | PRE-001 | catalogue 1320 |
| PRE-004 | Prepayments & Deposits:Prepaid Expenses | asset / balance_sheet / debit | PRE-001 | catalogue 1340 |
| OI-001 | Office Improvement | asset / balance_sheet / debit | — | catalogue 1600 |

Each account's rationale is recorded in the profile.

## 6. Revenue Structure

**One header and one postable account:** SAL-001 Sales and SAL-002 Sales:Project Revenue.

The Sales header uses WNG's own QuickBooks parent name. Revenue sub-accounts (Printing, Branding, Fabrication, Events/Exhibition, Signage, Merchandise, Other) were considered and **not created**, for three reasons:

- invoices earn into one default revenue account;
- the invoice screen never sends a per-line `revenue_account_id`, so no posting would reach a sub-account;
- revenue by line of business already comes from the enquiry/project dimension.

A child can be added under SAL-001 if the accountant wants the split in the ledger itself.

## 7. Tax Control Accounts

- **VAT-001** Output VAT Payable and **VAT-002** Input VAT Recoverable are separate, because the VAT return reports them separately.
- **WHT-001** Withholding Tax Payable.

On the rehearsal target, `FinanceTaxSeeder` linked the recoverable VAT treatments (STD16-REC, ZERO) to VAT-002 and both WHT categories to WHT-001. Non-recoverable, exempt and out-of-scope treatments correctly carry no account.

**Not created:**
- a WHT receivable/credit: receivables never record tax a client withheld;
- any other statutory tax account: no ERP workflow uses one.

## 8. Payroll Liability Accounts

A PL-001 "Payroll liabilities" header, in WNG's own terminology, with:
- **PL-002 PAYE**;
- **PL-003 Statutory & Other Deductions**.

Net pay reuses **2160** and is not duplicated.

**Why no separate NSSF, SHIF and Housing Levy accounts.** The payroll accrual posts one pooled liability leg: gross − net − PAYE. It covers:
- NSSF, SHIF and the housing levy;
- HELB and other deductions;
- the employer's NSSF and housing-levy share.

Separate accounts would receive nothing until the engine splits that leg by authority. The payslip breakdown does carry `nssf`, `shif` and `housing_levy`, so the split is feasible. It is a follow-up (§25). **No NITA payable was created:** payroll does not compute NITA, and PE-004 Nita Levy is WNG's own expense account.

Expense accounts PE-003, PE-004 and PE-005 are untouched and are not confused with the liabilities.

## 9. Inventory Accounts

**IA-001 Inventory Asset** uses WNG's QuickBooks name. It is required because Stores is perpetual: a receipt debits inventory, and an issue or count credits it. INV-001 Inventory Shrinkage stays the adjustment expense.

**No inventory clearing account** was created. The GRN accrual posts to `accrued_expenses` (§10).

## 10. Procurement / Accrual Accounts

- **AE-001 Accrued Expenses.** One account for goods received not invoiced and for the liability allocated by spend vouchers. The engine has a single accrual function and no procurement-clearing function.
- AP-001 remains the supplier liability.
- **PRE-002 Supplier Advances** is created for the catalogue's supplier-advance code.
- There is no duplicate of AP.

## 11. WIP Accounts

**Available:** the WIP-001 header plus WIP-002…WIP-010, one per cost family the engine implements.

Each family needs its **own** WIP account. The release reads each family's WIP balance, and a new guard refuses the release if two families resolve to one account; otherwise that balance would be released twice.

WIP recognition timing is separate: see §22.

## 12. Cost-of-Sales Mapping

| Family | COS account |
|---|---|
| Materials | COS-008 |
| Direct labour | **PE-007** Wages-Direct Labour |
| Subcontractors | COS-020 |
| Transport & logistics | COS-016 |
| Facilitation | COS-006 |
| Rework & warranty | COS-018 Other - COS |
| Equipment hire & site | **COS-021** (new) |
| Project utilities | **COS-022** (new) |
| Venue & permits | **COS-023** (new) |

**No "Cost of Sales:Direct Labour" duplicate.** Gross margin is computed from `account_type`, not from the name hierarchy. So classifying PE-007 as `direct_cost` (MIG-D3b) is enough, and a new account would be cosmetic.

The three new COS accounts exist because their job costs have no COS home. OPE-013 Equipment Rental, UTIL-001 and OPE-017 are WNG's own operating costs; posting job costs there would take them out of gross margin.

## 13. Client Deposits

**CD-001 Client Deposits.** The receipt architecture accepts client money before any invoice earns it. That money is held here, a liability, and applied when allocated. It never goes straight to Sales.

## 14. Staff Advances

**Mapped to STD-001 Short Term Debtors.** No separate account was created:

- trade debtors are AR-001;
- no history uses STD-001 for anything else;
- staff imprest is therefore its natural use.

A separate account is needed only if WNG also books other debtors in STD-001. That is noted for the accountant.

## 15. Account Hierarchy

**New headers** are non-postable, with postable children:
- SAL-001 → SAL-002;
- PL-001 → PL-002, PL-003;
- WIP-001 → WIP-002…010;
- PRE-001 → PRE-002…004.

**New COS accounts** sit under WNG's own COS-002 Cost of Sales. The link lives on the new rows only; COS-002 is unchanged.

**Existing accounts are not re-parented.** WNG's hierarchy lives only in names, and 5 WNG parents are postable (Report 53 §12); changing either would modify WNG accounts.

The 2160 Net Payroll Payable account also stays unparented rather than being moved under PL-001.

## 16. Account Types

Every new account has a category, an `account_type` and a `normal_balance` consistent with each other; a test enforces this:

- **Assets:** balance_sheet, debit.
- **Liabilities:** balance_sheet, credit.
- **Revenue:** revenue, credit.
- **COS:** direct_cost, debit.

**WNG's 120 existing accounts still have no `account_type`**, so the P&L shows them as unclassified. Classifying them is **MIG-D3b** (open). The proposal is:
- COS-* and PE-007 as direct_cost (OPE-026 Set Up Casuals to be confirmed);
- other expense accounts as opex.

It was deliberately **not** applied, because it would modify WNG's accounts. No readiness check depends on it.

## 17. Semantic Mapping

- **Profile:** `database/finance/wng-chart-profile.json`.
- **Loader:** `FinanceChartProfile`.
- **Config:** `config/finance_accounts.php` builds `map` and `payment_sources` from the profile named by `FINANCE_ACCOUNT_PROFILE`, with the WIP policy from `FINANCE_WIP_POLICY`.
- **Without a profile,** the map is empty: development and the test suite keep the reference chart unchanged.
- **Failure mode:** an unreadable profile or unknown policy maps nothing, rather than guessing. Readiness names the problem in a new **Chart profile** check.

The posting services still take their codes from `FinanceAccountFunctions` (Report 53). No literal account codes were added.

The directive's function names correspond to the engine's as follows:
- sales_revenue = `project_revenue`;
- inventory_asset = `inventory`;
- vat_input / vat_output = `input_vat` / `output_vat`;
- withholding_tax_payable = `wht_payable`;
- payroll_net_payable = `net_payroll_payable`;
- nssf/shif/housing_levy_payable are pooled in `statutory_payable` (§8).

**Paying accounts** are named per source in the profile:

| Source | Account |
|---|---|
| PC-MAIN | PETTY-001 |
| BANK-MAIN | EQB-001 |
| BANK-ALT | NCBA-001 |
| BANK-STANBIC | STB-001 |
| BANK-KCB | KCB-001 |
| BANK-FAMILY | FMB-001 |
| AP | AP-001 |
| MPESA | unlinked |
| CARD | unlinked |

The seeders now **keep** any link Finance has set, rather than overwriting it with the resolved one.

## 18. Expense Codes

Rehearsal target, after `migration:regenerate reference`:

- **102 of 109 codes are active**, and every active code posts to a postable account.
- **Distribution:**
  - 74 codes post to WIP-002…010 (43 on WIP-002 Materials) under the capitalise policy;
  - the rest post to existing WNG accounts first: COS-008, FIN-003, PETTY-001, STD-001, and OPE-006/012/014/022/023/029/030/031/032;
  - the remainder post to the new VAT-002, WHT-001, CD-001, IA-001, PRE-002/003/004 and OI-001.
- **7 inactive:**
  - **6 capture-time codes** name their account in prose ("Receiving cash/bank account", "Relevant 1400 PPE account", …). A person chooses the account per transaction, by design.
  - **NE-016 Loans Payable (2300)**: no account, pending the accountant (§25).
- **No expense account was created just to mirror a code.**

## 19. Duplicate Prevention

`finance:complete-chart` enforces these rules. Any refusal stops the whole run before anything is written, and each rule is tested:

1. **Code present with the same definition:** left alone. Rerunning on the real rehearsal chart created **0** accounts.
2. **Code present with a different definition:** **refused**, and the account is never overwritten.
3. **Same name already under another code** (for example, the accountant adds "Inventory Asset" as INVA-01): **refused as a duplicate**, "map to INVA-01 instead".
4. **A postable account that no function or catalogue reference maps to:** refused as an orphan.
5. **A new header must be non-postable**, and a parent must exist or be listed earlier.
6. **Existing rows are never updated.** Inserts are re-checked under lock inside one transaction.

**Guards:**
- execution needs `--confirm=<database>`;
- it always refuses the live source;
- it refuses the live target without `--cutover`.

## 20. W1–W7 Readiness

On the rehearsal target, every function each workflow needs resolves:

| Workflow | Functions (WNG accounts) | Status |
|---|---|---|
| W1 Receivables | AR-001, SAL-002, VAT-001, CD-001 | Resolves |
| W2 Procurement → Payment | AP-001, VAT-002, WHT-001, AE-001, IA-001, WIP-*/COS-* | Resolves |
| W3 Expenses | expense codes (102 active), VAT-002, WHT-001, STD-001 | Resolves |
| W4 Payment Vouchers | paying accounts (7 linked), AP-001, AE-001, FIN-003 | Resolves; MPESA/CARD unlinked (§25) |
| W5 Petty Cash | PETTY-001, STD-001, expense codes | Resolves; R-2 open |
| W6 Payroll / Project costing | PE-006, PE-007, PL-002, PL-003, 2160; WIP release WIP-*→COS-* | Resolves |
| W7 Labour | WIP-003 → PE-007 | Resolves |

**Finance readiness on the target:**

| Check | Result |
|---|---|
| Accounting period | OK |
| Postable accounts | OK: 148 |
| Required accounts | **OK: every function resolves** |
| Chart profile | OK: 'wng' active |
| Expense catalogue | OK |
| Catalogue account mapping | **NO**: 1 code (loans, §25) |
| Payment sources | **NO**: MPESA and CARD unlinked (§25) |
| VAT treatments | OK |
| WHT categories | OK |
| Cost centres | OK |
| Activities | OK |
| Integrity counters | All 0 |

Overall readiness is **false** because of those two decisions, not because of a chart gap.

**No W1–W7 smoke postings were run.** This task forbade posting journals, and the rehearsal target holds 0 journal entries. The Report 52 smoke suite is the next action, once the accountant has signed off.

## 21. 37/37 Finance Mapping Status

**37 / 37 posting functions resolve** to postable, active accounts on the completed rehearsal chart, both from `finance:complete-chart` and from the readiness endpoint:

- 16 resolve to existing accounts: 15 WNG accounts plus 2160;
- 21 resolve to new accounts: 12 control, revenue and COS accounts plus the 9 WIP accounts.

On WNG's chart before completion, 21 did not resolve. That exact list is asserted in `WngChartCompletionTest`.

**No function is unresolved, and none was mapped just to reach the count.** Each has the evidence in §§2–14.

## 22. WIP Policy Still Open

| | Status |
|---|---|
| **WIP ACCOUNTS AVAILABLE** | **Yes**: WIP-001 header + WIP-002…010, created and resolving. |
| **WIP RECOGNITION/TIMING POLICY** | **OPEN, for Finance confirmation (MIG-D3a).** |

Both policies are configured in the profile and chosen by `FINANCE_WIP_POLICY`:

- **`capitalise`** (profile default; what the engine implements; used in the rehearsal): job cost is debited to WIP on capture and released to its COS twin pro rata as the job is invoiced.
- **`expense_on_capture`**: each WIP function maps onto its COS twin, so cost reaches the P&L on capture. The release then has nothing to move, because it skips a family whose WIP and COS accounts are the same.

Creating the accounts does not decide which one applies.

## 23. R-2 Petty Cash Custody

Unchanged and **still open.** `finance.petty_cash.manage_custody` is assigned to no role other than Super Admin, and the Report 53 test enforces this. It is pending WNG's operational confirmation.

## 24. Tests

| Suite | Result |
|---|---|
| `WngChartCompletionTest` (new: creation, idempotency, conflict and duplicate refusal, guards, WIP switch, bank links, tax/expense-code resolution, fallback, readiness) | **15 / 15 pass** |
| `WngChartMappingTest` (Report 53) | 11 / 11 pass |
| `WorkInProgressReleaseTest` (+ the double-release guard) | 13 / 13 pass |
| Migration tooling (`tests/Feature/SourceMigration`, including the new replace-seeded chart and re-point test) | **36 / 36 pass** |
| Full backend regression (private DB) | **1,502 passed**, 5 failed (10,361 assertions). The 5 are `W7LabourConcurrencyTest` only: its forked workers cannot reach a private DB (known harness limit). |
| `W7LabourConcurrencyTest` on `db_test` | **5 / 5 pass** (59 assertions). **Net: 1,507 / 1,507 backend tests pass.** |
| Frontend (unchanged; the readiness screen renders the new check generically) | **169 / 169** unit tests; type-check **256 = ENG-1 baseline, 0 new**; build **exit 0**, 1,891 modules |

**Real-data rehearsal pipeline** (clean rebuild):

| Step | Result |
|---|---|
| Migration chain | 145 s |
| Import | **VALIDATION PASS**: 229 tables reconciled (the chart now included); 0 mismatches, 0 orphans introduced, exclusions PASS; 475 s |
| Permissions and mapped grants | PASS |
| `complete-chart` dry run → execute → rerun | 37/37 → 29 created → **0 created** |
| Reference regeneration | Paying-account and tax links, 102 codes active |
| Planned cost lines | 3,877 (D5) |
| Dangling chart references | 0 |
| Journal entries | 0 |


## 25. Remaining Decisions

| Owner | Decision |
|---|---|
| Accountant | Sign off the WNG profile before it is activated in production (`FINANCE_ACCOUNT_PROFILE=wng` on the live target at cutover) |
| Accountant / Finance | **MIG-D3a WIP timing policy** (`capitalise` or `expense_on_capture`) |
| Accountant | **MIG-D3b** account_type classification of WNG's existing accounts for P&L sectioning |
| Accountant | Loans: does WNG carry a loan liability (FK-001 Faulu is recorded as an asset)? Until then, NE-016 stays off. |
| Finance | Link or retire MPESA and CARD (is M-Pesa a separate asset? which account settles card spend?) |
| Finance | Deactivate the duplicates 7150 and 7550 once confirmed |
| Accountant | Confirm STD-001 carries staff advances only; INV-001 takes count gains as well as losses; bank charges on FIN-003 (M-Pesa fees could go to FIN-005) |
| Engineering (follow-up, if wanted) | Split the payroll statutory leg by authority (NSSF, SHIF, housing levy) into separate PL-* accounts |
| WNG operations | R-2 petty-cash custodian |

## 26. Exact Next Action

1. **The accountant reviews:**
   - Appendix 54A or the CSV;
   - the profile;
   - this report's decisions (§25), especially MIG-D3a and MIG-D3b.
2. **Finance links or retires MPESA and CARD**, and decides on the loan code.
3. **Re-run the Report 52 rehearsal pipeline and the W1–W7 smoke suite** on the completed rehearsal chart. This time the D3-blocked steps can post, in the **rehearsal database only**.
4. **Only after sign-off and a clean smoke run:** set `FINANCE_ACCOUNT_PROFILE=wng` for cutover, and run `finance:complete-chart --profile=wng --execute --confirm=woodnork_erp --cutover` as a cutover-runbook step.

Until then, the STOP rules hold.

## 27. Final Verdict

### WNG CHART OF ACCOUNTS COMPLETE FOR FINANCE REHEARSAL

All 37 required posting functions resolve on the completed rehearsal chart. The missing accounts exist without duplicating any WNG account, and no existing account was modified. What remains is accounting policy and configuration, not missing structure:

- the WIP timing policy;
- P&L classification of WNG's own accounts;
- the loan code;
- the M-Pesa and Card links;
- the accountant's sign-off before production.
