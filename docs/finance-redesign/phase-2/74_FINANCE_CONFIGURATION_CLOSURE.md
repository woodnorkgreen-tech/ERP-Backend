# Report 74 — Finance Configuration Closure

**WNG ERP — Finance.** Chart reconciliation → account classification → function mapping → channel review → readiness recheck.
**Prepared:** 2026-10-06. **Nature:** controlled configuration analysis and implementation-readiness. No live chart cutover was executed.

> This report replaces an earlier Report 74 draft dated 2026-10-03, which is kept unedited in `74-evidence/superseded-2026-10-03/`. That draft's main conclusions do not survive checking against the databases (§5.1) and one of its profile edits broke the profile (§23.4). Nothing in it should be relied on.

---

## 1. Executive Summary

**The five "duplicate-name conflicts" are a symptom, not the problem.** The database Report 73 inspected (local `db`) holds **two complete charts**: WNG's 120 mnemonic accounts (created 2026-03-06) and the ERP's 88-account reference chart (seeded on top, 2026-10-02). Five reference accounts happen to share a name with an account the WNG profile proposes, so five refusals appeared. Checked by posting function rather than by name, **25 of the 29 proposed accounts** would duplicate a reference account already there, and **48 posting/catalogue references have two candidate accounts**. Five of those reference accounts already carry postings (eight lines from four test entries).

**On WNG's own chart there is no conflict at all.** Against the 123-account WNG chart (120 WNG + 3 ERP-added) the dry run plans 29 creations, 0 conflicts, 37/37 functions. WNG's chart has no VAT, withholding-tax, accrual, client-deposit, inventory, WIP, payroll-liability, prepayment or sales account under any name, so nothing exists to reuse and none of the 29 is an unnecessary duplicate.

**So the open question is which chart the cutover target holds, and I could not check that from here.** A project note dated 2026-09-29 records the production database (`woodnork_erp`) being hand-seeded to 208 accounts — the same two-chart shape as local `db`. If that is still its state, running the WNG profile there would refuse (correctly), and the answer is a decision about the chart, not five mappings. This is the single item that decides whether the cutover is the rehearsed one.

What was done:

| Area | Outcome |
|---|---|
| Five conflicts | Root cause established (two charts in one database). Not present on WNG's chart. |
| Explicit reuse | New, opt-in `reuse_existing {code, name}` on a profile account. Never by name; ten unsafe forms refused. WNG's profile uses none, because WNG's chart has nothing to reuse. |
| Duplicate prevention | New refusal: a proposed account is not created beside the reference account for the same posting function, whatever its name. |
| Dry run | Now completes with conflicts and prints the whole plan, the classification preview and paying-account state; exits non-zero when blocked. `--execute` still refuses before writing. |
| 37 functions | All accounted for on WNG's chart: 10 resolved existing, 21 resolved proposed-new, 6 resolve technically but need the accountant to confirm the account chosen. 0 conflict, 0 unresolved. |
| 120 unclassified | All 120 audited: 17 deterministic, 86 strong evidence, 17 accountant review (4 of them deliberately left unclassified). |
| Paying accounts | M-Pesa and Card: `CONFIGURED_REVIEW_REQUIRED` locally (reference-seeder defaults, not WNG facts), `NOT_CONFIGURED` in the rehearsal. AP is guarded on both the paying and receiving side. |
| Earlier draft's profile edit | Reverted: it made the profile disagree with the rehearsal it had already been executed against. |
| Mutation | None. No `--execute`, no `--cutover`, no live flag, no seeder, no commit. Chart row counts identical before and after. |

**Verdict: FINANCE CONFIGURATION CLOSURE PARTIAL — SPECIFIC CONFIGURATION/ACCOUNTANT REVIEW BLOCKERS REMAIN** (§30). The tooling and the plan are complete for WNG's chart; the target's chart identity is unconfirmed and is a hard precondition.

---

## 2. Git Baseline

| | Backend (`ERP-Backend`) | Frontend (`ERP-Frontend`) |
|---|---|---|
| Branch | `master` | `master` |
| HEAD | `5a5a3db738110d153610e3e5b6e5da4d9ab2d4db` | `b82f27895c3d05e67d7d6029224d016783c8f127` |
| Status at start | 44 changed/untracked paths | 10 changed/untracked paths |
| Status at end | same set, plus the files in §27 | unchanged — **no frontend code was modified** |

Both worktrees carried intentional uncommitted work (Report 73/73A and other streams). No reset, discard, stash, merge, commit, push or deploy was performed. Two files that an earlier Report 74 draft had edited (`database/finance/wng-chart-profile.json`, `tests/Feature/Finance/WngChartCompletionTest.php`) were returned to their committed content before further work, for the reason in §23.4; the test file was then extended.

The frontend HEAD differs from the one the earlier draft recorded (`bb2e2af…`); the current value above was read directly.

---

## 3. Safety Confirmation

Not run, at any point: `finance:complete-chart … --execute`, `--cutover`, a live `--classify-existing`, a live `--disable-unlinked-sources`, any seeder, any migration, any period create/close/reopen, any opening balance or retained-earnings entry, any history repair, any deploy.

`--classify-existing` was passed only inside the automated tests, against the disposable test database.

**Queue workers.** DDEV was stopped when the work began, and its configuration starts a `stores-finance-worker` queue daemon with the web container. To honour "do not start queue workers", DDEV was **not** started. Only the database container was started, and artisan and the tests ran in one-off containers from the DDEV web image, which run no supervisor and therefore no worker. `jobs` and `failed_jobs` in `db` were both empty.

**Disclosure.** At the end of the work the DDEV web container was found running, with its `queue:work` daemon, started at 09:19 local time on 2026-10-06. No command in this stream started it (this stream ran only `docker start` on the database container and one-off `docker run` containers); it was started from outside this session while the work was under way. It was left as found. Checked afterwards: `jobs` in `db` is still empty, `journal_entries` is still 4 and `chart_of_accounts` still 208, so the worker processed nothing. Separately, when tidying up, this stream stopped the database container for a few seconds before noticing the web container was up, and restarted it immediately; anyone using the local app at that moment would have seen a brief database error.

**Databases touched.**

| Database | What it is | Access |
|---|---|---|
| `db` | Local development database; what Report 73 inspected | Read only |
| `wng_source_rehearsal` | WNG's chart as loaded for the rehearsal, before completion (123 accounts) | Read only |
| `wng_target_rehearsal` | Rehearsal target, chart already completed (152 accounts) | Read only |
| `woodnork_erpsystem`, `wng_source_pristine` | Local copies of the live source snapshot of 2026-09-28 (120 accounts) | Read only |
| `db_test` | Disposable test database | Written by the test suite only |

Chart row counts and the unclassified count were re-read after all work and are identical to the start (`db` 208/120, `wng_source_rehearsal` 123/120, `wng_target_rehearsal` 152/4).

**No production system was reachable or contacted.** Everything said about production below is either inference from these copies or a dated note, and is labelled as such.

---

## 4. Initial Dry Run

Command, exactly: `php artisan finance:complete-chart --profile=wng` (no `--execute`). Evidence: `74-evidence/03-initial-dry-run-*.txt`.

| | Local `db` | `wng_source_rehearsal` | `wng_target_rehearsal` |
|---|---|---|---|
| Profile | wng | wng | wng |
| Exit status | **1** | 0 | 0 |
| Result | REFUSED, five duplicate-name conflicts | 29 create | 29 exist |
| Plan printed | **None** — the command stopped before printing | Yes | Yes |
| Functions resolving | not reported (16/37 by separate inspection, Report 73) | 37 / 37 | 37 / 37 |
| Classification warnings | not reported | not reported | not reported |

The run on `db` reproduces Report 73 exactly: the five refusals named in the brief, exit 1, nothing else. The command gave no proposed accounts, no existing matches, no function resolution and no classification information once it met a conflict; that is corrected in §23.

`wng_target_rehearsal` was run after the earlier draft's profile edit had been reverted. With that edit in place it refused with three "different definition" errors (§23.4).

### Why 16 of 37

16 is not a defect count. The WNG profile sends 15 functions to accounts WNG already has and 1 to the ERP-added `2160`; those 16 resolve. The other 21 are sent to accounts the profile proposes to create, which do not exist until the chart is completed. On WNG's chart the same 16 resolve before completion and all 37 after. On local `db` the 21 are blocked from being created (§5), so it stays at 16.

---

## 5. Five Conflict Investigation

### 5.1 What the conflicting accounts are

All five existing accounts are **reference-chart accounts**: the four-digit accounts `ChartOfAccountSeeder` creates where the ERP is the first system to hold a chart. They are not WNG accounts.

| Check | Finding | Evidence |
|---|---|---|
| Where they exist | Only in local `db` (and `db_consolidation_test`, a copy of it). Absent from all four WNG-shaped databases, including both copies of the live source snapshot | `02-wng-chart-equivalents.txt` |
| When they were created | `db` holds 120 mnemonic accounts created 2026-03-06 and 88 numeric accounts created 2026-10-02 12:49:51 — one seeding run on top of WNG's chart | `02`, provenance histogram |
| Why local seeds them | `finance_accounts.seed_reference_chart` defaults to **true** outside production; in the main checkout it is `true` and no profile is active | `08-readiness-recheck.txt` |
| What refuses them | `CompleteChartCommand::plan()` compares a proposed account's name, lower-cased and whitespace-normalised, with every existing name, and refuses a match under another code. This is deliberate and is kept | code, lines 240–243 before this report |

The earlier draft said these conflicts came from `db_test` and would not occur in production. Both halves are unsupported: `db_test` holds 3 accounts and was never the database inspected, and whether production carries the reference chart is unknown (§5.4).

### 5.2 Due diligence on each existing account (local `db`)

`chart_of_accounts` has no description column; purpose is established from the code reference below, not from the name.

| | 2110 | 1330 | 2120 | 2150 | 2200 |
|---|---|---|---|---|---|
| Name | Output VAT Payable | Input VAT Recoverable | Withholding Tax Payable | Accrued Expenses | Client Deposits |
| Proposed counterpart | VAT-001 | VAT-002 | WHT-001 | AE-001 | CD-001 |
| Category / type | liability / balance_sheet | asset / balance_sheet | liability / balance_sheet | liability / balance_sheet | liability / balance_sheet |
| Normal balance | credit | debit | credit | credit | credit |
| Parent (header) | 2000 | 1000 | 2000 | 2000 | 2000 |
| Postable / active | yes / yes | yes / yes | yes / yes | yes / yes | yes / yes |
| Child accounts | 0 | 0 | 0 | 0 | 0 |
| Created | 2026-10-02 | 2026-10-02 | 2026-10-02 | 2026-10-02 | 2026-10-02 |
| Journal lines | 0 | 0 | 0 | 2 | 1 |
| Balance (credits − debits) | 0.00 | 0.00 | 0.00 | 1,000.00 Cr | 500,000.00 Cr |
| Source of those lines | — | — | — | GRN accruals `GRN-2026-0001`, `-0002` (2 Oct) | Client receipt ref. `hhhhhhh` (3 Oct) |
| Expense codes pointing at it | 0 | 1 | 1 | 0 | 1 |
| Tax tables pointing at it | 0 | 2 VAT treatments | 2 WHT categories | 0 | 0 |
| Paying accounts / posting rules / invoice lines / cash movements | 0 | 0 | 0 | 0 | 0 |
| Explicit code reference | `FinanceAccountFunctions::OUTPUT_VAT` | `::INPUT_VAT` | `::WHT_PAYABLE` | `::ACCRUED_EXPENSES` | `::CLIENT_DEPOSITS` |
| Function the proposed account serves | `output_vat` | `input_vat` | `wht_payable` | `accrued_expenses` | `client_deposits` |

The postings are development test entries (the receipt reference is `hhhhhhh`; the database holds four journal entries in total). They are not WNG history.

### 5.3 Purpose determination

The determination rests on code identity, not on the shared name: each existing account's code **is** the reference code of the posting function that the proposed account is declared to serve.

| Pair | On a chart that carries the reference accounts (local `db`) | On WNG's own chart |
|---|---|---|
| VAT-001 ↔ 2110 | **EXACT PURPOSE MATCH** — both are the `output_vat` account | **NOT A MATCH** — no such account exists |
| VAT-002 ↔ 1330 | **EXACT PURPOSE MATCH** — `input_vat` | **NOT A MATCH** |
| WHT-001 ↔ 2120 | **EXACT PURPOSE MATCH** — `wht_payable` | **NOT A MATCH** |
| AE-001 ↔ 2150 | **EXACT PURPOSE MATCH** — `accrued_expenses` | **NOT A MATCH** |
| CD-001 ↔ 2200 | **EXACT PURPOSE MATCH** — `client_deposits` | **NOT A MATCH** |

"Not a match" on WNG's chart was tested beyond the five names: a search of all 123 accounts for VAT, tax, withholding, accrual, deposit, advance, prepayment, inventory, stock, WIP, payroll, PAYE, NSSF, statutory, levy, sales, revenue, income, improvement, unearned, deferred, customer and client returns only expense accounts (for example `VP-001 Vat Penalty`, `PE-005 NSSF Expense`), `OCI-001 Other comprehensive income`, and the ERP-added `2160`. WNG keeps no balance-sheet tax, accrual or deposit account.

### 5.4 The five are a subset of a larger duplication

The name check finds an existing account only when the two names are identical. The reference chart duplicates the WNG profile far more widely, under different names:

| Proposed | Reference account already in `db` | Journal lines on it | Caught by name check? |
|---|---|---|---|
| IA-001 Inventory Asset | 1200 Raw-material Inventory | 3 | No |
| WIP-002 Work in Progress:Materials | 1211 Project WIP – Direct Materials | 1 | No |
| SAL-002 Sales:Project Revenue | 4100 Project Revenue | 0 | No |
| PL-002 Payroll liabilities:PAYE | 2130 PAYE Payable | 0 | No |
| … 16 more (WIP-003…010, PL-003, COS-021…023, PRE-002…004, OI-001) | 1212…1219, 2140, 5500/5600/5800, 1310/1320/1340, 1600 | 0 | No |

In addition, 14 functions and 9 catalogue references that the profile sends to **existing** WNG accounts also have a reference twin in `db` (`bank_default` → EQB-001 beside 1010; `petty_cash_float` → PETTY-001 beside 1030, which carries a posting; `accounts_receivable` → AR-001 beside 1100; and so on). Full list: `07-final-dry-run-db.txt` and `final-db/chart_completion.json` (`reference_twins`, 48 unacknowledged).

Had the five name conflicts been "resolved" one at a time, the command would have gone on to create 20 further accounts beside working reference accounts, two of which (1200, 1211) already hold postings.

### 5.5 What production holds

Not verifiable from this workstation. What is on record:

- 2026-09-28, operator-verified: the redesigned target `woodnork_erp` had zero tables.
- 2026-09-29, incident note: `woodnork_erp` was not empty; it held about 120 accounts, the reference chart was seeded by hand to fix a purchase-category outage, and the count afterwards was **208 accounts**.

208 is exactly local `db`. If production still holds 208 accounts it has the same two-chart shape, and every finding in §5.4 applies to it. If the planned source-to-target migration (Report 50, which replace-seeds `chart_of_accounts` by code) runs first, the target holds WNG's 123 accounts and none of it applies. **Establishing which is step 3 of the cutover plan (§29) and gates everything after it.** Category: DATA.

---

## 6. Existing Account Reuse Decisions

### 6.1 Did the profile already support reuse?

Partly. A function may name any existing account directly (`functions.net_payroll_payable.account = "2160"`, basis `ERP_ADDED`), and that is how 16 functions already use existing accounts. There was no way to say "this proposed account is already met by that existing one": `new_accounts` entries were create-or-refuse. Pointing the five functions at 2110 etc. by hand would also have required deleting the five `new_accounts` entries, which the brief rules out ("do not delete the proposed profile requirement").

### 6.2 The mechanism added

A `new_accounts` entry may carry:

```json
{ "code": "VAT-001", "name": "Output VAT Payable", "...": "...",
  "reuse_existing": { "code": "2110", "name": "Output VAT Payable" } }
```

Meaning: *the requirement VAT-001 describes is met by existing account 2110; create nothing.* Every mapping that names `VAT-001` (functions, both WIP policies, catalogue, paying accounts) then resolves to `2110`. The requirement stays in the profile; no existing account is renamed, renumbered or modified; no journal reference changes.

Rules, each enforced and tested (§25):

| Rule | Behaviour |
|---|---|
| Explicit only | With no declaration, a same-named account refuses exactly as before. Nothing is matched by name, similarity or "first found". |
| Two keys | The declaration must give both `code` and `name`, and the account at that code must carry that name. A mistyped code lands on a differently named account and is refused. |
| Must exist | A code not in the chart is refused: "Nothing is reused by name or by guess." |
| Must be usable | Refused if inactive; refused if its postability differs from the requirement (a header cannot meet a postable requirement, nor the reverse). |
| Must agree | Refused if category differs, or if a non-NULL account type or normal balance differs. A NULL (not yet classified) is not treated as a contradiction. |
| One for one | Refused if another requirement already reuses the same account, if the named account is itself one the profile proposes, or if the proposed code also exists in the chart. |
| No guard loosened | `--confirm`, the live-source refusal and `--cutover` apply unchanged. |

### 6.3 Decisions

| Requirement | Decision for WNG's profile | Reason |
|---|---|---|
| VAT-001, VAT-002, WHT-001, AE-001, CD-001 | **No reuse declared. Remain proposed-new.** | WNG's chart has no account to reuse (§5.3). Declaring `2110` etc. would make the profile refuse on WNG's own chart, where those codes do not exist. |
| The other 24 proposed accounts | No reuse declared | Same: no WNG equivalent (§8). |
| A target that carries the reference chart | **Not decided here.** | Reuse is then technically exact (§5.3), but adopting 25 reference accounts into WNG's chart contradicts decision D3 Option A ("WNG keeps its chart"). That is a chart-identity decision for WNG and the accountant, category ACCOUNTANT REVIEW. The mechanism exists so that, if they choose it, it is declared rather than improvised. |

`database/finance/wng-chart-profile.json` is therefore **unchanged from the committed version**.

---

## 7. 37-Function Mapping Matrix

Resolved against WNG's chart (`wng_source_rehearsal`, 123 accounts) under WIP policy `capitalise`, which is the profile default and not an approved policy (§17). Machine output: `final-wng_source_rehearsal/chart_completion.json`. The last column is the same function on local `db`.

"NULL→x" means the account is unclassified today and the profile proposes x (§10). Status `ACCOUNTANT_CONFIRMATION_REQUIRED` is applied where the account resolves but the profile itself records a judgement about using it; the tool reports these as `RESOLVED_EXISTING` and they are **not** counted as confirmed here.

| Function | Required semantic purpose | Code | Account name | Source | Type | Balance | Postable | Active | Resolution status (WNG chart) | Evidence | Review requirement | Dev `db` (two charts) |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `bank_default` | Default operating bank account | EQB-001 | Equity Bank | existing WNG account | NULL→balance_sheet | NULL→debit | yes | yes | RESOLVED_EXISTING | In WNG chart; active, postable | None beyond profile sign-off. | RESOLVED_EXISTING |
| `petty_cash_float` | Petty cash float (cash on hand) | PETTY-001 | Petty cash | existing WNG account | NULL→balance_sheet | NULL→debit | yes | yes | RESOLVED_EXISTING | In WNG chart; active, postable | None beyond profile sign-off. | RESOLVED_EXISTING |
| `accounts_receivable` | Amounts clients owe | AR-001 | Accounts Receivable (A/R) | existing WNG account | NULL→balance_sheet | NULL→debit | yes | yes | RESOLVED_EXISTING | In WNG chart; active, postable | None beyond profile sign-off. | RESOLVED_EXISTING |
| `staff_advances` | Staff advances / imprest (asset) | STD-001 | Short Term Debtors | existing WNG account | NULL→balance_sheet | NULL→debit | yes | yes | ACCOUNTANT_CONFIRMATION_REQUIRED | In WNG chart; active, postable | STD-001 is "Short Term Debtors", not a named staff-advance account. Profile: correct only if WNG uses STD-001 for nothing else. | RESOLVED_EXISTING |
| `input_vat` | Input VAT recoverable (asset) | VAT-002 | Input VAT Recoverable | proposed new account | balance_sheet | debit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | None beyond profile sign-off; no WNG equivalent exists (§8). | CONFLICT |
| `inventory` | Raw-material inventory (asset) | IA-001 | Inventory Asset | proposed new account | balance_sheet | debit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | None beyond profile sign-off; no WNG equivalent exists (§8). | CONFLICT |
| `wip_direct_materials` | Project work in progress: direct materials | WIP-002 | Work in Progress:Materials | proposed new account | balance_sheet | debit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | Created only if WIP policy "capitalise" is signed off (POLICY). | CONFLICT |
| `wip_direct_labour` | Project work in progress: direct labour | WIP-003 | Work in Progress:Direct Labour | proposed new account | balance_sheet | debit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | Created only if WIP policy "capitalise" is signed off (POLICY). | CONFLICT |
| `wip_subcontractors` | Project WIP: subcontractors | WIP-004 | Work in Progress:Subcontractors | proposed new account | balance_sheet | debit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | Created only if WIP policy "capitalise" is signed off (POLICY). | CONFLICT |
| `wip_transport_logistics` | Project WIP: transport and logistics | WIP-005 | Work in Progress:Transport & Delivery | proposed new account | balance_sheet | debit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | Created only if WIP policy "capitalise" is signed off (POLICY). | CONFLICT |
| `wip_equipment_site` | Project WIP: equipment and site | WIP-006 | Work in Progress:Equipment Hire & Site | proposed new account | balance_sheet | debit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | Created only if WIP policy "capitalise" is signed off (POLICY). | CONFLICT |
| `wip_project_utilities` | Project WIP: project utilities | WIP-007 | Work in Progress:Project Utilities | proposed new account | balance_sheet | debit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | Created only if WIP policy "capitalise" is signed off (POLICY). | CONFLICT |
| `wip_project_facilitation` | Project WIP: project facilitation (meals, accommodation, per diem) | WIP-008 | Work in Progress:Field Facilitation | proposed new account | balance_sheet | debit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | Created only if WIP policy "capitalise" is signed off (POLICY). | CONFLICT |
| `wip_venue_statutory` | Project WIP: venue and statutory | WIP-009 | Work in Progress:Venue & Permits | proposed new account | balance_sheet | debit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | Created only if WIP policy "capitalise" is signed off (POLICY). | CONFLICT |
| `wip_rework_warranty` | Project WIP: rework and warranty | WIP-010 | Work in Progress:Rework & Warranty | proposed new account | balance_sheet | debit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | Created only if WIP policy "capitalise" is signed off (POLICY). | CONFLICT |
| `accounts_payable` | Amounts owed to suppliers and payees | AP-001 | Accounts Payable (A/P) | existing WNG account | NULL→balance_sheet | NULL→credit | yes | yes | RESOLVED_EXISTING | In WNG chart; active, postable | None beyond profile sign-off. | RESOLVED_EXISTING |
| `output_vat` | Output VAT payable | VAT-001 | Output VAT Payable | proposed new account | balance_sheet | credit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | None beyond profile sign-off; no WNG equivalent exists (§8). | CONFLICT |
| `wht_payable` | Withholding tax payable | WHT-001 | Withholding Tax Payable | proposed new account | balance_sheet | credit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | None beyond profile sign-off; no WNG equivalent exists (§8). | CONFLICT |
| `paye_payable` | PAYE payable | PL-002 | Payroll liabilities:PAYE | proposed new account | balance_sheet | credit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | None beyond profile sign-off; no WNG equivalent exists (§8). | CONFLICT |
| `statutory_payable` | Statutory deductions payable (NSSF, SHIF, housing levy) | PL-003 | Payroll liabilities:Statutory & Other Deductions | proposed new account | balance_sheet | credit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | None beyond profile sign-off; no WNG equivalent exists (§8). | CONFLICT |
| `accrued_expenses` | Goods received not yet invoiced / accrued expenses | AE-001 | Accrued Expenses | proposed new account | balance_sheet | credit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | None beyond profile sign-off; no WNG equivalent exists (§8). | CONFLICT |
| `net_payroll_payable` | Net pay owed to staff | 2160 | Net Payroll Payable | existing ERP-added account | balance_sheet | credit | yes | yes | RESOLVED_EXISTING | In WNG chart; active, postable | None: account added by migration 2026_08_24_000001; no WNG duplicate. | RESOLVED_EXISTING |
| `client_deposits` | Client deposits received before revenue is earned | CD-001 | Client Deposits | proposed new account | balance_sheet | credit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | None beyond profile sign-off; no WNG equivalent exists (§8). | CONFLICT |
| `opening_balance_equity` | Opening balance equity (opening stock counts) | OBE-001 | Opening Balance Equity | existing WNG account | NULL→balance_sheet | NULL→credit | yes | yes | RESOLVED_EXISTING | In WNG chart; active, postable | None beyond profile sign-off. | RESOLVED_EXISTING |
| `project_revenue` | Project revenue | SAL-002 | Sales:Project Revenue | proposed new account | revenue | credit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | None beyond profile sign-off; no WNG equivalent exists (§8). | CONFLICT |
| `cos_direct_materials` | Cost of sales: direct materials (WIP release) | COS-008 | Cost of Sales:Materials | existing WNG account | NULL→direct_cost | NULL→debit | yes | yes | RESOLVED_EXISTING | In WNG chart; active, postable | None beyond profile sign-off. | RESOLVED_EXISTING |
| `cos_direct_labour` | Cost of sales: direct labour (WIP release; payroll direct labour) | PE-007 | Personnel Expenses:Wages-Direct Labour | existing WNG account | NULL→direct_cost | NULL→debit | yes | yes | ACCOUNTANT_CONFIRMATION_REQUIRED | In WNG chart; active, postable | PE-007 sits in WNG's Personnel Expenses family; treating it as cost of sales (direct_cost) is the profile's recorded open decision. | RESOLVED_EXISTING |
| `cos_subcontractors` | Cost of sales: subcontractors (WIP release) | COS-020 | Subcontractors - COS | existing WNG account | NULL→direct_cost | NULL→debit | yes | yes | RESOLVED_EXISTING | In WNG chart; active, postable | None beyond profile sign-off. | RESOLVED_EXISTING |
| `cos_transport_logistics` | Cost of sales: transport and logistics (WIP release) | COS-016 | Cost of Sales:Transport & Delivery | existing WNG account | NULL→direct_cost | NULL→debit | yes | yes | RESOLVED_EXISTING | In WNG chart; active, postable | None beyond profile sign-off. | RESOLVED_EXISTING |
| `cos_equipment_site` | Cost of sales: equipment and site (WIP release) | COS-021 | Cost of Sales:Equipment Hire & Site | proposed new account | direct_cost | debit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | None beyond profile sign-off; no WNG equivalent exists (§8). | CONFLICT |
| `cos_project_utilities` | Cost of sales: project utilities (WIP release) | COS-022 | Cost of Sales:Project Utilities | proposed new account | direct_cost | debit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | None beyond profile sign-off; no WNG equivalent exists (§8). | CONFLICT |
| `cos_project_facilitation` | Cost of sales: project facilitation (WIP release) | COS-006 | Cost of Sales:Field Facilitation | existing WNG account | NULL→direct_cost | NULL→debit | yes | yes | ACCOUNTANT_CONFIRMATION_REQUIRED | In WNG chart; active, postable | WNG also keeps COS-014 Team Meals and COS-015 Teams accomodation; the engine pools all facilitation into COS-006. | RESOLVED_EXISTING |
| `cos_venue_statutory` | Cost of sales: venue and statutory (WIP release) | COS-023 | Cost of Sales:Venue & Permits | proposed new account | direct_cost | debit | yes | yes | RESOLVED_PROPOSED_NEW | Not in WNG chart (0 name/code matches, evidence 02); profile creates it | None beyond profile sign-off; no WNG equivalent exists (§8). | CONFLICT |
| `cos_rework_warranty` | Cost of sales: rework and warranty (WIP release) | COS-018 | Other - COS | existing WNG account | NULL→direct_cost | NULL→debit | yes | yes | ACCOUNTANT_CONFIRMATION_REQUIRED | In WNG chart; active, postable | COS-018 is WNG's catch-all "Other - COS"; no WNG account is named for rework/warranty. | RESOLVED_EXISTING |
| `inventory_adjustments` | Inventory adjustments and shrinkage | INV-001 | Inventory Shrinkage | existing WNG account | NULL→opex | NULL→debit | yes | yes | ACCOUNTANT_CONFIRMATION_REQUIRED | In WNG chart; active, postable | INV-001 "Inventory Shrinkage" would also take count GAINS (net shrinkage). | RESOLVED_EXISTING |
| `salaries_expense` | Salaries and wages: office and admin staff (overhead) | PE-006 | Personnel Expenses:Salaries | existing WNG account | NULL→opex | NULL→debit | yes | yes | RESOLVED_EXISTING | In WNG chart; active, postable | None beyond profile sign-off. | RESOLVED_EXISTING |
| `bank_charges` | Bank and mobile-money transaction charges | FIN-003 | Finance cost:Bank charges | existing WNG account | NULL→opex | NULL→debit | yes | yes | ACCOUNTANT_CONFIRMATION_REQUIRED | In WNG chart; active, postable | All payment fees post to FIN-003 Bank charges although WNG keeps FIN-005 Mpesa charges separately. | RESOLVED_EXISTING |

**Totals on WNG's chart:** RESOLVED_EXISTING 10 · RESOLVED_PROPOSED_NEW 21 · ACCOUNTANT_CONFIRMATION_REQUIRED 6 · CONFLICT 0 · UNRESOLVED 0 = 37.
**On local `db`:** RESOLVED_EXISTING 16 · CONFLICT 21.

The whole mapping, not only the six flagged rows, still needs the accountant's sign-off before the profile is activated in production; that requirement is written into the profile (`meta.authority`) and is unchanged.

---

## 8. Remaining Proposed New Accounts

All 29 proposals, checked against WNG's 123-account chart.

| Code | Name | Code in WNG chart | Name in WNG chart | Category / type | Balance | State | Parent | Serves |
|---|---|---|---|---|---|---|---|---|
| SAL-001 | Sales | unique | unique | revenue / revenue | credit | header | — | — (header) |
| SAL-002 | Sales:Project Revenue | unique | unique | revenue / revenue | credit | postable | SAL-001 (new header) | project_revenue |
| VAT-001 | Output VAT Payable | unique | unique | liability / balance_sheet | credit | postable | — | output_vat |
| VAT-002 | Input VAT Recoverable | unique | unique | asset / balance_sheet | debit | postable | — | input_vat |
| WHT-001 | Withholding Tax Payable | unique | unique | liability / balance_sheet | credit | postable | — | wht_payable |
| PL-001 | Payroll liabilities | unique | unique | liability / balance_sheet | credit | header | — | — (header) |
| PL-002 | Payroll liabilities:PAYE | unique | unique | liability / balance_sheet | credit | postable | PL-001 (new header) | paye_payable |
| PL-003 | Payroll liabilities:Statutory & Other Deductions | unique | unique | liability / balance_sheet | credit | postable | PL-001 (new header) | statutory_payable |
| AE-001 | Accrued Expenses | unique | unique | liability / balance_sheet | credit | postable | — | accrued_expenses |
| CD-001 | Client Deposits | unique | unique | liability / balance_sheet | credit | postable | — | client_deposits |
| IA-001 | Inventory Asset | unique | unique | asset / balance_sheet | debit | postable | — | inventory |
| WIP-001 | Work in Progress | unique | unique | asset / balance_sheet | debit | header | — | — (header) |
| WIP-002 | Work in Progress:Materials | unique | unique | asset / balance_sheet | debit | postable | WIP-001 (new header) | wip_direct_materials |
| WIP-003 | Work in Progress:Direct Labour | unique | unique | asset / balance_sheet | debit | postable | WIP-001 (new header) | wip_direct_labour |
| WIP-004 | Work in Progress:Subcontractors | unique | unique | asset / balance_sheet | debit | postable | WIP-001 (new header) | wip_subcontractors |
| WIP-005 | Work in Progress:Transport & Delivery | unique | unique | asset / balance_sheet | debit | postable | WIP-001 (new header) | wip_transport_logistics |
| WIP-006 | Work in Progress:Equipment Hire & Site | unique | unique | asset / balance_sheet | debit | postable | WIP-001 (new header) | wip_equipment_site |
| WIP-007 | Work in Progress:Project Utilities | unique | unique | asset / balance_sheet | debit | postable | WIP-001 (new header) | wip_project_utilities |
| WIP-008 | Work in Progress:Field Facilitation | unique | unique | asset / balance_sheet | debit | postable | WIP-001 (new header) | wip_project_facilitation |
| WIP-009 | Work in Progress:Venue & Permits | unique | unique | asset / balance_sheet | debit | postable | WIP-001 (new header) | wip_venue_statutory |
| WIP-010 | Work in Progress:Rework & Warranty | unique | unique | asset / balance_sheet | debit | postable | WIP-001 (new header) | wip_rework_warranty |
| COS-021 | Cost of Sales:Equipment Hire & Site | unique | unique | expense / direct_cost | debit | postable | COS-002 (existing WNG) | cos_equipment_site |
| COS-022 | Cost of Sales:Project Utilities | unique | unique | expense / direct_cost | debit | postable | COS-002 (existing WNG) | cos_project_utilities |
| COS-023 | Cost of Sales:Venue & Permits | unique | unique | expense / direct_cost | debit | postable | COS-002 (existing WNG) | cos_venue_statutory |
| PRE-001 | Prepayments & Deposits | unique | unique | asset / balance_sheet | debit | header | — | — (header) |
| PRE-002 | Prepayments & Deposits:Supplier Advances | unique | unique | asset / balance_sheet | debit | postable | PRE-001 (new header) | catalogue:1310 |
| PRE-003 | Prepayments & Deposits:Refundable Deposits | unique | unique | asset / balance_sheet | debit | postable | PRE-001 (new header) | catalogue:1320 |
| PRE-004 | Prepayments & Deposits:Prepaid Expenses | unique | unique | asset / balance_sheet | debit | postable | PRE-001 (new header) | catalogue:1340 |
| OI-001 | Office Improvement | unique | unique | asset / balance_sheet | debit | postable | — | catalogue:1600 |

| Check | Result |
|---|---|
| Code uniqueness | 29 / 29 unused in WNG's chart |
| Name uniqueness | 29 / 29 unused in WNG's chart |
| Type and balance consistent with category | 29 / 29 (existing test `every_new_account_is_classified_consistently_with_its_category`) |
| Headers | SAL-001, PL-001, WIP-001, PRE-001 are non-postable; every child of a new header is postable |
| Orphans | None: every postable proposal is named by a function or a catalogue reference |
| Another WNG account with the same purpose | None found (§5.3 search). Nearest names are expense accounts, not the balance-sheet or revenue account required |
| ERP-added accounts | `2160` reused for net pay. `7150` and `7550` duplicate `OPE-030` and `PE-006`; the profile records them as unmapped duplicates recommended for deactivation. They are left untouched and reported by the dry run as acknowledged. |

Two observations for the accountant, neither changed here:

1. **COS-021/022/023 sit under `COS-002 Cost of Sales`, which is itself a postable account.** That is how QuickBooks sub-accounts work and how the rehearsal created them. The earlier draft removed the parent on the belief that the command forbids it; it does not (it forbids only a *new* postable header), and removing it broke the profile against the rehearsal (§23.4).
2. **WNG's chart has no revenue account at all** except `RI-001 Return Inwards` (contra-revenue). `SAL-002` would be the only account revenue posts to.

---

## 9. 120-Account Classification Audit

**The 120 are WNG's entire chart.** Every one of WNG's 120 mnemonic accounts arrived from QuickBooks with a category but with NULL `account_type` and NULL `normal_balance`. The same 120 codes are unclassified in local `db` and in `wng_source_rehearsal`; in `wng_target_rehearsal`, where the rehearsal applied the classification, 4 remain.

**Evidence available.** Category, code family and name hierarchy (QuickBooks `Parent:Child` names) for all 120. **Historical journal use is not available**: the source system has no journal tables, so no WNG account has posting history in the ERP. Where the audit says "no history" it means that. This limits how far any classification can be called evidenced, and is why only category-derived values are rated deterministic.

| Group | Accounts | Deterministic | Strong evidence | Accountant review |
|---|---|---|---|---|
| Assets | 13 | 12 | 0 | 1 (FK-001) |
| Liabilities | 1 | 1 | 0 | 0 |
| Equity | 5 | 4 | 0 | 1 (DIV-001) |
| Revenue | 1 | 0 | 0 | 1 (RI-001) |
| Cost of Sales | 21 | 0 | 17 | 4 (COS-001, COS-017, COS-019, PE-007) |
| Operating Expenses | 64 | 0 | 59 | 5 (INV-001, OTE-001, WE-001, UE-001, UCBPE-001) |
| Other Income | 0 | — | — | — |
| Other Expense (finance cost, penalties, non-cash) | 11 | 0 | 10 | 1 (EXD-001) |
| Unclear / Accountant Review | 4 | 0 | 0 | 4 (EQE-001, ITX-001, LDO-001, OPE-026) |
| **Total** | **120** | **17** | **86** | **17** |

Notes on the grouping:

- WNG's chart has **no other-income account**, and the ERP's `account_type` has no "other expense" value (`balance_sheet`, `direct_cost`, `overhead`, `opex`, `capex`, `revenue`). The eleven finance-cost, penalty and non-cash accounts are grouped separately above for the accountant's benefit, but the profile types them `opex`, as WNG presents them.
- "Cost of Sales" includes `PE-007 Wages-Direct Labour`, which WNG files under Personnel Expenses and the profile types `direct_cost`.

---

## 10. Classification Preview

### What `--classify-existing` would change

Read from the code and confirmed by test: for each of the 116 accounts the profile lists under `existing_classification.accounts`, it sets `account_type` and/or `normal_balance` **only where that column is NULL**. A different non-NULL value refuses the whole run. It never touches code, name, id, category, parent, postability, active flag or any balance, and never touches an account the profile does not list. It was **not run** against any database in this stream.

On WNG's chart that is 116 accounts × 2 columns = 232 values written, and 4 accounts left NULL.

The dry run now prints this preview by default, with the full before → proposed table under `-v` or in the JSON report (`classification_preview`): `would_fill` 116, `pending_decision` 4, `not_in_profile` 0. The last figure matters: no unclassified WNG account falls outside the profile.

### Confidence

- **DETERMINISTIC (17):** the proposed values follow mechanically from the category WNG already stored (asset → balance sheet, debit; liability and equity → balance sheet, credit). Candidates for automated classification.
- **STRONG_EVIDENCE (86):** normal balance follows from category `expense`; the type (`direct_cost` or `opex`) follows WNG's own family ("Cost of Sales:…", "Operating Expenses:…"). Profile-authoritative, but the type is a management-reporting choice that moves cost between gross margin and overheads, so it rests on the accountant's sign-off of the profile.
- **ACCOUNTANT_REVIEW_REQUIRED (17):** 13 the profile classifies while recording a judgement, and 4 it refuses to classify.

**A limit worth stating plainly:** the option applies all 116 listed accounts together. It cannot apply only the deterministic 17. The 13 judgement accounts are therefore applied with the rest unless the accountant has them removed from the profile first. That is a sign-off item (§28), not something this stream changed.

### Before → proposed, every account

**Assets** (13)

| Code | Name | Current type | Current balance | Proposed type | Proposed balance | Basis | Confidence | Review status |
|---|---|---|---|---|---|---|---|---|
| AR-001 | Accounts Receivable (A/R) | NULL | NULL | balance_sheet | debit | Follows from the stored category `asset` | DETERMINISTIC | Candidate for automated classification |
| AR-002 | Accounts Receivable (A/R) - EUR | NULL | NULL | balance_sheet | debit | Follows from the stored category `asset` | DETERMINISTIC | Candidate for automated classification |
| CASH-001 | Cash Account | NULL | NULL | balance_sheet | debit | Follows from the stored category `asset` | DETERMINISTIC | Candidate for automated classification |
| EQB-001 | Equity Bank | NULL | NULL | balance_sheet | debit | Follows from the stored category `asset` | DETERMINISTIC | Candidate for automated classification |
| FK-001 | Faulu Kenya | NULL | NULL | balance_sheet | debit | WNG records Faulu Kenya as an asset; whether it is a loan (liability) is unconfirmed. Classified as recorded. | ACCOUNTANT_REVIEW_REQUIRED | In profile; confirm before --classify-existing |
| FMB-001 | Family Bank | NULL | NULL | balance_sheet | debit | Follows from the stored category `asset` | DETERMINISTIC | Candidate for automated classification |
| KCB-001 | KCB Bank | NULL | NULL | balance_sheet | debit | Follows from the stored category `asset` | DETERMINISTIC | Candidate for automated classification |
| NCBA-001 | NCBA | NULL | NULL | balance_sheet | debit | Follows from the stored category `asset` | DETERMINISTIC | Candidate for automated classification |
| NIC-001 | NIC Dollar Account | NULL | NULL | balance_sheet | debit | Follows from the stored category `asset` | DETERMINISTIC | Candidate for automated classification |
| PETTY-001 | Petty cash | NULL | NULL | balance_sheet | debit | Follows from the stored category `asset` | DETERMINISTIC | Candidate for automated classification |
| SBM-001 | SBM | NULL | NULL | balance_sheet | debit | Follows from the stored category `asset` | DETERMINISTIC | Candidate for automated classification |
| STB-001 | Stanbic Bank | NULL | NULL | balance_sheet | debit | Follows from the stored category `asset` | DETERMINISTIC | Candidate for automated classification |
| STD-001 | Short Term Debtors | NULL | NULL | balance_sheet | debit | Follows from the stored category `asset` | DETERMINISTIC | Candidate for automated classification |

**Liabilities** (1)

| Code | Name | Current type | Current balance | Proposed type | Proposed balance | Basis | Confidence | Review status |
|---|---|---|---|---|---|---|---|---|
| AP-001 | Accounts Payable (A/P) | NULL | NULL | balance_sheet | credit | Follows from the stored category `liability` | DETERMINISTIC | Candidate for automated classification |

**Equity** (5)

| Code | Name | Current type | Current balance | Proposed type | Proposed balance | Basis | Confidence | Review status |
|---|---|---|---|---|---|---|---|---|
| DIV-001 | Dividend disbursed | NULL | NULL | balance_sheet | debit | Contra-equity: debit normal balance departs from the category default. | ACCOUNTANT_REVIEW_REQUIRED | In profile; confirm before --classify-existing |
| OBE-001 | Opening Balance Equity | NULL | NULL | balance_sheet | credit | Follows from the stored category `equity` | DETERMINISTIC | Candidate for automated classification |
| OCI-001 | Other comprehensive income | NULL | NULL | balance_sheet | credit | Follows from the stored category `equity` | DETERMINISTIC | Candidate for automated classification |
| RE-001 | Retained Earnings | NULL | NULL | balance_sheet | credit | Follows from the stored category `equity` | DETERMINISTIC | Candidate for automated classification |
| SC-001 | Share capital | NULL | NULL | balance_sheet | credit | Follows from the stored category `equity` | DETERMINISTIC | Candidate for automated classification |

**Revenue** (1)

| Code | Name | Current type | Current balance | Proposed type | Proposed balance | Basis | Confidence | Review status |
|---|---|---|---|---|---|---|---|---|
| RI-001 | Return Inwards | NULL | NULL | revenue | credit | Contra-revenue kept at CREDIT so the P&L deducts it; a deliberate sign convention. | ACCOUNTANT_REVIEW_REQUIRED | In profile; confirm before --classify-existing |

**Cost of Sales** (21)

| Code | Name | Current type | Current balance | Proposed type | Proposed balance | Basis | Confidence | Review status |
|---|---|---|---|---|---|---|---|---|
| COS-001 | Change in inventory - COS | NULL | NULL | direct_cost | debit | "Change in inventory" is an adjustment, not a purchase cost. | ACCOUNTANT_REVIEW_REQUIRED | In profile; confirm before --classify-existing |
| COS-002 | Cost of Sales | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| COS-003 | Cost of Sales:Branding | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| COS-004 | Cost of Sales:Courier - Projects | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| COS-005 | Cost of Sales:Fabrication | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| COS-006 | Cost of Sales:Field Facilitation | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| COS-007 | Cost of Sales:Jomat hardware | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| COS-008 | Cost of Sales:Materials | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| COS-009 | Cost of Sales:Motor bike and motor vehicle fuel | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| COS-010 | Cost of Sales:Paints & Hardware | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| COS-011 | Cost of Sales:Parking Fees | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| COS-012 | Cost of Sales:Plotting & Cutting | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| COS-013 | Cost of Sales:Printing | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| COS-014 | Cost of Sales:Team Meals | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| COS-015 | Cost of Sales:Teams accomodation | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| COS-016 | Cost of Sales:Transport & Delivery | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| COS-017 | Discounts given - COS | NULL | NULL | direct_cost | debit | "Discounts given" booked inside Cost of Sales; kept as WNG presents it. | ACCOUNTANT_REVIEW_REQUIRED | In profile; confirm before --classify-existing |
| COS-018 | Other - COS | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| COS-019 | Overhead - COS | NULL | NULL | overhead | debit | "Overhead - COS" typed overhead, not direct_cost: moves it out of gross margin. | ACCOUNTANT_REVIEW_REQUIRED | In profile; confirm before --classify-existing |
| COS-020 | Subcontractors - COS | NULL | NULL | direct_cost | debit | Normal balance follows from category `expense`; type from WNG's own "Cost of Sales" family | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| PE-007 | Personnel Expenses:Wages-Direct Labour | NULL | NULL | direct_cost | debit | Personnel-family account typed direct_cost so direct labour reaches gross margin. | ACCOUNTANT_REVIEW_REQUIRED | In profile; confirm before --classify-existing |

**Operating Expenses** (64)

| Code | Name | Current type | Current balance | Proposed type | Proposed balance | Basis | Confidence | Review status |
|---|---|---|---|---|---|---|---|---|
| ADM-001 | Administration expenses | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| ADM-002 | Administration expenses:Courier & Postage | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| DEL-001 | Delivery/Trolley expenses | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| EXP-001 | Pasting Expenses | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| INS-001 | Insurance - Disability | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| INS-002 | Insurance - General | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| INS-003 | Insurance - Liability | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| INV-001 | Inventory Shrinkage | NULL | NULL | opex | debit | Shrinkage typed opex; some presentations place it in cost of sales. | ACCOUNTANT_REVIEW_REQUIRED | In profile; confirm before --classify-existing |
| LPF-001 | Legal and professional fees | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| MAE-001 | Meals and entertainment | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| MBMV-001 | Motor bike and motor vehicle car wash | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| MBMV-002 | Motor bike and motor vehicles Repair | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| MC-001 | Management compensation | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| MED-001 | Medical expenses | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OGAE-001 | Other general and administrative expenses | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-001 | Operating Expenses | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-002 | Operating Expenses:Advertising/Promotional | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-003 | Operating Expenses:Audit & Accountancy Fees | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-004 | Operating Expenses:Commissions and fees | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-005 | Operating Expenses:Consultancy Fees | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-006 | Operating Expenses:Consumables | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-007 | Operating Expenses:Corporate social Responsibility | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-008 | Operating Expenses:Director Expenses | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-009 | Operating Expenses:Director Expenses:Courier - Director's Errands | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-010 | Operating Expenses:Dues and subacriptions | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-011 | Operating Expenses:Dues and subscriptions | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-012 | Operating Expenses:Electricity & Water | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-013 | Operating Expenses:Equipment Rental | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-014 | Operating Expenses:Garbage Collections | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-015 | Operating Expenses:Generator fuel, Repair and Maintenance | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-016 | Operating Expenses:Insurance premium | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-017 | Operating Expenses:Licenses and permits | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-018 | Operating Expenses:Office Casual/Stipend | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-019 | Operating Expenses:Office expenses | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-020 | Operating Expenses:Packaging and Delivery | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-021 | Operating Expenses:Professional Fees | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-022 | Operating Expenses:Rent & lease Payments | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-023 | Operating Expenses:Repairs and Maintenance | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-024 | Operating Expenses:Samples | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-025 | Operating Expenses:Security And Fees | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-027 | Operating Expenses:Shipping & Delivery Expense | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-028 | Operating Expenses:Site Visist | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-029 | Operating Expenses:Staff Welfare | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-030 | Operating Expenses:Stationery & Printing | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-031 | Operating Expenses:Telephone & Internet | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OPE-032 | Operating Expenses:Transport & Delivery | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OSE-001 | Other selling expenses | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| OTE-001 | Overtime expense | NULL | NULL | opex | debit | No history; could be direct project labour. | ACCOUNTANT_REVIEW_REQUIRED | In profile; confirm before --classify-existing |
| OTEA-001 | Other Types of Expenses-Advertising Expenses | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| PE-001 | Payroll Expenses | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| PE-002 | Personnel Expenses | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| PE-003 | Personnel Expenses:Housing levy | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| PE-004 | Personnel Expenses:Nita Levy | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| PE-005 | Personnel Expenses:NSSF Expense | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| PE-006 | Personnel Expenses:Salaries | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| PS-001 | Printing Supplies | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| RE-002 | Rider's Expense | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| SUP-001 | Supplies | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| TSE-001 | Travel expenses - selling expenses | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| UCBPE-001 | Unapplied Cash Bill Payment Expense | NULL | NULL | opex | debit | QuickBooks system account; nature unknown. | ACCOUNTANT_REVIEW_REQUIRED | In profile; confirm before --classify-existing |
| UE-001 | Uncategorised Expense | NULL | NULL | opex | debit | QuickBooks catch-all; nature unknown. | ACCOUNTANT_REVIEW_REQUIRED | In profile; confirm before --classify-existing |
| UTIL-001 | Utilities | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| WE-001 | Wage expenses | NULL | NULL | opex | debit | No history; could be direct project labour. | ACCOUNTANT_REVIEW_REQUIRED | In profile; confirm before --classify-existing |
| WNGA-001 | WNG Give aways | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |

**Other Expense (finance, tax penalties, non-cash)** (11)

| Code | Name | Current type | Current balance | Proposed type | Proposed balance | Basis | Confidence | Review status |
|---|---|---|---|---|---|---|---|---|
| ADM-003 | Administration expenses:Depreciation Expense | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| AMO-001 | Amortisation expense | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| ATM-001 | Atm charges | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| BD-001 | Bad Debts | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| EXD-001 | EXCISE DUTY | NULL | NULL | opex | debit | Excise duty treated as an operating expense as WNG records it. | ACCOUNTANT_REVIEW_REQUIRED | In profile; confirm before --classify-existing |
| FIN-001 | Finance cost | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| FIN-002 | Finance cost:Bad debts | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| FIN-003 | Finance cost:Bank charges | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| FIN-004 | Finance cost:Interest expense | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| FIN-005 | Finance cost:Mpesa charges | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |
| VP-001 | Vat Penalty | NULL | NULL | opex | debit | Normal balance follows from category `expense`; type from WNG's own account family/name (operating) | STRONG_EVIDENCE | In profile; covered by accountant sign-off of the profile |

**Unclear / Accountant Review** (4)

| Code | Name | Current type | Current balance | Proposed type | Proposed balance | Basis | Confidence | Review status |
|---|---|---|---|---|---|---|---|---|
| EQE-001 | Equity in earnings of subsidiaries | NULL | NULL | — (left NULL) | — (left NULL) | "Equity in earnings of subsidiaries" is recorded as EQUITY, but in QuickBooks it is normally an income-statement item. The category itself is ambiguous; left unclassified. | ACCOUNTANT_REVIEW_REQUIRED | NOT classified by the tool; accountant decides |
| ITX-001 | Income tax expense | NULL | NULL | — (left NULL) | — (left NULL) | Income tax expense: a tax charge below operating profit. None of direct_cost / overhead / opex is correct, and the ERP has no tax section. Left unclassified. | ACCOUNTANT_REVIEW_REQUIRED | NOT classified by the tool; accountant decides |
| LDO-001 | Loss on discontinued operations, net of tax | NULL | NULL | — (left NULL) | — (left NULL) | Loss on discontinued operations, net of tax: below-the-line. Left unclassified for the same reason. | ACCOUNTANT_REVIEW_REQUIRED | NOT classified by the tool; accountant decides |
| OPE-026 | Operating Expenses:Set Up Casuals | NULL | NULL | — (left NULL) | — (left NULL) | Set Up Casuals: could be project setup labour (direct cost) or general casual labour (opex). No history line uses it, so there is no evidence either way. Left unclassified; the P&L shows it under `unclassified` until the accountant decides. | ACCOUNTANT_REVIEW_REQUIRED | NOT classified by the tool; accountant decides |

---

## 11. Payment Source Audit

`payment_sources` has one capability column, `can_make_payment`. There is no stored receipt flag: whether a source can **receive** is decided by the receipt endpoint, which accepts `bank` sources for bank transfer and cheque, `mobile_money` for M-Pesa and `petty_cash` for cash. `card` and `payable` sources answer to no receipt method.

### 11.1 Local `db` (reference chart active, no profile)

| Code | Name | Type | Active | Payment capable | Receipt capable | Linked account | Account category | Postable / active | Status | Evidence / review note |
|---|---|---|---|---|---|---|---|---|---|---|
| PC-MAIN | Main Petty Cash Float | petty_cash | yes | yes | yes (cash) | 1030 Petty Cash Float | asset | yes / yes | CONFIGURED_REVIEW_REQUIRED | Reference account, not WNG's `PETTY-001`. 1 journal line; 2 receipts recorded against it. |
| BANK-MAIN | Equity Bank – Operating Account | bank | yes | yes | yes | 1010 Bank – Main Account | asset | yes / yes | CONFIGURED_REVIEW_REQUIRED | Reference account, not `EQB-001`. |
| BANK-ALT | NCBA Bank – Operations Account | bank | yes | yes | yes | 1020 Bank – Secondary Account | asset | yes / yes | CONFIGURED_REVIEW_REQUIRED | Reference account, not `NCBA-001`. |
| MPESA | Company M-Pesa | mobile_money | yes | yes | yes (M-Pesa) | 1040 Mobile Money Float | asset | yes / yes | CONFIGURED_REVIEW_REQUIRED | §12 |
| CARD | Company Card | card | yes | yes | no | 1010 Bank – Main Account | asset | yes / yes | CONFIGURED_REVIEW_REQUIRED | §13 |
| AP | Supplier Credit (Payable) | payable | yes | **no** | no | 2100 Accounts Payable | liability | yes / yes | Not a money channel (§14) | Reference account, not `AP-001`. |
| BANK-STANBIC | Stanbic Bank | bank | **no** | yes (if activated) | yes (if activated) | 1010 Bank – Main Account | asset | yes / yes | CONFIGURED_REVIEW_REQUIRED | §11.3 |
| BANK-KCB | KCB Bank | bank | **no** | yes (if activated) | yes (if activated) | 1010 Bank – Main Account | asset | yes / yes | CONFIGURED_REVIEW_REQUIRED | §11.3 |
| BANK-FAMILY | Family Bank | bank | **no** | yes (if activated) | yes (if activated) | 1010 Bank – Main Account | asset | yes / yes | CONFIGURED_REVIEW_REQUIRED | §11.3 |

Every link on local `db` points at a reference-chart account created on 2026-10-02. None points at a WNG account. Five of the nine sources share account 1010. This is the reference seeder's default wiring and says nothing about WNG's banks.

### 11.2 `wng_target_rehearsal` (WNG profile active)

| Code | Type | Active | Payment capable | Linked account | Category | Status | Note |
|---|---|---|---|---|---|---|---|
| PC-MAIN | petty_cash | yes | yes | PETTY-001 Petty cash | asset | CONFIGURED_CONFIRMED (structurally) | As the profile declares. 14 journal lines (rehearsal smoke test). |
| BANK-MAIN | bank | yes | yes | EQB-001 Equity Bank | asset | CONFIGURED_CONFIRMED (structurally) | As declared. |
| BANK-ALT | bank | yes | yes | NCBA-001 NCBA | asset | CONFIGURED_CONFIRMED (structurally) | As declared. |
| BANK-STANBIC | bank | no | yes (if activated) | STB-001 Stanbic Bank | asset | CONFIGURED_CONFIRMED (structurally), inactive | §11.3 |
| BANK-KCB | bank | no | yes (if activated) | KCB-001 KCB Bank | asset | CONFIGURED_CONFIRMED (structurally), inactive | §11.3 |
| BANK-FAMILY | bank | no | yes (if activated) | FMB-001 Family Bank | asset | CONFIGURED_CONFIRMED (structurally), inactive | §11.3 |
| AP | payable | yes | **no** | AP-001 Accounts Payable (A/P) | liability | Not a money channel | Correctly on the liability. |
| MPESA | mobile_money | no | — | none | — | NOT_CONFIGURED | §12 |
| CARD | card | no | — | none | — | NOT_CONFIGURED | §13 |

"Structurally" means the link is a valid, active, postable asset account of the right name. It does not certify that the account's balance, or the bank behind it, is what WNG operates today.

WNG's chart also holds cash and bank accounts that **no paying account uses**: `CASH-001 Cash Account`, `SBM-001 SBM`, `NIC-001 NIC Dollar Account`, `FK-001 Faulu Kenya`. Whether any of them is live is for Finance to say. Category: ACCOUNTANT REVIEW.

The dry run now prints this comparison (linked now / profile declares / state) for every source.

### 11.3 Inactive bank sources (Stanbic, KCB, Family)

STANBIC, KCB and FAMILY are inactive in both databases. **None was activated.**

| Source | Local `db` link | Structurally valid? | Rehearsal link | Structurally valid? |
|---|---|---|---|---|
| BANK-STANBIC | 1010 Bank – Main Account | A valid asset account, but **the wrong one**: it is the generic reference bank, shared with four other sources | STB-001 Stanbic Bank | Yes |
| BANK-KCB | 1010 Bank – Main Account | Same | KCB-001 KCB Bank | Yes |
| BANK-FAMILY | 1010 Bank – Main Account | Same | FMB-001 Family Bank | Yes |

Under the WNG profile each is linked to its own active, postable WNG asset account. Activating any of them on local `db` as it stands would post that bank's movements into the account BANK-MAIN uses.

Activation is a business decision. One fact for whoever takes it: Report 55 found KCB in use in the source data (petty-cash top-ups came from NCBA, Equity and KCB), while BANK-KCB is seeded inactive. Category: CONFIGURATION (links) and POLICY (activation).

---

## 12. M-Pesa Review

| Question | Answer |
|---|---|
| Account id 128 in local `db` | `1040 — Mobile Money Float` |
| Type | asset, balance sheet, debit, postable, active, parent 1000 |
| Origin | Reference chart; created 2026-10-02 12:49:51 by the reference seeder, in the same run as the other 87 numeric accounts |
| Historical use | **None.** 0 journal lines. No document names the MPESA source (only PC-MAIN has receipts) |
| Is it a WNG M-Pesa balance account? | **No evidence that it is.** WNG's chart holds no M-Pesa, mobile-money, till, paybill or wallet account. The only match is `FIN-005 Finance cost:Mpesa charges`, an expense. `1040` is a generic placeholder the reference chart supplies to every installation. |
| Why "previous rehearsal evidence had MPESA unconfigured" | Both are true of different databases. The rehearsal runs the WNG profile, which declares MPESA unlinked and has it disabled. Local `db` runs the reference chart, whose seeder links every source. Neither is production truth. |

**Status: local `db` — CONFIGURED_REVIEW_REQUIRED. Rehearsal — NOT_CONFIGURED.**

The decision remains WNG's: is M-Pesa a balance WNG holds (a till or wallet, needing its own asset account), or a channel that settles into a bank (in which case it links to that bank's account)? The profile records the evidence: 63 of 160 client receipts in the source arrived by M-Pesa, and petty cash books M-Pesa fees. No account was invented. Category: POLICY (carried from Report 55 as MIG-P1).

**Consequence that makes this a cutover blocker, not a tidy-up:** while MPESA is disabled, the receipt screen refuses M-Pesa receipts (HTTP 422). Report 55 measured 39% of source receipts as M-Pesa.

---

## 13. Card Review

| Question | Answer |
|---|---|
| What CARD is linked to in local `db` | Account id 125, `1010 Bank – Main Account`, shared with BANK-MAIN, BANK-STANBIC, BANK-KCB and BANK-FAMILY |
| Why | `PaymentSourceSeeder` names every bank-type source by the one generic reference code `1010`. The sharing is a seeder default applied to five sources alike. |
| Is it a card settling into that bank? | **The data cannot say.** A deliberate "card settles into Equity" link and the seeder default are indistinguishable in the row. Nothing else supports the first reading: no document uses CARD, WNG's chart has no card account, and the profile found no company card in the source (its "Visa" hits are the client VISA CEMEA). |
| Receipt capable? | No. No receipt method maps to a `card` source. |

**Status: local `db` — CONFIGURED_REVIEW_REQUIRED (an unsupported default, not a confirmed settlement link). Rehearsal — NOT_CONFIGURED.**

Decision for WNG: does a company card exist, and if so which bank account does it settle to? Until then the profile keeps it unlinked and disabled. Category: POLICY.

---

## 14. AP Source Guard

AP (`Supplier Credit`, type `payable`) is linked to Accounts Payable. It records that a bill is owed; it is never where money leaves from or arrives.

**Paying side — already guarded at every layer (verified, unchanged):**

| Layer | Guard |
|---|---|
| Model | `PaymentSource::saving` forces `can_make_payment = false` for any `payable`, whatever wrote the row |
| Scopes | `usableForPayment` and `paymentCapable` exclude it |
| Settlement | `PaymentSettlementService::settle` refuses it with a plain message |
| Posting | `JournalPostingService` refuses a `payable` or non-payment-capable source for supplier payments and payments |
| Controllers | Voucher, bill-payment, payroll and salary-advance pickers and validators require `can_make_payment` |
| Readiness | `finance:readiness` counts only asset-linked, non-payable sources as paying accounts |

**Receiving side — one gap closed.** The receipt endpoint already could not select AP (no receipt method maps to `payable`). But the posting service took whatever ledger account the receipt's source carried, with no check of its own. A receipt row naming AP by any other route would have posted *Dr Accounts Payable / Cr Client Deposits*: a client's money silently writing down what WNG owes suppliers. `ReceivablesPostingService::cashAccountFor` now refuses a `payable` source. Category of the gap: SOFTWARE DEFECT (latent, no instance found in any database; fixed and tested).

**Legitimate AP use preserved:** the AP source still exists, is still active, is still linked to the payable account, and is still how a credit purchase is recorded. `accounts_payable` still resolves to `AP-001`.

---

## 15. Expense Code Readiness

No expense-code seeder was run. Evidence: `06-expense-codes-settings.txt`.

| | Local `db` | `wng_target_rehearsal` |
|---|---|---|
| Codes | 109 | 109 |
| Active | 101 | 102 |
| Active and **resolved** (linked account exists, postable, active) | 101 | 102 |
| Active and unresolved | 0 | 0 |
| Active with a header or inactive account | 0 | 0 |
| Active without family / class / cost centre / activity / job rule | 0 | 0 |
| Duplicate codes | 0 | 0 |
| Inactive | 8 | 7 |

| Classification | Codes |
|---|---|
| Resolved | All active codes in both databases |
| Unresolved | None |
| Ambiguous by design (inactive) | NE-002, NE-004, NE-015, NE-017, NE-020, NE-021 — their catalogue text names a class of account ("Relevant 1400 PPE account", "Receiving cash/bank account") for a person to choose within |
| **Ambiguous, but wrongly resolved under the WNG profile** | **NE-018, NE-023** — see below |
| Off by design | NE-016 `2300 Loans Payable` under the WNG profile: no evidence WNG carries a loan. It is **active** on local `db`, where reference account 2300 exists |
| Deprecated / duplicate mapping | Reference `7150` is answered by WNG's `OPE-030`, not the ERP-added `7150`; `6400` and `6600` both land on `OPE-006` (WNG has no separate safety account). Both are recorded in the profile. |

**The two databases resolve the same codes to different accounts.** On local `db` 43 job-material codes post to `1211`; under the WNG profile they post to `WIP-002`. Both are internally correct. It means that on a two-chart database, expense codes, tax links and paying accounts are all currently wired to the **reference** side, while the WNG profile would point posting functions at the **WNG** side. Activating the profile there without re-linking would split postings across both charts. This is the same finding as §5.4 seen from the catalogue.

**A defect found in passing (not fixed; category SOFTWARE DEFECT).** NE-018 ("money received from a client before revenue is earned"; debit text `Bank / Cash (credit is 2200 Client Deposits)`) and NE-023 ("project WIP transfer to cost of sales"; debit text `Relevant 5100–5800 Cost of Sales account`) are meant to stay unresolved, with the account chosen per transaction; Report 53A lists both that way. On the reference chart they are inactive, as designed. Under the WNG profile they are **active**, linked to `CD-001 Client Deposits` and `COS-008 Cost of Sales:Materials`. Cause: `ChartAccountMap::localFromGl` resolves an indirectly named account when its four-digit code is "explicitly mapped", and a full company profile maps every function code, so a code merely mentioned in the prose (2200, 5100) qualifies. For NE-018 the result is a *debit* default on the Client Deposits liability, which is the credit side of that transaction. Neither code is procurable and neither has a cost line in the rehearsal, so nothing has posted through them. It should be corrected before the expense-code seed at cutover; it was left alone here because the fix changes seeder semantics and takes effect only through a re-seed. Evidence: `09-expense-code-indirect-and-settings.txt`.

Whether a re-seed is needed at cutover is determined by the readiness check after completion (§29 step 11), not assumed.

---

## 16. Accounting Period

| | Local `db` | `wng_target_rehearsal` |
|---|---|---|
| Periods configured | 48, January 2024 – December 2027 | 48, same range |
| Status | all `open`; none closed or locked | all `open` |
| Period covering 2026-10-06 | id 34, October 2026, `open` | id 34, October 2026, `open` |
| Readiness check | PASS — "open period covers 2026-10-06" | PASS |

No period was created, closed or reopened. (The earlier draft reported six periods in 2027 and none in 2026; that is not what either database holds.)

**`assertOpenPeriod` remains authoritative.** `JournalPostingService::assertOpenPeriod` refuses any posting whose period is missing or not open, and is called on every posting path in that service (cost lines, supplier invoices, supplier payments, balanced entries, payments). The receivables and payroll posting services and the voucher controller make the same `isOpen()` check before posting. Nothing in this stream touches them.

One observation, category POLICY: with all 48 periods open, including 2024 and 2025, the period control currently restrains nothing. Which historical periods should be closed before go-live is a Finance decision tied to opening balances, and is out of scope here.

---

## 17. WIP Configuration

| Item | Value |
|---|---|
| `FINANCE_ACCOUNT_PROFILE` in the main checkout | not set (`null`) |
| `FINANCE_WIP_POLICY` in the main checkout | not set (`null`) |
| Rehearsal checkout | `wng` / `capitalise` — a working assumption for the rehearsal, recorded as such in Report 55 |
| Profile default | `wip_policies.default = "capitalise"`, annotated "Still OPEN for Finance confirmation" |

**Default behaviour if unset — the software does not behave uniformly, and this is worth knowing before sign-off:**

| Situation | What happens |
|---|---|
| No profile (local today) | The reference map applies. Costs debit the reference WIP accounts `1211–1219` and release to cost of sales on invoicing. In effect this is capitalisation, by construction rather than by decision. |
| Profile `wng` active, policy unset | The account map **silently adopts the profile default, `capitalise`**: WIP functions resolve to `WIP-002…010`. The chart-completion command did the same and printed it as if chosen. |
| Same situation, reconciliation report | `FinancialReconciliationService` reads the raw setting, finds none, and reports WIP as **policy-blocked**: "no capitalisation assumption is made". |
| Unknown policy name | No WIP function is mapped; posting refuses; readiness says why. |

So with the profile active and the policy unset, the ledger would capitalise while the reconciliation says no policy exists. Nothing is posted wrongly, but the two disagree about whether a decision was made.

**Software consequence:** none today (no profile is active). The dry run now states where its policy came from — for example `WIP policy: capitalise, profile default — FINANCE_WIP_POLICY is not set` — so a default can no longer pass for a choice.

**Production consequence:** if the profile is activated without `FINANCE_WIP_POLICY` being set deliberately, project cost is carried on the balance sheet until invoiced, on a default nobody approved. `expense_on_capture` would instead send the same cost straight to cost of sales. The choice changes reported profit timing and the balance sheet.

**Status: POLICY_REQUIRED.** No authoritative approval exists in the repository, the databases or the reports. None was given or assumed here.

---

## 18. Finance Settings

15 rows, identical in both databases. `approved_by` and `approved_at` are NULL on **every** row. Eight values are the literal string `null`; these are unset, not configured. (Report 73 counted nine; the rows are listed below.)

| Key | Stored value | Approved | Status |
|---|---|---|---|
| `tax_return_due_day` | 20 | no | POLICY_REQUIRED — value present, unapproved; description asks for the tax advisor's confirmation |
| `reconciliation_date_tolerance_days` | 3 | no | POLICY_REQUIRED — present, unapproved |
| `petty_cash_max_per_transaction` | 20000 | no | POLICY_REQUIRED — §19 |
| `input_vat_claim_window_months` | 6 | no | POLICY_REQUIRED — present, unapproved |
| `margin_warning_percent` | 40 | no | POLICY_REQUIRED — present, unapproved |
| `margin_escalation_percent` | 35 | no | POLICY_REQUIRED — present, unapproved |
| `cost_overrun_alert_percent` | 10 | no | POLICY_REQUIRED — present, unapproved |
| `capitalisation_threshold` | `null` | no | NOT_CONFIGURED → POLICY_REQUIRED (accountant must set before capex flagging) |
| `purchase_order_auto_approval_limit` | `null` | no | NOT_CONFIGURED → POLICY_REQUIRED |
| `purchase_order_senior_approval_threshold` | `null` | no | NOT_CONFIGURED → POLICY_REQUIRED |
| `spend_voucher_senior_approval_threshold` | `null` | no | NOT_CONFIGURED → POLICY_REQUIRED |
| `petty_cash_low_balance_threshold` | `null` | no | NOT_CONFIGURED → POLICY_REQUIRED |
| `petty_cash_critical_balance_threshold` | `null` | no | NOT_CONFIGURED → POLICY_REQUIRED |
| `petty_cash_surrender_due_days` | `null` | no | NOT_CONFIGURED → POLICY_REQUIRED |
| `petty_cash_surrender_due_soon_days` | `null` | no | NOT_CONFIGURED → POLICY_REQUIRED |

**CONFIGURED (value present and approved): 0 of 15.** Present but unapproved: 7. Unset: 8. None is DATA_REQUIRED, because each is a number a person decides rather than one derived from records.

The seven present values are seeded recommendations from the design brief, not WNG decisions. Where a setting gates behaviour the code reads only an **approved** value (for example the petty cash cap, §19), so an unapproved number does not enforce anything.

---

## 19. Petty Cash Settings

| Control the brief names | Setting | Stored | Behaviour today | Status |
|---|---|---|---|---|
| Per-transaction cap | `petty_cash_max_per_transaction` | 20000, unapproved | **Not enforced.** `PettyCashCap::limit()` reads only an approved value; unapproved, every payment passes | POLICY_REQUIRED |
| Float ceiling | `payment_sources.float_limit` on PC-MAIN (no finance setting exists) | NULL in both databases; custodian also NULL | No ceiling | POLICY_REQUIRED |
| Approval threshold | `spend_voucher_senior_approval_threshold` | `null` | Gate inactive; ordinary approval only | POLICY_REQUIRED |
| Surrender deadline | `petty_cash_surrender_due_days` | `null` | No default deadline; an explicit due date is required per advance | POLICY_REQUIRED |
| "Due soon" window | `petty_cash_surrender_due_soon_days` | `null` | No due-soon state shown | POLICY_REQUIRED |
| Low / critical balance alerts | `petty_cash_low_balance_threshold`, `…critical…` | `null` | Legacy safe default | POLICY_REQUIRED |
| Advance ceiling | — no setting exists | — | No ceiling | POLICY_REQUIRED |

No value was invented, set or approved. The KES 20,000 figure in the table is the brief's recommendation sitting unapproved in the database; it is not a WNG decision and is not being enforced.

---

## 20. Tax Reference Configuration

### Software / reference — READY

| Table | Rows | Linked ledger account (local `db`) | Linked ledger account (rehearsal) |
|---|---|---|---|
| `vat_treatments` | 5: STD16-REC 16% recoverable, STD16-NONREC 16% not recoverable, ZERO, EXEMPT, OOS | Recoverable treatments → `1330` | → `VAT-002` |
| `wht_categories` | 3: PROF-RES 5%, CONTRACT-RES 3%, NONE | Withholding categories → `2120` | → `WHT-001` |

All rows are active, effective from 2020-01-01 with no end date. Non-recoverable, exempt and out-of-scope treatments correctly carry no ledger account. `input_vat`, `output_vat` and `wht_payable` resolve in both databases. After a chart completion the tax links follow the active map (existing test `after_completion_tax_links_and_expense_codes_resolve_to_wng_accounts`).

### Finance / statutory — VALIDATION REQUIRED

Reference rows resolving is not filing readiness. Not established by anything in this stream:

- that 16%, 5% and 3% are the rates applicable to WNG's actual supplies and payees, or that three WHT categories cover them (there is no non-resident category in use, and no rent, dividend or interest category);
- that the WHT thresholds are right (`threshold_amount` is NULL on every category);
- that the filing day (20th) and the six-month input-VAT claim window are current — both are unapproved settings whose own descriptions ask for the tax advisor's confirmation;
- that historical VAT and WHT balances agree with KRA records (no history exists in the ERP to test);
- eTIMS behaviour against a live KRA connection.

Category: ACCOUNTANT REVIEW.

---

## 21. Document Sequences

`document_sequences` holds controlled, gap-free series issued by `DocumentNumber::next()` under a row lock. A missing row is created automatically on first use, starting at 1.

| Database | Rows |
|---|---|
| Local `db` | `PAY / 2025` next 44; `PAY / 2026` next 1512 |
| `wng_target_rehearsal` | `PAY / 2026` next 15 |

| Finance workflow | Numbering today | Sequence | Status |
|---|---|---|---|
| Payments (petty cash, vouchers, supplier, payroll, salary advance) | `PAY-<year>-<n>` via `DocumentNumber` | **exists** | Controlled. **Historical authority unknown**: the local counters (44, 1512) were set on 2026-10-02 and nothing here shows they continue WNG's real payment numbering. |
| Requisitions (`REQ`), receipts (`RCT`), journals (`JV`), advances (`PCA`), retirements (`PCR`) | Constants are defined in `DocumentNumber`; **no code calls them** | **missing**, and unused | These documents are numbered by other means (below) |
| Journal entries | Derived from the source document, e.g. `JE-CL-0000002`, `JE-RCPT-000085` | none | Unique by construction; not an independent series |
| Payment vouchers | `SV-<yyyymmdd>-<id>` from the row id | none | Unique; not gap-free |
| Supplier bills, purchase orders, GRNs, procurement requisitions, petty-cash requisitions | Each model reads its own last number and adds one | none | No lock: two concurrent creations can compute the same number |
| Client invoices | `INV-<yyyymm>-<id>` from the row id, assigned after the row is created | none | Unique; **not gap-free** (a voided or abandoned draft leaves a hole) and not continuous with any numbering WNG used before. Invoice numbering is the series a tax authority cares most about. |

No sequence was created. Whether statutory documents need gap-free controlled series, and from what number each continues, is a POLICY decision with a DATA dependency (WNG's last-used numbers).

---

## 22. Configuration / Policy / Data / Accountant Review Matrix

Each open item has one primary category.

| # | Item | Category | State | Blocks the chart cutover? |
|---|---|---|---|---|
| 1 | Which chart the cutover target holds (WNG's 123, or WNG plus the reference chart) | DATA | Unknown from here; last recorded as 208 accounts on 2026-09-29 | **Yes — first gate** |
| 2 | If the target holds both charts: keep WNG's chart only (D3 Option A) or adopt reference accounts by declared reuse | ACCOUNTANT REVIEW | Not decided; reuse mechanism available | Yes, if item 1 finds two charts |
| 3 | Sign-off of the WNG profile's 37 function mappings | ACCOUNTANT REVIEW | Outstanding (required by the profile itself) | Yes |
| 4 | Six mappings with a recorded judgement: `staff_advances`→STD-001, `cos_direct_labour`→PE-007, `cos_project_facilitation`→COS-006, `cos_rework_warranty`→COS-018, `inventory_adjustments`→INV-001, `bank_charges`→FIN-003 | ACCOUNTANT REVIEW | ACCOUNTANT_CONFIRMATION_REQUIRED | Yes (part of item 3) |
| 5 | WIP policy: `capitalise` or `expense_on_capture` | POLICY | POLICY_REQUIRED; no approval on record | Yes — it decides whether WIP-001…010 should be created at all |
| 6 | Classification of 116 WNG accounts, including 13 with a recorded judgement | ACCOUNTANT REVIEW | Proposed in the profile; previewable; not applied | Yes, for `--classify-existing` |
| 7 | OPE-026, ITX-001, LDO-001, EQE-001 | ACCOUNTANT REVIEW | Deliberately unclassified | No — they stay under "unclassified" on the P&L until decided |
| 8 | M-Pesa: held balance or settlement channel; which account | POLICY | NOT_CONFIGURED under the profile | Not the chart step; **blocks go-live** (M-Pesa receipts refused) |
| 9 | Card: does a company card exist; where it settles | POLICY | NOT_CONFIGURED under the profile | No |
| 10 | Activation of Stanbic, KCB, Family; status of CASH-001, SBM-001, NIC-001, FK-001 | POLICY | Inactive / unused | No |
| 11 | `FINANCE_ACCOUNT_PROFILE=wng` and `FINANCE_WIP_POLICY` set in the target environment | CONFIGURATION | Not set in the main checkout; production unknown | Yes — set as part of the cutover, after items 3 and 5 |
| 12 | Paying-account links on a two-chart database point at reference accounts | CONFIGURATION | Follows from item 1 | Yes, if item 1 finds two charts |
| 13 | NE-018 / NE-023 resolve to incidental accounts under a full profile | SOFTWARE DEFECT | Found, not fixed | Before the expense-code seed |
| 14 | Dry run aborted on the first conflict and reported nothing | SOFTWARE DEFECT | **Fixed** (§23) | — |
| 15 | Name-only duplicate check missed 20 of 25 duplicates | SOFTWARE DEFECT | **Fixed** (§23) | — |
| 16 | Receipt posting had no ledger-level refusal of a payable source | SOFTWARE DEFECT | **Fixed** (§14) | — |
| 17 | WIP default adopted silently and inconsistently between the map and reconciliation | SOFTWARE DEFECT | Dry run now states the source; the map/reconciliation difference is reported, not changed | No |
| 18 | 15 finance settings: 0 approved, 8 unset | POLICY | POLICY_REQUIRED | No |
| 19 | Petty cash cap, float ceiling, approval threshold, surrender deadline, advance ceiling | POLICY | POLICY_REQUIRED; nothing enforced | No |
| 20 | Tax rates, categories, thresholds, filing day, claim window | ACCOUNTANT REVIEW | Reference ready; statutory validation outstanding | No |
| 21 | Document numbering: controlled series only for payments; continuation numbers | POLICY | Open; needs WNG's last-used numbers (DATA) | No |
| 22 | All 48 periods open, including 2024–2025 | POLICY | Open | No |
| 23 | Opening balances, retained earnings, historical data | DATA | Out of scope by instruction | Not the chart step |

---

## 23. Safe Code/Profile Changes

Backend only. Nothing here changes an account, a balance or any stored configuration.

### 23.1 `app/Modules/Finance/Support/FinanceChartProfile.php`

- `reuse()` — proposed code → existing code, from `new_accounts[].reuse_existing.code`.
- `map()` and `paymentSources()` translate through it, so every consumer of the map (posting services, expense-code seeder, tax seeder, paying-account seeder, readiness) follows a declared reuse without knowing about it.
- `fake()` — test seam so tests can vary a profile without writing files.

With no `reuse_existing` in a profile, all three behave exactly as before; WNG's profile has none.

### 23.2 `app/Modules/Finance/Console/CompleteChartCommand.php`

| Change | Detail |
|---|---|
| Explicit reuse | Validates a `reuse_existing` declaration by the rules in §6.2; plans `reuse` instead of `create`. |
| Second-account refusal | Refuses to create an account for a function or catalogue reference whose reference-chart account is already in the chart. Checked by code identity under the WIP policy in force. |
| Dry run completes | With problems, a dry run now prints the full plan, marks the affected accounts `CONFLICT`, lists every blocking reason and exits 1. `--execute` with problems still refuses before any write, with the same `REFUSED — nothing was written` message. |
| Function status | Each function reports `RESOLVED_EXISTING`, `RESOLVED_PROPOSED_NEW`, `CONFLICT` or `UNRESOLVED`, plus source, type, balance, postable, active. |
| Classification preview | Always computed; before → proposed for every account, the accountant's pending list, and any unclassified account the profile does not mention. Applied only with `--classify-existing --execute`, as before. |
| Paying accounts | Read-only table: linked now, profile declares, state; flags a source on the wrong category of account. |
| Reference twins | Warns when the chart holds a reference account beside the account the profile posts to; accounts the profile already acknowledges (`erp_added_accounts`) are not warned about. |
| Conflict evidence | Refusals state how many journal lines the existing account carries. |
| WIP policy source | States whether the policy was configured or is the profile default. |
| JSON report | Adds `blocking`, `warnings`, `classification_preview`, `reference_twins`, per-account `reuse`/`problems`, per-function `status`. Existing keys are kept. |

Unchanged: the live-source refusal, `--confirm=<database>`, `--cutover` for the live target, idempotency, never modifying an existing account, the transaction and the in-transaction re-check before insert.

One output line was reworded: "payment sources linked: 7 / 9" became "paying-account ledger accounts resolved: 7 / 9". It never measured links, only whether the declared accounts exist; on a two-chart database it read as healthy while every link pointed elsewhere.

### 23.3 `app/Modules/Finance/Services/ReceivablesPostingService.php`

`cashAccountFor` refuses a receipt whose source is of type `payable` (§14). No other posting behaviour changed.

### 23.4 Profile: the earlier draft's edit reverted

The 2026-10-03 draft changed `parent` of COS-021/022/023 from `COS-002` to `null`, on the reasoning that the command refuses a postable parent. It does not; and the draft's error message ("parent COS-002 is neither in the chart…") came from running against a database that had no COS-002. The rehearsal target was built with those accounts under COS-002, so the edited profile refused there with three "different definition" errors and would have broken idempotency at cutover. The profile and the one test assertion were returned to their committed content. **Net change to `wng-chart-profile.json`: none.**

### 23.5 Not changed

No migration, seeder, model, controller, route, config file or frontend file. `FinanceReadiness.php` and the Report 73/73A files are as that work left them.

---

## 24. Final Dry Run

`php artisan finance:complete-chart --profile=wng` (no `--execute`), after the changes. Evidence: `74-evidence/07-final-dry-run-*.txt` and `final-*/chart_completion.json`.

| | Local `db` | `wng_source_rehearsal` (WNG chart, pre-completion) | `wng_target_rehearsal` (completed) |
|---|---|---|---|
| Exit | 1 (blocked) | 0 | 0 |
| To create | 4 (headers only) | 29 | 0 |
| Already present | 0 | 0 | 29 |
| Declared reuse | 0 | 0 | 0 |
| In conflict | **25** | 0 | 0 |
| Functions resolved | 16 / 37 | 37 / 37 | 37 / 37 |
| Function status | 16 existing, 21 conflict | 16 existing, 21 proposed-new | 37 existing |
| Classification preview | 116 to fill, 4 pending, 0 outside profile | 116 / 4 / 0 | 0 / 4 / 0 |
| Catalogue references | 10 / 15 | 14 / 15 (2300 off by design) | 14 / 15 |
| Paying accounts | 9 flagged REVIEW (all on reference accounts) | table empty at this stage of the rehearsal | 7 as declared; MPESA, CARD unlinked and disabled |
| Reference twins not acknowledged | **48** | 0 | 0 |
| Blocking reasons | 25 | 0 | 0 |

The run on WNG's chart is the attended-cutover preview: it names the 29 accounts it would create, the 37 accounts each function would post to, the 232 classification values it would fill, and nothing it would refuse. The run on local `db` is an accurate refusal with its reasons.

`finance:readiness` (read-only) passes 6/6 on both local `db` and the rehearsal target (`08-readiness-recheck.txt`). It checks reference data only. It does not check chart identity, classification, policy or history, and its pass on local `db` is a pass for the *reference* chart with no profile active — it says nothing about readiness to run WNG's chart there.

---

## 25. Backend Tests

All against the disposable `db_test`; the harness refuses any database whose name does not end `_test`.

**Targeted run (after the last code change)** — `11-targeted-rerun.txt`: **110 passed, 0 failed** (594 assertions).

| Suite | Covers | Result |
|---|---|---|
| `Feature/Finance/WngChartCompletionTest` | complete-chart command, profile, reuse, conflict safety, classification, paying accounts, WIP switch, execution guards, readiness under the profile | 36 passed (19 existing + 17 new cases) |
| `Feature/Finance/WngChartMappingTest` | `FinanceAccountFunctions`, the 37-function registry and mapping | passed |
| `Unit/Finance/ChartAccountMapTest` | `ChartAccountMap` | passed |
| `Feature/Finance/ReceivablesPostingTest` | receipt posting, period refusal, new AP receipt guard | passed |
| `Feature/Finance/FinanceControlCentreTest` | Report 73 readiness projection, finance settings, WIP mode | passed |
| `Feature/Seeding/SeedingGuardTest`, `Unit/Finance/RequisitionSchemaServiceTest` | seeding guard; schema service | passed |

Finance readiness, expense-code, payment-source, accounting-period, period-close and inventory-finance tests live in `tests/Feature/Finance` and ran in the regression suite below.

Regression tests the brief asked for, all in `tests/Feature/Finance/WngChartCompletionTest.php`:

| Required | Test |
|---|---|
| Same-name account does not auto-reuse | `a_same_named_account_is_never_reused_unless_the_profile_declares_it` (dry run and execute; map unchanged) |
| Explicit profile reuse works | `an_explicitly_declared_existing_account_meets_the_requirement_and_nothing_is_created_for_it` (dry run, execute, 37/37, existing rows byte-identical, idempotent) |
| Wrong-code reuse fails safely | `a_reuse_declaration_that_is_not_exactly_the_declared_account_is_refused` — cases "a code that is not in the chart", "a code that is another account", "a declaration with no name" |
| Non-postable reuse fails | same test, case "a non-postable header" |
| Inactive reuse fails | same test, case "an inactive account" |
| (further unsafe forms) | same test: other category, opposite normal balance, account reused twice, account the profile itself proposes, requirement also in the chart — ten cases, each for dry run and execute, each asserting nothing was written |
| Classification preview does not mutate | `the_classification_preview_shows_before_and_proposed_and_changes_nothing` |
| Dry run does not mutate | `a_blocked_dry_run_still_reports_the_whole_plan_and_writes_nothing`, plus the existing `a_dry_run_writes_nothing…` |
| Execute requires exact DB confirmation | `a_declared_reuse_loosens_none_of_the_execution_guards`, plus the existing guard test |
| Live execution requires `--cutover` | same two tests; the new one also proves it runs only with both |
| (duplicate by function, not name) | `a_new_account_is_refused_beside_the_reference_account_for_the_same_function_whatever_its_name` |
| (paying-account report is truthful and read-only) | `the_dry_run_reports_where_each_paying_account_is_linked_against_the_profile` |
| (AP cannot receive) | `ReceivablesPostingTest::supplier_credit_can_never_be_the_account_a_receipt_arrives_in` |

---

## 26. Finance Regression

`tests/Feature/Finance`, `tests/Unit/Finance`, `tests/Feature/CostCollector`, `tests/Feature/PettyCash` — `10-finance-regression-suite.txt`:

**954 passed, 1 failed** (7,628 assertions, 865 s).

The one failure is `CostCollectorApiTest > the picker searches and filters by family`: a search for "truck" returned two expense codes where the test expects one. It is not caused by this stream:

- it exercises the expense-code picker endpoint, which none of the three changed source files touches;
- the test file and that endpoint are unmodified (last commit to the test: 2026-09-13);
- **run on its own it passes** (it is in the targeted run above). In full-suite order a catalogue code seeded by an earlier test class (`TL-HIR-001 Truck and vehicle hire`) is still present and also matches. It is an order-dependent fixture collision.

It is reported, not fixed: it belongs to the cost-collector tests, not to Finance configuration.

That full run preceded one final cosmetic change (the dry run naming where its WIP policy came from: one header string and one JSON key). The suites covering that file were re-run afterwards and pass (targeted run, §25). The remainder of the regression suite was not re-run after it.

Not run: Projects, Stores, Procurement, HR and the other non-Finance suites, and the real-data rehearsal smoke suite (it commits data and requires rebuilding the rehearsal databases).

**Frontend: no code changes.** `ERP-Frontend` is at the same HEAD with the same ten pre-existing modified/untracked paths as at the start. No frontend test or build was run because nothing there changed and no API response shape consumed by the frontend changed (the command's JSON report is a file, not an endpoint).

---

## 27. Files Changed

`git diff --check`: clean — no whitespace errors or conflict markers, across the whole worktree and across this stream's files

By this stream:

| File | Change |
|---|---|
| `app/Modules/Finance/Console/CompleteChartCommand.php` | §23.2 |
| `app/Modules/Finance/Support/FinanceChartProfile.php` | §23.1 |
| `app/Modules/Finance/Services/ReceivablesPostingService.php` | §23.3 |
| `tests/Feature/Finance/WngChartCompletionTest.php` | Earlier draft's assertion reverted; 8 tests added (17 cases) |
| `tests/Feature/Finance/ReceivablesPostingTest.php` | 1 test added |
| `database/finance/wng-chart-profile.json` | Earlier draft's edit reverted; **no net change from HEAD** |
| `docs/finance-redesign/phase-2/74_FINANCE_CONFIGURATION_CLOSURE.md` | This report (replaces the earlier draft) |
| `docs/finance-redesign/phase-2/74-evidence/` | Evidence `01`–`09`, three `final-*/chart_completion.json`; the earlier draft and its scripts moved to `superseded-2026-10-03/` |

Pre-existing uncommitted work from other streams is untouched. Nothing was committed or pushed.

---

## 28. Remaining Cutover Blockers

In the order they must be cleared.

1. **Target chart identity (DATA).** Establish what `chart_of_accounts` on the cutover target holds. If it is WNG's chart (123 accounts, no four-digit accounts other than 2160, 7150, 7550), proceed. If it holds the reference chart as well, stop: go to blocker 2.
2. **Two-chart decision (ACCOUNTANT REVIEW), only if blocker 1 finds two charts.** Either restore the target to WNG's chart by the planned source-to-target migration, or decide to adopt reference accounts and have each reuse declared in the profile. The second option reopens decision D3 and the 25 declarations need the accountant's confirmation individually; reference accounts that already carry postings (1030, 1200, 1211, 2150, 2200 locally) need a decision on those balances first.
3. **WIP policy (POLICY).** Signed choice of `capitalise` or `expense_on_capture`.
4. **Profile sign-off (ACCOUNTANT REVIEW).** The 37 mappings, with explicit confirmation of the six in §22 item 4, and of the 29 new accounts.
5. **Classification sign-off (ACCOUNTANT REVIEW).** The 116 proposed classifications, in particular the 13 with a recorded judgement (§9); any the accountant rejects must be removed from the profile before `--classify-existing` is used, because the option applies the whole list.
6. **Environment (CONFIGURATION).** `FINANCE_ACCOUNT_PROFILE=wng` and the signed `FINANCE_WIP_POLICY` set on the target, then the configuration cache rebuilt.
7. **NE-018 / NE-023 (SOFTWARE DEFECT).** Corrected, or the two codes explicitly deactivated, before the expense-code seed.

Not blockers for the chart step, but for go-live: M-Pesa (item 8 in §22), opening balances and history, statutory tax validation, document-number continuation, period policy, finance-setting approvals.

---

## 29. Attended Cutover Plan

**Not executed. Not to be executed without the approvals in §28.** The configuration evidence supports this sequence for a target that holds WNG's chart. It is the sequence the rehearsal pipeline already runs (`scripts/rehearsal/wng-rehearsal-pipeline.sh`), made explicit, with the checks this report adds. `<db>` is the target database name.

| # | Step | Command / action | Proceed only if |
|---|---|---|---|
| 1 | Change control | Announce a Finance write freeze. No postings, receipts, payments or period actions during the window. Named operator and named accountant present. Queue workers stopped for the window. | Freeze confirmed |
| 2 | Fresh backup | Full dump of `<db>`; record file, size, SHA-256; restore it into a scratch database and count `chart_of_accounts` there. | The restore succeeds and the count matches |
| 3 | Database identity | `php artisan config:show database` and `SELECT DATABASE()`; confirm it is `<db>` and not the live source. Then: `SELECT COUNT(*), SUM(code REGEXP '^[0-9]{4}$') FROM chart_of_accounts`. | The target is the intended one, and the four-digit count is 3 (2160, 7150, 7550). **Any other four-digit account: STOP — §28 blocker 2.** |
| 4 | Activate profile | Set `FINANCE_ACCOUNT_PROFILE=wng` and the signed `FINANCE_WIP_POLICY`; `php artisan config:clear && php artisan config:cache`; `php artisan config:show finance_accounts`. | Both values show as set |
| 5 | Default dry run | `php artisan finance:complete-chart --profile=wng --classify-existing -v --output=<evidence dir>` | Exit 0; "29 to create, … 0 in conflict"; "Functions resolved: 37 / 37"; no `BLOCKED`; no reference-twin warning; WIP policy shown as configured, not "profile default" |
| 6 | Review artifact | Operator and accountant read `chart_completion.json` together: the 29 accounts, the 37 function rows, the classification before → proposed table, the paying-account table. Compare with §7–§10 of this report. | Accountant signs the artifact |
| 7 | Execute | `php artisan finance:complete-chart --profile=wng --classify-existing --execute --confirm=<db> --cutover --output=<evidence dir>` (`--cutover` is required because `<db>` is the live target; omit `--classify-existing` if step 6 did not approve the classification) | Exit 0; 29 accounts reported created; 37 / 37 |
| 8 | Idempotency check | Re-run step 7's command | 0 created; output otherwise identical |
| 9 | Mapping verification | `php artisan finance:complete-chart --profile=wng` (dry run) | 0 to create, 29 already present, 37 / 37 `RESOLVED_EXISTING` |
| 10 | Account count reconciliation | `SELECT COUNT(*) FROM chart_of_accounts` | Count before + 29. Every pre-existing row unchanged in id, code, name, category, parent, postable and active (compare with the step 2 restore); `account_type`/`normal_balance` changed only from NULL, only on the 116 listed accounts |
| 11 | Reference data | `php artisan finance:readiness`. Run the paying-account, tax and expense-code reference step **only if** readiness reports unlinked codes or sources, and only after §28 blocker 7 is cleared. | Readiness names nothing missing, or the reference step then clears it |
| 12 | Paying accounts | Dry run paying-account table; then, if approved, `… --disable-unlinked-sources --execute --confirm=<db> --cutover` | Each bank "linked as the profile declares"; AP on AP-001; MPESA and CARD per the decisions in §12–§13 (linked by Finance, or disabled) |
| 13 | Readiness rerun | `php artisan finance:readiness` | 6 / 6 PASS |
| 14 | Smoke tests | In a rehearsal copy, not on live data: the Report 52 smoke suite. On live: open the Finance setup screen and confirm 37 functions resolved, profile `wng` active, and the WIP mode shown. Post nothing as a test. | No failure |
| 15 | Release | Lift the freeze; restart workers; file the evidence directory with this report. | — |

**Stop conditions (do not continue; do not improvise):** any `REFUSED` or `BLOCKED`; any conflict or reference-twin warning at step 5; functions below 37 / 37; step 3 finding unexpected four-digit accounts; a created-account count other than 29; any existing account differing at step 10; readiness failing after step 11.

**Rollback.** Before step 7 nothing has been written, so stopping is free. Step 7 runs in one transaction: a failure inside it writes nothing. After a successful step 7 the only changes are 29 inserted accounts and NULLs filled on 116; if they must be undone before any posting has used them, restore the step 2 backup. Once anything has posted to a new account, do not restore: stop, keep the freeze, and escalate. Never delete a chart account by hand.

---

## 30. Verdict

**FINANCE CONFIGURATION CLOSURE PARTIAL — SPECIFIC CONFIGURATION/ACCOUNTANT REVIEW BLOCKERS REMAIN**

Why not COMPLETE. The deterministic architecture is finished for WNG's chart: reuse is explicit, duplicates are refused by function as well as by name, the dry run reports everything, all 37 functions are accounted for, the classification is previewable, and the cutover plan is written and matches the rehearsal. But the one database Report 73 examined does not hold WNG's chart alone, production was last recorded in the same state, and whether the cutover target is the rehearsed one could not be established from here. That is a specific configuration blocker, and COMPLETE would claim readiness for a target nobody has identified.

Why not INCOMPLETE. Nothing in the configuration architecture is unresolved. Every remaining item is a named decision or a named fact to obtain (§28).

This verdict is not a statement that the system is production-ready, that historical balances are certified, that any accounting policy is approved, that statutory filing is ready, or that deployment is approved.

**Stopped here.** No `--execute`, no live classification, no source disabling, no chart mutation, no seeder, no opening balances, no retained earnings, no history repair, no policy approval, no deployment, no W8, no queue workers.
