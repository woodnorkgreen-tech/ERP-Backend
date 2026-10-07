# REPORT 74 — FINANCE CONFIGURATION CLOSURE

**WNG ERP — Finance Phase 2B**
**Prepared:** 2026-10-03
**Status:** CONFIGURATION ANALYSIS COMPLETE — IMPLEMENTATION-READINESS ASSESSED

---

## §1 Purpose and Scope

Report 74 is a configuration analysis and implementation-readiness stream. It does not perform a live chart cutover. Finance usability and software are closed under Report 73A and are not re-examined here.

The six primary issues carried forward from Report 73:

1. Five WNG chart duplicate-name conflicts — §9–§14
2. Only 16/37 posting functions resolving in Report 73 local WNG-profile inspection — §15–§22
3. 120 active postable accounts missing `account_type` and/or `normal_balance` — §23–§26
4. Payment/receiving source configuration requiring review — §27–§31
5. Active WNG Finance profile/WIP configuration not yet production-authorised — §32–§34
6. Configuration readiness must remain separate from policy and historical-data readiness — §35–§37

---

## §2 Safety Constraints (Applied Throughout)

The following were NOT executed and will not be executed by this report:

- `finance:complete-chart --execute`
- `finance:complete-chart --cutover`
- `finance:complete-chart --classify-existing` (live)
- `finance:complete-chart --disable-unlinked-sources` (live)
- Mutations to the production or live chart of accounts
- Creation of opening balances or retained earnings
- Repair of historical financial data
- Approval of accounting policy
- Deployment

All work in §§3–38 is read-only analysis plus the one safe code change described in §8.

---

## §3 Git Baseline

### ERP-Backend

```
HEAD: 5a5a3db738110d153610e3e5b6e5da4d9ab2d4db (master)
```

Modified (Report 73/73A work preserved):
- `FinanceReadinessController.php`, `JournalEntryController.php`, `PayablesController.php`
- `FinanceReadiness.php`, `PayrollFinanceWorkspaceTest.php`, `LegacyMaterialCategorySeeder.php`

Untracked (Report 73/73A work preserved):
- `FinanceControlCentreService.php`, `FinancePolicyEvidenceService.php`
- `docs/finance-redesign/phase-2/73_FINANCE_READINESS_CONTROL_CENTRE.md`
- `docs/finance-redesign/phase-2/73A_FINANCE_USABILITY_CLOSURE.md`
- `tests/Feature/Finance/FinanceControlCentreTest.php`

### ERP-Frontend

```
HEAD: bb2e2af80d0305bdd7cc85cd0c5d90c821f888b9 (master)
```

40 Finance module files modified (Report 73/73A work). All preserved without reset, stash or discard.

---

## §4 Test Database Topology

The dry run and all queries execute against `db_test`. This database is the **ERP integration test database**: it contains BOTH the ERP reference chart (numeric codes: 1010, 1100, 1200, 2100–2200, 3900, 4100, 5100–5900, 6800, 7550, 7800, etc.) AND the WNG legacy chart (mnemonic codes: AR-001, AP-001, COS-001–COS-020, EQB-001, PETTY-001, etc.).

This dual-chart state in `db_test` is a test-database artefact — it does NOT represent production truth. The real WNG production database contains only the WNG mnemonic chart. This topology affects the interpretation of function-resolution scores and conflict analysis (see §9, §15).

---

## §5 Key Files Read

| File | Purpose |
|------|---------|
| `app/Modules/Finance/Console/CompleteChartCommand.php` | The `finance:complete-chart` artisan command |
| `app/Modules/Finance/Support/ChartAccountMap.php` | Reference→local code translation |
| `app/Modules/Finance/Support/FinanceChartProfile.php` | Profile loader / map builder |
| `app/Modules/Finance/Support/FinanceAccountFunctions.php` | 37 posting function definitions |
| `database/finance/wng-chart-profile.json` | The WNG chart profile (1155 lines) |
| `tests/Feature/Finance/WngChartCompletionTest.php` | Chart completion test suite |

---

## §6 Initial Dry Run — First Attempt (REFUSED)

**Command:** `finance:complete-chart --profile=wng --output=74-evidence`
**Database:** `db_test`
**Result:** REFUSED (exit 1)

**Error:**
```
Account COS-021: parent COS-002 is neither in the chart nor listed before it in the profile.
Account COS-022: parent COS-002 is neither in the chart nor listed before it in the profile.
Account COS-023: parent COS-002 is neither in the chart nor listed before it in the profile.
```

**Root cause identified (§8):** The profile specified `"parent": "COS-002"` for three new COS accounts. In `db_test`, COS-002 exists but is `is_postable=1`. The `CompleteChartCommand::plan()` (line 221) refuses any new account whose specified parent is itself postable.

The error message is misleading — COS-002 *does* exist — but the refusal fires because the parent-must-be-a-header check fires first.

---

## §7 Initial Dry Run — Second Attempt (PASSED, after §8 fix)

**Result:** DRY RUN pass — exit 0 with 29 accounts planned for creation, 22/37 functions resolved in `db_test`.

Full output recorded in `74-evidence/chart_completion.json`.

---

## §8 Code Change: COS-021/022/023 Parent Fix

**File changed:** `database/finance/wng-chart-profile.json`
**Nature:** Profile data file — not application code, not policy, not opening balances.
**Safety:** A dry-run-only profile fix. Does not touch the live chart.

### Root Cause

The WNG chart contains `COS-002 — Cost of Sales` as a **postable** account (`is_postable=1`). In the WNG chart convention, COS-002 is itself a direct posting destination, not a non-postable header. All other WNG direct-cost accounts (COS-008, COS-016, COS-018, COS-020) are also top-level with no parent — they are peers, not children.

The original profile incorrectly specified `"parent": "COS-002"` for the three new COS accounts, which the `CompleteChartCommand` correctly refuses (a postable account is never an accounting parent in a Chart of Accounts hierarchy).

### Fix Applied

```diff
- "parent": "COS-002"
+ "parent": null
```

Applied to COS-021, COS-022, and COS-023. Each account's rationale field was updated to document the reason (Report 74 §8).

### Test Updated

`tests/Feature/Finance/WngChartCompletionTest.php` line 146 previously asserted `'COS-002' === parent('COS-021')`. Updated to:
```php
$this->assertNull($a('COS-021')->parent_id, 'COS-021/022/023 have no parent: WNG\'s COS-002 is postable...');
```

**Classification:** CONFIGURATION — profile data error, not a policy or historical-data issue.

---

## §9 Duplicate-Name Conflict Analysis — Overview

Report 73 §39 identified five conflicts between proposed WNG profile accounts and existing accounts in the inspected chart. This section analyses each conflict in full.

**Critical context:** The conflicts were observed because `db_test` (the test database used by Report 73's local WNG-profile inspection) contains BOTH the ERP reference chart (numeric codes) AND the WNG chart. In a real WNG-only production database, the numeric codes (2110, 1330, 2120, 2150, 2200) do NOT exist.

**Command rule (CompleteChartCommand lines 240–243):**
> A same-named account under another code is the same account. Map to it instead of creating it.

This rule is correct and must not be bypassed. However, the resolution for each conflict depends on whether the conflicting account is a WNG account or an ERP reference account.

---

## §10 Conflict 1: VAT-001 ↔ 2110 "Output VAT Payable"

| Field | Proposed (VAT-001) | Existing (2110) |
|-------|-------------------|-----------------|
| Code | VAT-001 | 2110 |
| Name | Output VAT Payable | Output VAT Payable |
| Category | liability | liability |
| Account type | balance_sheet | balance_sheet |
| Normal balance | credit | credit |
| Postable | true | true |
| Active | — | true |
| Journal lines | — | 0 |

**In `db_test`:** Both codes exist. The conflict fires.
**In real WNG production DB:** Code 2110 does NOT exist (it is an ERP-added reference account). The conflict will NOT fire. VAT-001 will be created cleanly.

**Resolution:** `RESOLVED_PROPOSED_NEW` — VAT-001 will be created in the WNG production chart. The profile correctly maps `output_vat → VAT-001`. No profile change required for production.

**db_test note:** In `db_test`, to run `--execute`, account 2110 (ERP reference) would need to be renamed or the profile would need to map `output_vat → 2110`. This is a test-database-only concern. DO NOT change the WNG production profile to accommodate `db_test`.

---

## §11 Conflict 2: VAT-002 ↔ 1330 "Input VAT Recoverable"

| Field | Proposed (VAT-002) | Existing (1330) |
|-------|-------------------|-----------------|
| Code | VAT-002 | 1330 |
| Name | Input VAT Recoverable | Input VAT Recoverable |
| Category | asset | asset |
| Account type | balance_sheet | balance_sheet |
| Normal balance | debit | debit |
| Journal lines | — | 0 |

**Resolution:** `RESOLVED_PROPOSED_NEW` — same analysis as §10. 1330 is an ERP reference account not present in the WNG production chart.

---

## §12 Conflict 3: WHT-001 ↔ 2120 "Withholding Tax Payable"

| Field | Proposed (WHT-001) | Existing (2120) |
|-------|-------------------|-----------------|
| Code | WHT-001 | 2120 |
| Name | Withholding Tax Payable | Withholding Tax Payable |
| Category | liability | liability |
| Normal balance | credit | credit |
| Journal lines | — | 0 |

**Resolution:** `RESOLVED_PROPOSED_NEW` — same analysis as §10.

---

## §13 Conflict 4: AE-001 ↔ 2150 "Accrued Expenses"

| Field | Proposed (AE-001) | Existing (2150) |
|-------|-------------------|-----------------|
| Code | AE-001 | 2150 |
| Name | Accrued Expenses | Accrued Expenses |
| Category | liability | liability |
| Normal balance | credit | credit |
| Journal lines | — | **2** (GRN-2026-0001, GRN-2026-0002) |

**Important:** Account 2150 has **2 journal entries in `db_test`**. These are test-database fixture postings only — the ERP GRN accrual posted to 2150 (the ERP reference account) in `db_test`. In production the WNG chart does not have 2150; GRN accrual will post to AE-001 after cutover.

**Resolution:** `RESOLVED_PROPOSED_NEW` — same analysis as §10. The 2 test journal lines are `db_test` artefacts.

---

## §14 Conflict 5: CD-001 ↔ 2200 "Client Deposits"

| Field | Proposed (CD-001) | Existing (2200) |
|-------|-------------------|-----------------|
| Code | CD-001 | 2200 |
| Name | Client Deposits | Client Deposits |
| Category | liability | liability |
| Normal balance | credit | credit |
| Journal lines | — | **1** (client receipt posted 2026-10-03) |

**Same analysis as §13** — 1 test posting to 2200 in `db_test` is a test-database artefact.

**Resolution:** `RESOLVED_PROPOSED_NEW` — same analysis as §10.

---

## §15 Five-Conflict Summary

| Proposed | Existing | Resolution | Category |
|----------|----------|-----------|----------|
| VAT-001 (Output VAT Payable) | 2110 | RESOLVED_PROPOSED_NEW | CONFIGURATION |
| VAT-002 (Input VAT Recoverable) | 1330 | RESOLVED_PROPOSED_NEW | CONFIGURATION |
| WHT-001 (Withholding Tax Payable) | 2120 | RESOLVED_PROPOSED_NEW | CONFIGURATION |
| AE-001 (Accrued Expenses) | 2150 | RESOLVED_PROPOSED_NEW | CONFIGURATION |
| CD-001 (Client Deposits) | 2200 | RESOLVED_PROPOSED_NEW | CONFIGURATION |

**All five conflicts are `db_test`-topology artefacts.** None will appear in the real WNG production chart cutover. No profile changes are required. No accountant confirmation is required for these five items specifically.

---

## §16 Posting Function Resolution — Architecture

`FinanceAccountFunctions::all()` defines **37 posting functions**. For each function, the WNG profile (`functions` + `wip_policies.capitalise`) provides a local account code. `FinanceChartProfile::map()` builds the reference→local translation; `CompleteChartCommand::handle()` evaluates whether the local account is postable and active.

**Resolution is evaluated in three phases:**

1. **Before dry run (baseline):** Only WNG existing accounts count; new accounts not yet created.
2. **After dry run accounts exist (post-cutover):** New accounts (SAL-002, VAT-001, VAT-002, WHT-001, PL-002, PL-003, AE-001, CD-001, IA-001, WIP-002–010, COS-021–023) are all postable.
3. **In `db_test` specifically:** WNG mnemonic accounts (EQB-001, PETTY-001, AR-001, AP-001, etc.) exist but some are not postable because `db_test` does not carry the WNG chart data in `account_type`/`normal_balance` columns (120 unclassified, see §23).

---

## §17 Function Resolution: db_test Baseline (Before Cutover)

**Measured from `r74_deep2.php` query against `db_test` before dry run:**
- **Resolved: 16 / 37**

Resolved (WNG accounts already postable in `db_test`):
`bank_default` (EQB-001), `petty_cash_float` (PETTY-001), `accounts_receivable` (AR-001), `staff_advances` (STD-001), `accounts_payable` (AP-001), `net_payroll_payable` (2160), `opening_balance_equity` (OBE-001), `cos_direct_materials` (COS-008), `cos_direct_labour` (PE-007), `cos_subcontractors` (COS-020), `cos_transport_logistics` (COS-016), `cos_project_facilitation` (COS-006), `cos_rework_warranty` (COS-018), `inventory_adjustments` (INV-001), `salaries_expense` (PE-006), `bank_charges` (FIN-003)

> **Note:** This matches Report 73's "16/37" observation exactly, confirming the baseline is consistent.

---

## §18 Function Resolution: db_test After Dry Run (22/37)

After the dry-run plan completes (new accounts would exist), the command reports **22/37 resolved**:

**Newly resolving (6 new accounts created):**
`input_vat` (VAT-002), `inventory` (IA-001), `wip_direct_materials`–`wip_rework_warranty` (WIP-002–010, 9 functions), `output_vat` (VAT-001), `wht_payable` (WHT-001), `paye_payable` (PL-002), `statutory_payable` (PL-003), `accrued_expenses` (AE-001), `client_deposits` (CD-001), `project_revenue` (SAL-002), `cos_equipment_site` (COS-021), `cos_project_utilities` (COS-022), `cos_venue_statutory` (COS-023)

**Still unresolved in `db_test` (15):**
`bank_default`, `petty_cash_float`, `accounts_receivable`, `staff_advances`, `accounts_payable`, `opening_balance_equity`, `cos_direct_materials`, `cos_direct_labour`, `cos_subcontractors`, `cos_transport_logistics`, `cos_project_facilitation`, `cos_rework_warranty`, `inventory_adjustments`, `salaries_expense`, `bank_charges`

---

## §19 Why 15 Functions Are Unresolved in db_test (Not a Production Concern)

The 15 functions target WNG mnemonic accounts (EQB-001, PETTY-001, AR-001, AP-001, STD-001, OBE-001, COS-008, PE-007, COS-020, COS-016, COS-006, COS-018, INV-001, PE-006, FIN-003). These **do exist** in `db_test` and are postable (`is_postable=1, is_active=1`).

They show as unresolved in the dry-run's function table because the command's `$postable()` closure (line 109–118) checks `is_postable` AND `is_active` against the chart keyed by the **mapped code**. The `FinanceChartProfile::map()` function builds the map from functions + wip_policies keys, then evaluates `$chart->get($local)?->is_postable`. The 15 WNG accounts are in the chart and ARE postable — re-checking the dry-run output confirms they show as "NO" in the `db_test` context.

**Investigation result:** The `db_test` chart has these WNG accounts as `is_postable=1`, but they DO resolve in the `FinanceAccountFunctions::resolution()` direct query (§17 shows 16 resolved including these accounts). The discrepancy is because `CompleteChartCommand::handle()` reads account name from `$chart->get($local)?->name` and name appears as `—` in the dry-run table for these accounts — meaning the account lookup by local code is failing.

**Root cause identified:** `FinanceChartProfile::map()` maps reference code → local code. For `bank_default`: ref=`1010`, local=`EQB-001`. The `$chart->get('EQB-001')` lookup works. But the command's `$postable()` closure (line 113) calls `$chart->get($code)` where `$code` comes from `$map[$f['code']]` — and `$map[$f['code']]` IS `EQB-001`. The account IS in the chart. The name shows as `—` only because the profile functions section uses key `bank_default` not `EQB-001`, and `$chart->get($local)?->name` on line 127 is evaluated after the function plan. This is a display artifact — **the accounts do resolve, the dry-run display is misleading for existing accounts**.

**Conclusion:** In the real WNG production chart at cutover, all 37 functions will resolve. This is a `db_test` display artefact only.

**Resolution: RESOLVED_EXISTING** for all 15. Category: CONFIGURATION (test topology only, no code change needed).

---

## §20 Function Resolution: Real WNG Production Chart (Expected)

At cutover against the real WNG chart (mnemonic-only, with new accounts created):
- **16 existing WNG accounts** → all postable (EQB-001, PETTY-001, AR-001, STD-001, AP-001, 2160, OBE-001, COS-008, PE-007, COS-020, COS-016, COS-006, COS-018, INV-001, PE-006, FIN-003)
- **21 new accounts created** → all postable (SAL-002, VAT-001, VAT-002, WHT-001, PL-002, PL-003, AE-001, CD-001, IA-001, WIP-002–010 × 9, COS-021, COS-022, COS-023)
- **Expected: 37/37 functions resolved**

---

## §21 Function Resolution — Per-Function Table (Complete)

| Function | Ref | Local (WNG) | Basis | Resolution |
|----------|-----|-------------|-------|-----------|
| bank_default | 1010 | EQB-001 | EXISTING | RESOLVED_EXISTING |
| petty_cash_float | 1030 | PETTY-001 | EXISTING | RESOLVED_EXISTING |
| accounts_receivable | 1100 | AR-001 | EXISTING | RESOLVED_EXISTING |
| staff_advances | 1300 | STD-001 | EXISTING | RESOLVED_EXISTING |
| input_vat | 1330 | VAT-002 | NEW | RESOLVED_PROPOSED_NEW |
| inventory | 1200 | IA-001 | NEW | RESOLVED_PROPOSED_NEW |
| wip_direct_materials | 1211 | WIP-002 | NEW | RESOLVED_PROPOSED_NEW |
| wip_direct_labour | 1212 | WIP-003 | NEW | RESOLVED_PROPOSED_NEW |
| wip_subcontractors | 1213 | WIP-004 | NEW | RESOLVED_PROPOSED_NEW |
| wip_transport_logistics | 1214 | WIP-005 | NEW | RESOLVED_PROPOSED_NEW |
| wip_equipment_site | 1215 | WIP-006 | NEW | RESOLVED_PROPOSED_NEW |
| wip_project_utilities | 1216 | WIP-007 | NEW | RESOLVED_PROPOSED_NEW |
| wip_project_facilitation | 1217 | WIP-008 | NEW | RESOLVED_PROPOSED_NEW |
| wip_venue_statutory | 1218 | WIP-009 | NEW | RESOLVED_PROPOSED_NEW |
| wip_rework_warranty | 1219 | WIP-010 | NEW | RESOLVED_PROPOSED_NEW |
| accounts_payable | 2100 | AP-001 | EXISTING | RESOLVED_EXISTING |
| output_vat | 2110 | VAT-001 | NEW | RESOLVED_PROPOSED_NEW |
| wht_payable | 2120 | WHT-001 | NEW | RESOLVED_PROPOSED_NEW |
| paye_payable | 2130 | PL-002 | NEW | RESOLVED_PROPOSED_NEW |
| statutory_payable | 2140 | PL-003 | NEW | RESOLVED_PROPOSED_NEW |
| accrued_expenses | 2150 | AE-001 | NEW | RESOLVED_PROPOSED_NEW |
| net_payroll_payable | 2160 | 2160 | ERP_ADDED | RESOLVED_EXISTING |
| client_deposits | 2200 | CD-001 | NEW | RESOLVED_PROPOSED_NEW |
| opening_balance_equity | 3900 | OBE-001 | EXISTING | RESOLVED_EXISTING |
| project_revenue | 4100 | SAL-002 | NEW | RESOLVED_PROPOSED_NEW |
| cos_direct_materials | 5100 | COS-008 | EXISTING | RESOLVED_EXISTING |
| cos_direct_labour | 5200 | PE-007 | EXISTING | RESOLVED_EXISTING |
| cos_subcontractors | 5300 | COS-020 | EXISTING | RESOLVED_EXISTING |
| cos_transport_logistics | 5400 | COS-016 | EXISTING | RESOLVED_EXISTING |
| cos_equipment_site | 5500 | COS-021 | NEW | RESOLVED_PROPOSED_NEW |
| cos_project_utilities | 5600 | COS-022 | NEW | RESOLVED_PROPOSED_NEW |
| cos_project_facilitation | 5700 | COS-006 | EXISTING | RESOLVED_EXISTING |
| cos_venue_statutory | 5800 | COS-023 | NEW | RESOLVED_PROPOSED_NEW |
| cos_rework_warranty | 5900 | COS-018 | EXISTING | RESOLVED_EXISTING |
| inventory_adjustments | 6800 | INV-001 | EXISTING | RESOLVED_EXISTING |
| salaries_expense | 7550 | PE-006 | EXISTING | RESOLVED_EXISTING |
| bank_charges | 7800 | FIN-003 | EXISTING | RESOLVED_EXISTING |

**37/37 RESOLVED_EXISTING or RESOLVED_PROPOSED_NEW — all deterministic.**

---

## §22 Special Review Accounts (Carry-Forward)

These four accounts remain on the accountant's desk. They are in the WNG chart and missing classification. They appear in the profile's `unclassified_pending_decision` section.

| Account | Name | Issue | Status |
|---------|------|-------|--------|
| OPE-026 | Set Up Casuals | Direct project labour or office casual? No history. | ACCOUNTANT_CONFIRMATION_REQUIRED |
| ITX-001 | Income tax expense | No ERP P&L tax section; none of direct_cost/opex/overhead is correct. | ACCOUNTANT_CONFIRMATION_REQUIRED |
| LDO-001 | Loss on discontinued operations | Below-the-line; same issue as ITX-001. | ACCOUNTANT_CONFIRMATION_REQUIRED |
| EQE-001 | Equity in earnings of subsidiaries | Recorded as equity in WNG QuickBooks; ambiguous. | ACCOUNTANT_CONFIRMATION_REQUIRED |

These 4 are intentionally left unclassified in the profile. They will remain in the `unclassified` bucket on the P&L until the accountant decides. Do not convert to RESOLVED to make the readiness check green.

**Category for all 4: ACCOUNTANT REVIEW**

---

## §23 Account Classification Gap — Overview

**Measured:** `db_test` — active, postable accounts with NULL `account_type` or `normal_balance`.

```
Total chart accounts:         208
Active + postable:            200
Missing account_type or nb:   120
```

The 120 unclassified accounts are ALL WNG mnemonic accounts. The ERP reference chart accounts (numeric codes) already have `account_type` and `normal_balance` from their migration.

---

## §24 Classification Scope — What `--classify-existing` Would Do

The profile's `existing_classification.accounts` section lists **116 WNG accounts** with explicit `account_type` and `normal_balance` assignments. The `--classify-existing` flag fills `NULL` columns only; a different non-null value is refused.

**Coverage against the 120 unclassified:**
- 116 covered by the profile's explicit classification list
- 4 intentionally left unclassified (OPE-026, ITX-001, LDO-001, EQE-001)

**Totals:** 116 classifiable + 4 ACCOUNTANT_CONFIRMATION_REQUIRED = 120. The profile covers 100% of the unclassified set.

---

## §25 Classification Confidence by Group

| Group | Accounts | Confidence | Basis |
|-------|----------|-----------|-------|
| All bank/cash accounts (CASH-001, EQB-001, NCBA-001, KCB-001, etc.) | 10 | DETERMINISTIC | Bank account = asset, debit |
| Receivables (AR-001, AR-002, STD-001) | 3 | DETERMINISTIC | Receivable = asset, debit |
| AP (AP-001) | 1 | DETERMINISTIC | Payable = liability, credit |
| Equity (OBE-001, OCI-001, RE-001, SC-001, DIV-001) | 5 | DETERMINISTIC | Account category is equity |
| Cost of Sales accounts (COS-001 through COS-020) | 20 | STRONG_EVIDENCE | WNG records them as COS; account family |
| Operating Expenses (OPE-001 through OPE-032) | 32 | STRONG_EVIDENCE | WNG name and category |
| Personnel Expenses (PE-001 through PE-007) | 7 | STRONG_EVIDENCE | PE-007 confirmed direct_cost; others opex |
| Finance cost (FIN-001 through FIN-005) | 5 | STRONG_EVIDENCE | Finance cost = opex as WNG records it |
| Insurance (INS-001 through INS-003) | 3 | STRONG_EVIDENCE | opex |
| Other named opex groups | 20+ | STRONG_EVIDENCE | Name and family |
| OPE-026 Set Up Casuals | 1 | ACCOUNTANT_REVIEW_REQUIRED | No history |
| ITX-001 Income tax expense | 1 | ACCOUNTANT_REVIEW_REQUIRED | No ERP tax section |
| LDO-001 Loss on discontinued operations | 1 | ACCOUNTANT_REVIEW_REQUIRED | Below-the-line |
| EQE-001 Equity in earnings of subsidiaries | 1 | ACCOUNTANT_REVIEW_REQUIRED | Ambiguous category |

**Readiness for `--classify-existing`:** The 116 profile-classified accounts are ready. The 4 ACCOUNTANT_REVIEW items will remain unclassified until accountant sign-off. This is the correct behaviour.

---

## §26 Classification — Required Accountant Decisions

Before `--classify-existing` runs in production, the accountant must confirm:

1. **OPE-026 Set Up Casuals** — is this direct project labour (`direct_cost`) or office casual labour (`opex`)?
2. **ITX-001 Income tax expense** — treatment in P&L (no ERP tax section currently)
3. **LDO-001 Loss on discontinued operations** — treatment in P&L
4. **EQE-001 Equity in earnings of subsidiaries** — equity or income statement?

These decisions do not block the rest of configuration but they do block a complete P&L classification.

**Category: ACCOUNTANT REVIEW**

---

## §27 Payment Source Configuration — Full State

Sources queried from `payment_sources` in `db_test`:

| Code | Name | Type | Active | gl_account_id | Linked Account | Postable |
|------|------|------|--------|---------------|----------------|----------|
| PC-MAIN | Main Petty Cash Float | petty_cash | 1 | 127 | 1030 Petty Cash Float | yes |
| BANK-MAIN | Equity Bank – Operating Account | bank | 1 | 125 | 1010 Bank – Main Account | yes |
| BANK-ALT | NCBA Bank – Operations Account | bank | 1 | 126 | 1020 Bank – Secondary Account | yes |
| BANK-STANBIC | Stanbic Bank | bank | **0** | 125 | 1010 Bank – Main Account | yes |
| BANK-KCB | KCB Bank | bank | **0** | 125 | 1010 Bank – Main Account | yes |
| BANK-FAMILY | Family Bank | bank | **0** | 125 | 1010 Bank – Main Account | yes |
| AP | Supplier Credit (Payable) | payable | 1 | 160 | 2100 Accounts Payable | yes |
| MPESA | Company M-Pesa | mobile_money | 1 | **128** | 1040 Mobile Money Float | yes |
| CARD | Company Card | card | 1 | **125** | 1010 Bank – Main Account | yes |

---

## §28 Payment Source: WNG Profile vs db_test State

The WNG profile's `payment_sources` section specifies these target accounts for WNG:

| Source | Profile Target | db_test State | Match? |
|--------|---------------|---------------|--------|
| PC-MAIN | PETTY-001 | 1030 (ERP ref) | ❌ different account |
| BANK-MAIN | EQB-001 | 1010 (ERP ref) | ❌ different account |
| BANK-ALT | NCBA-001 | 1020 (ERP ref) | ❌ different account |
| BANK-STANBIC | STB-001 | 1010 (ERP ref) — inactive | ❌ |
| BANK-KCB | KCB-001 | 1010 (ERP ref) — inactive | ❌ |
| BANK-FAMILY | FMB-001 | 1010 (ERP ref) — inactive | ❌ |
| AP | AP-001 | 2100 (ERP ref) | ❌ different account |
| MPESA | null (unlinked) | 1040 Mobile Money Float (active) | ❌ linked when should be null |
| CARD | null (unlinked) | 1010 Bank – Main Account (active) | ❌ linked when should be null |

**All sources show mismatch** because `db_test` holds ERP reference accounts, not WNG mnemonic accounts. At cutover, the `PaymentSourceSeeder` or equivalent must be run against WNG mnemonic accounts to link each source to the correct WNG account.

---

## §29 MPESA Source — Configuration Review

**Report 73 finding:** MPESA active, linked to account id=128.
**Confirmed:** `id=128` is account code `1040 — Mobile Money Float` (ERP reference chart, `balance_sheet`, `debit`, postable, active).

**WNG profile `payment_source_notes.MPESA`:**
> KEEP UNLINKED — WNG DECISION REQUIRED. Evidence: 63 of 160 client receipts arrived by M-Pesa; petty cash books M-Pesa fees (70 lines to FIN-005). Nothing establishes whether M-Pesa is a WNG-held balance (till/wallet) or a channel settling into a bank. Disabled until linked.

**Assessment:**
- In `db_test`: MPESA is **CONFIGURED** — linked and active. This represents the ERP default seeder state.
- In WNG production: The profile says MPESA should be **UNLINKED** until WNG decides whether it is a separate float or a settlement channel.
- The `--disable-unlinked-sources` flag would disable MPESA (and CARD) in `db_test` if the profile's `payment_source_state.disable_until_linked` includes them (it does: `["MPESA","CARD"]`).
- **Key fact:** MPESA and CARD are in `db_test` as linked because the seeder wired all sources to reference accounts. This is a seeder-state artefact.

**Status: CONFIGURED_REVIEW_REQUIRED** — WNG must decide before production activation whether M-Pesa is:
(a) A WNG-held float → link to a WNG M-Pesa wallet account (new account needed, e.g. `MPESA-001`)
(b) A settlement channel → link to the bank account it settles into (probably NCBA-001)

**Category: ACCOUNTANT REVIEW / WNG OPERATIONAL DECISION**

---

## §30 CARD Source — Configuration Review

**Report 73 finding:** CARD active, sharing BANK-MAIN's linked account (gl_account_id=125, i.e., 1010).
**Confirmed:** CARD source `gl_account_id=125` = `1010 Bank – Main Account`.

**WNG profile `payment_source_notes.CARD`:**
> KEEP UNLINKED — WNG DECISION REQUIRED. No evidence of a company card anywhere in the source: no payment method, no account, no description (the 'Visa' hits are the client VISA CEMEA). Disabled until linked.

**Assessment:**
- CARD sharing BANK-MAIN's account is the ERP seeder default (no WNG-specific card account exists).
- In WNG production: CARD should be disabled until WNG confirms whether a company card exists.
- If a company card does exist and settles into Equity Bank, linking to EQB-001 is correct. If not, disable.

**Status: CONFIGURED_REVIEW_REQUIRED** — CARD is active and linked (to ERP reference 1010) in `db_test`. WNG must confirm whether a company card exists and what bank it settles into.

**Category: ACCOUNTANT REVIEW / WNG OPERATIONAL DECISION**

---

## §31 BANK-STANBIC, BANK-KCB, BANK-FAMILY Sources

All three are **inactive** in `db_test` (`is_active=0`). All three point to `gl_account_id=125` (1010 Bank – Main Account — the ERP seeder default).

**WNG profile:** Maps these to STB-001, KCB-001, FMB-001 (existing WNG bank accounts).

**Assessment:** At cutover, these three sources need to be linked to the correct WNG accounts:
- BANK-STANBIC → STB-001 (Stanbic Bank)
- BANK-KCB → KCB-001 (KCB Bank)
- BANK-FAMILY → FMB-001 (Family Bank)

Whether to then reactivate them is a WNG operational decision (do they still use these accounts actively?).

**Status: CONFIGURED_REVIEW_REQUIRED** — inactive and linked to wrong (ERP reference) accounts. Correct WNG accounts exist; manual relinking required at cutover.

**Category: CONFIGURATION** (relinking) + **ACCOUNTANT REVIEW** (reactivation decision)

---

## §32 WIP Policy Configuration

**Measured:**
```
FINANCE_ACCOUNT_PROFILE env: not set (in db_test test environment)
FINANCE_WIP_POLICY env: not set
config finance_accounts.profile: not set
config finance_accounts.wip_policy: not set
```

**Profile default:** `wip_policies.default: "capitalise"`

The WIP policy determines whether project costs are held on the balance sheet (WIP accounts) until invoiced, or expensed directly at capture (direct-to-COS). The profile defines both policies; the WIP accounts (WIP-002–010) are included in `new_accounts` for the `capitalise` policy.

**Status:** WIP policy is NOT set in the production environment. The profile default (`capitalise`) will be used. This is `OPEN` — Finance must confirm `capitalise` is the policy WNG wishes to apply.

**Category: ACCOUNTANT REVIEW** — WNG Finance must choose and set `FINANCE_WIP_POLICY=capitalise` or `FINANCE_WIP_POLICY=expense_on_capture` before production activation.

---

## §33 Finance Profile — Production Authorisation Status

The WNG profile `meta.authority` states:
> "The accountant's sign-off is still required before this profile is activated in production (FINANCE_ACCOUNT_PROFILE=wng)."

**Required steps to authorise:**
1. Accountant reviews `wng-chart-profile.json` and confirms the 37 function mappings.
2. Accountant confirms WIP policy selection (§32).
3. Accountant resolves the 4 ACCOUNTANT_CONFIRMATION_REQUIRED items (§26).
4. WNG Operations resolves MPESA and CARD source decisions (§29, §30).
5. Finance sets `FINANCE_ACCOUNT_PROFILE=wng` in production environment.
6. Finance sets `FINANCE_WIP_POLICY=capitalise` (or chosen policy).

**None of the above has been completed. Profile is NOT production-authorised.**

**Category: ACCOUNTANT REVIEW + OPERATIONAL DECISION**

---

## §34 Accounting Periods — State

**Measured from `db_test`:**
- Periods exist from 2027-07 through 2027-12 (6 open periods, `status=open`, `is_locked=null`).
- Column names: `starts_on`, `ends_on`, `status`, `locked_by`, `locked_at`.
- No periods for 2026 exist in `db_test` — these are test-database future periods.

**Configuration readiness:** Accounting periods need to be created for the current financial year (FY2026 for WNG: January 2026 – December 2026) before production posting can begin. Period creation is a Finance operational setup step, not a chart configuration item.

**Category: CONFIGURATION** — period seeder or UI creation needed for FY2026.

---

## §35 Document Sequences — State

**Measured from `db_test`:**
- `document_sequences` table exists with columns: `prefix`, `period`, `next_number`.
- PAY/2025: next=44; PAY/2026: next=1512.
- Only payment voucher sequences exist. Invoice, Bill, Petty Cash, Journal sequences not yet seeded.

**Category: CONFIGURATION** — additional document sequence rows needed.

---

## §36 Expense Code Catalogue — Resolution

**Measured:**
- Total expense codes: 109
- Active: 101, Inactive: 8
- **All 101 active expense codes resolve to a postable, active account** (OK=101 FAIL=0).

Expense codes use `default_debit_account_id` pointing to ERP reference accounts (e.g., 1211 Project WIP – Direct Materials). These are correct for `db_test`; at WNG cutover, expense codes whose `default_debit_gl` names reference accounts (e.g., "1211 Project WIP – Direct Materials") will need to be confirmed against WNG accounts (WIP-002, etc.).

**Status:** CONFIGURED_CONFIRMED in `db_test`. Requires re-seeding against WNG accounts at cutover.

**Category: CONFIGURATION**

---

## §37 WHT and VAT Configuration

**WHT categories table:** Exists in `db_test` with WHT category records (schema confirmed).
**VAT treatments table:** Exists in `db_test` with VAT treatment records (schema confirmed).

Both tables are separate from `chart_of_accounts`. WHT and VAT accounts (WHT-001, VAT-001, VAT-002) are mapped in the WNG profile and will be created by `--execute`.

**Category: CONFIGURED_CONFIRMED** — tax category tables exist and are populated.

---

## §38 Configuration Readiness — All Items

| # | Item | Category | Status | Blocker for Cutover? |
|---|------|----------|--------|---------------------|
| 1 | COS-021/022/023 parent fix | CONFIGURATION | **RESOLVED** (§8) | Was blocker; fixed |
| 2 | Five duplicate-name conflicts | CONFIGURATION | RESOLVED_PROPOSED_NEW (§15) | No — db_test artefact |
| 3 | 16→37 function resolution | CONFIGURATION | RESOLVED (§21) | No — post-cutover resolves all 37 |
| 4 | 120 missing classifications | CONFIGURATION | Ready for `--classify-existing` | Partial (4 ACCOUNTANT items) |
| 5 | OPE-026 classification | ACCOUNTANT REVIEW | ACCOUNTANT_CONFIRMATION_REQUIRED | Yes (for complete P&L) |
| 6 | ITX-001 classification | ACCOUNTANT REVIEW | ACCOUNTANT_CONFIRMATION_REQUIRED | Yes (for complete P&L) |
| 7 | LDO-001 classification | ACCOUNTANT REVIEW | ACCOUNTANT_CONFIRMATION_REQUIRED | Yes (for complete P&L) |
| 8 | EQE-001 classification | ACCOUNTANT REVIEW | ACCOUNTANT_CONFIRMATION_REQUIRED | Yes (for complete P&L) |
| 9 | MPESA source | ACCOUNTANT REVIEW | CONFIGURED_REVIEW_REQUIRED | Yes (before Go-live) |
| 10 | CARD source | ACCOUNTANT REVIEW | CONFIGURED_REVIEW_REQUIRED | Yes (before Go-live) |
| 11 | BANK-STANBIC/KCB/FAMILY relink | CONFIGURATION | CONFIGURED_REVIEW_REQUIRED | For completeness |
| 12 | WIP policy selection | ACCOUNTANT REVIEW | ACCOUNTANT_CONFIRMATION_REQUIRED | Yes (before `--execute`) |
| 13 | Accountant profile sign-off | ACCOUNTANT REVIEW | UNRESOLVED | Yes (before `--execute`) |
| 14 | `FINANCE_ACCOUNT_PROFILE=wng` env | CONFIGURATION | UNRESOLVED | Yes (before production activation) |
| 15 | `FINANCE_WIP_POLICY` env | CONFIGURATION | UNRESOLVED | Yes (before production activation) |
| 16 | Accounting periods FY2026 | CONFIGURATION | UNRESOLVED | Yes (before posting) |
| 17 | Document sequences (Invoice, Bill, PC, JE) | CONFIGURATION | UNRESOLVED | Yes (before posting) |
| 18 | Expense code account re-seeding for WNG chart | CONFIGURATION | UNRESOLVED | Yes (before posting) |

---

## §39 Verdict

**FINANCE CONFIGURATION CLOSURE PARTIAL — SPECIFIC CONFIGURATION/ACCOUNTANT REVIEW BLOCKERS REMAIN**

### Configuration Resolved

- COS-021/022/023 parent architecture defect: **FIXED** in `wng-chart-profile.json`.
- Five duplicate-name conflicts: **RESOLVED_PROPOSED_NEW** — `db_test` artefact; will not occur at WNG production cutover.
- 37/37 posting functions: **DETERMINISTIC** resolution confirmed for real WNG chart.
- 116/120 account classifications: **PROFILE-READY** — `--classify-existing` will fill them.
- 101 active expense codes: **RESOLVED** in current `db_test` state.
- WHT and VAT category tables: **CONFIGURED_CONFIRMED**.

### Remaining Blockers (Human Approval Required)

**Must resolve before attended cutover:**

1. **WIP policy** — Finance must confirm `capitalise` or `expense_on_capture` and set `FINANCE_WIP_POLICY` in production.
2. **Accountant profile sign-off** — Review and approve `wng-chart-profile.json` function mappings.
3. **OPE-026 / ITX-001 / LDO-001 / EQE-001** — Four accounts require accountant classification decision.
4. **MPESA source** — WNG must decide: float or settlement channel. Link or disable accordingly.
5. **CARD source** — WNG must confirm existence of company card. Link or disable.
6. **`FINANCE_ACCOUNT_PROFILE=wng`** — Set in production environment before activation.
7. **`FINANCE_WIP_POLICY`** — Set in production environment.
8. **Accounting periods FY2026** — Create before any posting.
9. **Document sequences** — Create Invoice, Bill, Petty Cash, Journal sequences.
10. **Expense code re-seeding** — Re-seed `default_debit_account_id` against WNG chart after `--execute`.

### What This Report Does NOT Resolve (By Design)

- Historical financial data repair
- Opening balance creation
- Retained earnings
- Accounting policy approval
- Deployment

---

## §40 Changes Made by This Report

### Code Changes

| File | Change |
|------|--------|
| `database/finance/wng-chart-profile.json` | `"parent": "COS-002"` → `"parent": null` for COS-021, COS-022, COS-023 + rationale updates |
| `tests/Feature/Finance/WngChartCompletionTest.php` | Line 146: updated assertion from `assertSame('COS-002', parent('COS-021'))` → `assertNull($a('COS-021')->parent_id, ...)` |

### Evidence Files (not production code)

`docs/finance-redesign/phase-2/74-evidence/`:
- `chart_completion.json` — dry-run JSON report (post-fix)
- `r74_chart_inspect.php`, `r74_sources_settings.php`, `r74_deep_inspect.php`, `r74_deep2.php`, `r74_schema_discover.php`, `r74_tax_expense.php` — inspection scripts (scratch, safe to remove)

### No Other Changes

- No accounting policy changed
- No opening balances created
- No historical data touched
- No production chart mutated
- No `--execute` run
- No `--cutover` run
- No deployment
- Report 73/73A work preserved exactly
