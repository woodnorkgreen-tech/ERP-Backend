# Report 74A — Pre-Cutover Software Blocker Closure + Target Chart Identity

**WNG ERP — Finance.** Prepared 2026-10-06, following Report 74.
**Scope:** close the software defects Report 74 left open, and provide a read-only way to establish what chart a cutover database holds. No cutover was performed.

---

## 1. Executive summary

Three software items were open after Report 74. All three are closed, each with regression tests.

| Item | Finding | Outcome |
|---|---|---|
| **NE-018 / NE-023** | The resolver took the first four-digit number anywhere in a catalogue row's debit text and accepted it when that code was mapped. A company profile maps every posting function, so a code merely mentioned became a resolution. | **Fixed.** A row resolves only from the account it designates: a leading code, or a single class code Finance has mapped. Both codes now stay unresolved and inactive, as Report 53A specifies. All 100 legitimately designated codes still resolve under WNG's profile. |
| **WIP policy fallback** | **Yes, it could reach production postings.** With the WNG profile active and `FINANCE_WIP_POLICY` unset, the account map adopted the profile's default (`capitalise`) and every posting path resolves through that map. | **Fixed, fail-closed.** With no approved policy the nine WIP functions map to nothing, project-cost postings and the WIP release are refused with `POLICY REQUIRED`, and readiness reports `POLICY REQUIRED`. Neither policy is chosen. The planning tool still shows the suggestion, labelled `DEFAULT / NOT APPROVED FOR PRODUCTION`, and refuses a live cutover on it. |
| **Target chart identity** | Report 74 could not say what the cutover target holds, and the command could not tell a company chart from a two-chart database except by side effects. | **Built into the existing dry run.** Every run of `finance:complete-chart` now classifies the database as `WNG_CHART_ONLY`, `TWO_CHART_STATE` or `OTHER / MANUAL_REVIEW_REQUIRED` from that database alone, and stops on anything but the first. Read-only. |

**Production has not been inspected.** Nothing in this report says what the production chart is. The procedure in §9 is how to find out.

One previously observed test failure was investigated (§14): it is a fixture-isolation defect in a different test class, not a regression. It was not changed.

**Verdict: FINANCE PRE-CUTOVER SOFTWARE BLOCKERS CLOSED — TARGET IDENTITY AND HUMAN DECISIONS REMAIN** (§22).

---

## 2. Git baseline

| | Backend (`ERP-Backend`) | Frontend (`ERP-Frontend`) |
|---|---|---|
| Branch | `master` | `master` |
| HEAD | `5a5a3db738110d153610e3e5b6e5da4d9ab2d4db` | `b82f27895c3d05e67d7d6029224d016783c8f127` |
| Dirty paths at start | 47 | 10 |
| Dirty paths at end | 47 + the files in §15 not already dirty | 10 — **no frontend change** |

Work already present and preserved exactly: Report 73 / 73A (readiness controller and service, control-centre service and tests, report files and verification folders), Report 74 (`CompleteChartCommand`, `FinanceChartProfile`, `ReceivablesPostingService`, two test files, the report and `74-evidence/`), and unrelated streams (materials, projects, stores, payroll tests, a project-elements migration).

No reset, stash, discard, merge, commit, push or deploy. `database/finance/wng-chart-profile.json` remains identical to HEAD.

**Environment.** The DDEV web container, with its queue daemon, was already running when this work began (started outside this stream during Report 74 and disclosed there). It was neither started nor stopped here. All artisan and test commands ran in one-off containers that start no worker.

---

## 3. Corrected Report 74 assumptions

Carried forward as the working facts. Each was re-read from the databases in this stream, not taken from the earlier report.

| # | Fact | Re-verified here |
|---|---|---|
| 1 | Local `db` holds two complete charts: WNG's 120 accounts (2026-03-06) and the 88-account reference chart (2026-10-02) | Dry run on `db`: 208 accounts, 120 company-coded, 88 four-digit, all 88 on the reference seeder's own list → `TWO_CHART_STATE` |
| 2 | The five name conflicts are a subset; by function 25 of 29 proposed accounts duplicate a reference account | Same run: 25 in conflict, 4 creatable (the four headers) |
| 3 | Five reference accounts carry test postings | 1030 (1 line), 1200 (3), 1211 (1), 2150 (2), 2200 (1) |
| 4 | On the clean 123-account chart: 29 creations, 0 conflicts, 37/37 | §11 |
| 5 | Production chart identity is not verified | Still not verified. Not inferred from any local or rehearsal database |
| 6 | The `parent=null` edit to COS-021/022/023 was reverted | Still reverted. No current evidence requires it: the rehearsal target holds the three accounts under `COS-002`, and the profile matches it (29 "already present", 0 conflicts) |
| 7 | NE-018 / NE-023 are a software defect | Fixed, §4–§6 |
| 8 | WIP policy is a business decision | Unchanged; not decided here |
| 9 | An unset policy may silently fall back to `capitalise` | Confirmed and fixed, §7–§8 |

One refinement to fact 1: the earlier count of "48 references with two accounts" measured function twins. Counted simply as reference-chart accounts present that the profile does not name, the figure on local `db` is **85** (the 88 less 2160, 7150 and 7550, which the profile names).

---

## 4. NE-018 root cause

**How resolution works.** An expense code's posting account is `expense_codes.default_debit_account_id`. It is set in one place, `ExpenseCodeSeeder::resolveAccount`, which hands the catalogue row's `default_debit_gl` text to `ChartAccountMap::localFromGl` and looks the answer up among postable accounts. `FinanceReadiness` uses the same function to decide what a code "should" resolve to. There is no other mapping path, and posting reads only the stored link.

**The old rule** (`ChartAccountMap::localFromGl`):

1. find the first four-digit number **anywhere** in the text;
2. accept it if the text starts with it, **or** if that code has an entry in the account map.

Step 2's second half was written for one deliberate case: "Relevant 1400 PPE account" names a class, and an installation may map `1400` to say where the class posts. Against a map of a few hand-written entries that was sound.

**Why it broke.** A company profile is not a few entries. WNG's maps all 37 posting functions and 15 catalogue references, so "this code is in the map" became true of almost every code a row could mention.

**NE-018** is "money received from a client before WNG earns the revenue". Its debit text is:

> `Bank / Cash (credit is 2200 Client Deposits)`

The debit is whichever bank or cash account received the money. `2200` appears only in a parenthesis, and describes the **credit**. The old rule found `2200`, found it mapped (`client_deposits → CD-001`), and stored `CD-001` as NE-018's **debit** account, activating the code. A posting through it would have debited the Client Deposits liability for money coming in.

**Proof.** In `wng_target_rehearsal`, seeded under the profile: NE-018 active, linked to `CD-001`. On local `db`, no profile: NE-018 inactive, unlinked. Report 53A lists it as `CAPTURE_TIME (account named in prose; chosen per transaction)`. The new unit test reproduces the old outcome's input and asserts null.

---

## 5. NE-023 root cause

Same mechanism, different text. **NE-023** is "costs accumulated on an open project are moved to P&L" — an internal transfer whose account depends on the job's cost family:

> `Relevant 5100–5800 Cost of Sales account`

That is a **range** of eight accounts. The old rule read the first four digits, `5100`, found it mapped (`cos_direct_materials → COS-008`), and linked NE-023 to `COS-008 Cost of Sales:Materials`, activating it. Every such transfer, whatever the cost family, would have defaulted to the materials account.

**Proof.** Rehearsal: NE-023 active, linked to `COS-008`. Local `db`: inactive, unlinked. Report 53A: `CAPTURE_TIME`.

**Exposure.** Neither code is procurable, so neither appears in purchase pickers, and neither has a cost line in any database inspected. Nothing posted through them. The defect was latent, and would have been armed by the expense-code seed at cutover.

---

## 6. Expense-code fix

`ChartAccountMap::localFromGl` now reads only the two ways the catalogue designates an account:

| Text form | Example | Result |
|---|---|---|
| Leads with one code | `1211 Project WIP – Direct Materials` | That code, through the map (101 catalogue rows) |
| `Relevant <one code> … account`, **and** Finance has mapped that code | `Relevant 1400 PPE account` with `1400` mapped | The mapped account. Unmapped: null |
| A range, leading or after "Relevant" | `Relevant 5100–5800 Cost of Sales account` | null — no single account answers a range |
| A code anywhere else | `Bank / Cash (credit is 2200 Client Deposits)` | null — a mention is not a designation |
| No code | `Receiving cash/bank account` | null |

Against the requirements:

| Requirement | How it is met |
|---|---|
| No substring / narrative / fuzzy inference | The code must be the first token, or the object of the catalogue's own "Relevant <code>" form. Nothing else in the text is read. |
| No silent fallback to an unrelated account | Unresolved is null; the seeder leaves the code inactive and names it; posting refuses a code with no account. |
| Legitimate configured mappings preserved | The deliberate "map a class code" route is kept and still tested. |
| WNG profile mappings preserved | 100 of 101 designated codes resolve under the profile exactly as before; the 101st is NE-016 (loans payable), off by the profile's own decision. |
| `ChartAccountMap` architecture preserved | One method changed. Same callers, same signature, same map. |
| No hard-coded GL ids; no second mapping system | None added. |

**Effect on each code under the full WNG profile:** NE-018 → unresolved, inactive. NE-023 → unresolved, inactive. NE-020 and NE-021 (PPE, hire assets) → unresolved, as before. NE-002, NE-004, NE-015, NE-017 → unresolved, as before.

**One thing the fix does not do.** The seeder deliberately keeps an account link a code already has ("never wipe a link Finance has set"). A database seeded under the old rule therefore keeps NE-018 and NE-023 linked until someone clears them. That is true of `wng_target_rehearsal` today (it is rebuilt from scratch by the rehearsal pipeline, which clears it). A target seeded for the first time at cutover never acquires the links. The cutover prerequisites (§21) include checking for them.

---

## 7. WIP fallback investigation

Trace, with the state before this report:

| Stage | Where | Behaviour when `FINANCE_WIP_POLICY` is unset and the profile is active |
|---|---|---|
| Environment → config | `config/finance_accounts.php` | `wip_policy` = null; `map` = `FinanceChartProfile::map('wng', null)` |
| Profile → map | `FinanceChartProfile::map` | `$policy = $wipPolicy ?: $data['wip_policies']['default']` → **`capitalise`**. The nine WIP reference codes 1211–1219 map to `WIP-002…010`. |
| Map → account | `ChartAccountMap::local` | 1211 → `WIP-002` |
| Posting: uncoded cost | `JournalPostingService::accountByCode(UNCODED_COST_FALLBACK)` | Debits `WIP-002` |
| Posting: coded cost | `expense_codes.default_debit_account_id`, set by the seeder through the same map | 43 job-material codes linked to `WIP-002`, and the other WIP families likewise |
| Posting: release | `WorkInProgressReleaseService` | Moves `WIP-00x` → cost of sales on invoicing |
| Readiness (API) | `FinanceReadinessController` | Ready: "Chart profile 'wng' is active; WIP policy: **profile default**." |
| Readiness (CLI) | `FinanceReadiness` | No profile or policy check at all |
| Reconciliation | `FinancialReconciliationService` | Reads the raw setting: "policy not configured; no capitalisation assumption is made" |
| UI | Finance setup | WIP domain shown as POLICY_REQUIRED regardless (Report 73), while the readiness check beside it was green |

**Answer: YES.** Activating the profile without setting the policy would have capitalised project cost into `WIP-002…010`, with readiness reporting ready, on a default the profile itself annotates "Still OPEN for Finance confirmation". The only part of the system that noticed was the reconciliation report.

It had not happened: no profile is active in the main checkout, and the rehearsal sets the policy explicitly.

---

## 8. WIP fail-closed implementation

**The default is no longer an authority.** `FinanceChartProfile::map` applies a WIP policy only when one is named and the profile defines it. Otherwise each of the nine WIP functions maps to a reserved marker, `WIP-POLICY-REQUIRED`, which is not an account and can never become one (the chart command refuses that code).

Mapping to a marker, rather than mapping nothing, is deliberate: an unmapped reference code resolves to itself, and on a two-chart database `1211` exists, so project cost would have landed on the reference chart instead.

| Layer | Behaviour with the profile active and no approved policy |
|---|---|
| Account map | WIP functions → marker. The other 28 functions unchanged. Neither `WIP-00x` nor the cost-of-sales twins are chosen. |
| Function resolution | The nine WIP functions report unresolved. |
| Expense-code seed | Job-cost codes (debit text 1211–1219) get no account and stay inactive. Codes the policy does not govern link as usual. |
| Posting — uncoded cost | No account → refused. |
| Posting — coded project cost | `JournalPostingService::assertWipPolicy` refuses with `POLICY REQUIRED`, **even if the code still carries an account** from an earlier seed or from a reference chart alongside. Called at each of the six places a posting takes its debit from an expense code. |
| Posting — other costs | Office overheads, bank charges and the like are not the WIP policy's to decide and post normally. |
| WIP release | Refused with `POLICY REQUIRED`. Previously a family whose WIP account did not resolve was skipped silently, which would have left job cost off the P&L with nobody told. Because the release runs in the invoice-issue transaction, **issuing an invoice is refused** (HTTP 422 with the reason) until the policy is set. |
| Readiness (CLI) | New `Chart profile` check: `FAIL — POLICY REQUIRED — …`, with the instruction to set the policy Finance approved. The expense-code check no longer misreports the marker as a missing account. |
| Readiness (API / setup screen) | `chart_profile` not ready, message `POLICY REQUIRED — …`; `required_accounts` not ready. No frontend change: the screen renders the backend message. |
| Planning tool | `finance:complete-chart` may plan with the profile's suggestion, and prints `WIP policy: capitalise, DEFAULT / NOT APPROVED FOR PRODUCTION — FINANCE_WIP_POLICY is not set`. `--execute --cutover` without an approved policy is refused with `POLICY REQUIRED`. |

No policy was chosen. `capitalise` and `expense_on_capture` both clear the block identically (tested).

**What "approved" means to the software.** The software can only see that `FINANCE_WIP_POLICY` is set to a policy the profile defines. That the value reflects a signed decision by WNG Finance is a human control (§19, §21); setting the variable is not itself the approval.

**No effect where no profile is active.** Development, the test suite and any reference-chart installation have one treatment by construction and are untouched.

---

## 9. Target chart identity inspection design

**Where it lives.** In the existing `finance:complete-chart` dry run. No new command and no new framework: the dry run already read the chart, the profile, the paying accounts and the journal usage. It now also states, as its second line of output and under `target_identity` in its JSON report, what chart it is looking at.

**How the identity is decided** — from the inspected database and the profile file only:

| Identity | Rule |
|---|---|
| `WNG_CHART_ONLY` (`<PROFILE>_CHART_ONLY`) | Every existing account the profile posts to is present, and the only reference-chart accounts present are ones the profile names (its `erp_added_accounts`, an account it maps to directly, or a declared `reuse_existing`). |
| `TWO_CHART_STATE` | At least one of the company's accounts is present **and** at least one reference-chart account the profile does not name. |
| `OTHER / MANUAL_REVIEW_REQUIRED` | Anything else: accounts the profile posts to are missing, or four-digit accounts exist that are neither the reference chart's nor named by the profile. |

A reference-chart account is recognised by **code**, against the reference seeder's own list (`ChartOfAccountSeeder::referenceCodes()`). Never by name, and never by the mere fact of being four digits.

**The procedure for the cutover database.** Run by a named operator, on the target host, before anything else in the cutover:

```
# 1. Confirm which database the application is pointed at. Reads configuration only.
php artisan config:show database.default
php artisan config:show database.connections.mysql.database     # or .mariadb, per the line above

# 2. The inspection. No --execute, no --cutover, no --classify-existing, no --disable-unlinked-sources.
php artisan finance:complete-chart --profile=wng -v --output=<a directory outside the web root>
echo "exit status: $?"
```

`--profile=wng` is given on the command line, so the inspection does not require the profile to be activated in the environment first, and activating nothing is the point.

**What it reports**, against the list in the brief:

| Required | Where in the output | JSON key |
|---|---|---|
| Database name | Line 1: `DRY RUN — profile 'wng' on '<database>'` | `database` |
| Total / active / postable accounts | `Chart of accounts:` line | `target_identity.counts.total / active / postable` |
| WNG mnemonic account count | same line, "company-coded" | `…counts.company_coded` |
| ERP numeric / reference account count | same line, "four-digit (N reference-chart, of which M named by the profile)" | `…counts.numeric / reference / reference_acknowledged / reference_unnamed / unexplained_numeric` |
| Duplicate names | one line per duplicated name with its codes | `target_identity.duplicate_names` |
| Duplicate posting-function equivalents | `WARNING — N reference-chart account(s) sit beside the account the profile posts to`, listed under `-v` | `reference_twins` |
| Journal lines on potentially conflicting accounts | one line per reference account carrying postings; also inside each refusal | `target_identity.reference_accounts_with_postings`, `reference_twins[].journal_lines` |
| WNG profile dry-run result | exit status and the `BLOCKED` block, if any | `mode`, `blocking` |
| Proposed creations / reuses / conflicts | accounts table and `Accounts: N to create, … met by an existing account (declared reuse), N in conflict` | `accounts[].action` |
| 37 posting-function states | functions table, `Status` column | `functions.<key>.status` |
| Classification state | `Classification preview` line; full before → proposed table under `-v` | `classification_preview` |
| Payment-source account links | paying-account table: linked now / profile declares / state | `payment_sources` |
| WIP policy standing | Line 1: configured, or `DEFAULT / NOT APPROVED FOR PRODUCTION` | `wip_policy`, `wip_policy_source`, `wip_policy_approved` |

**Why it cannot mutate.**

- Without `--execute` the command issues only `SELECT`s. The single write path (`$db->transaction(…)`) is inside `if ($execute)`.
- `--output` writes one JSON file to the directory named. That is a file on disk, not the database; omit it to write nothing at all.
- It refuses to run at all against the configured live **source** database name.
- Tests assert, for a clean chart, a blocked chart, a two-chart database and an unrecognised one, that every column of every chart row, every paying account and every journal line is identical after the run.
- In this stream it was run against three databases; their chart and unclassified counts were identical before and after (`74a-evidence/00-chart-counts-*.txt`).

**What it does not tell you.** It reads the chart, not the ledger's correctness. `WNG_CHART_ONLY` means "the accounts present are WNG's"; it does not certify balances, history, or that the account behind a paying account is the bank WNG uses today.

---

## 10. Two-chart detection safeguards

On `TWO_CHART_STATE` the run puts this first among its blocking reasons, and `--execute` refuses before any write:

> `ACCOUNTANT REVIEW REQUIRED — TWO CHARTS DETECTED: this database holds the company's chart and N reference-chart account(s) (…), M of them carrying journal lines. Nothing is deleted, merged, moved, renamed, remapped or adopted automatically. An accountant decides which chart this database keeps; a reference account is used only where the profile declares it (reuse_existing, or erp_added_accounts).`

| The software must not automatically… | Status |
|---|---|
| delete one chart | No code path deletes a chart account. Tested: every row identical after the run. |
| merge accounts | None exists. |
| move journal lines | None exists. Tested: every journal line identical. |
| rename accounts | None exists; an existing account is never modified. |
| remap posted history | None exists. |
| declare numeric accounts duplicates by name alone | Reference accounts are identified by code. The test seeds `1100 Accounts Receivable` beside `AR-001 Accounts Receivable (A/R)`: found by code, and correctly **not** listed as a duplicate name. |
| adopt reference accounts automatically | The account map is unchanged by detection. Tested: `output_vat` still maps to `VAT-001` with `2110` present. |

**Explicit reuse still works, and only as declared.** A `reuse_existing {code, name}` declaration removes that one account from the "not named by the profile" list. The other reference accounts remain, so the database is still `TWO_CHART_STATE` and still stops (tested: declaring reuse of `2110` with three other reference accounts present still refuses). A two-chart database therefore cannot be completed until every reference account in it is either declared for reuse, acknowledged in the profile, or gone — each of which is a deliberate, reviewable act by a person.

`OTHER / MANUAL_REVIEW_REQUIRED` also refuses `--execute`, with `MANUAL REVIEW REQUIRED`.

**Also blocked on these states:** `--classify-existing` and `--disable-unlinked-sources`, because they share the same refusal (tested).

---

## 11. Clean 123-account rehearsal result

`php artisan finance:complete-chart --profile=wng -v` against `wng_source_rehearsal`. Evidence: `74a-evidence/01-identity-dry-run-wng_source_rehearsal.txt` and `identity-wng_source_rehearsal/chart_completion.json`.

| | Result |
|---|---|
| Exit | 0 |
| Identity | **`WNG_CHART_ONLY`** — all 29 existing accounts the profile posts to are present; no reference-chart account beyond the 3 the profile names |
| Chart | 123 accounts, 123 active, 123 postable; 120 company-coded; 3 four-digit (2160, 7150, 7550) |
| Duplicated names | 0 |
| Reference accounts carrying postings | 0 |
| To create | 29 |
| Declared reuse | 0 |
| Conflicts | 0 |
| Functions | 37 / 37 (16 `RESOLVED_EXISTING`, 21 `RESOLVED_PROPOSED_NEW`) |
| Classification preview | 116 to fill, 4 left for the accountant, 0 outside the profile |
| Blocking reasons | 0 |
| WIP policy line | `capitalise, DEFAULT / NOT APPROVED FOR PRODUCTION — FINANCE_WIP_POLICY is not set` |

Unchanged from Report 74 in substance. The same run with `FINANCE_WIP_POLICY=capitalise` in the environment prints `configured (FINANCE_WIP_POLICY)` (`02-policy-labels.txt`).

For comparison, from the same evidence set:

| | `wng_target_rehearsal` (completed) | Local `db` |
|---|---|---|
| Identity | `WNG_CHART_ONLY` | **`TWO_CHART_STATE`** |
| Chart | 152 accounts; 149 company-coded; 3 four-digit | 208 accounts; 120 company-coded; 88 four-digit, 85 not named by the profile |
| Create / present / conflict | 0 / 29 / 0 | 4 / 0 / 25 |
| Functions | 37 / 37 | 16 / 37 |
| Reference accounts with postings | 0 | 5 |
| Exit | 0 | 1, `ACCOUNTANT REVIEW REQUIRED — TWO CHARTS DETECTED` |

These are local and rehearsal databases. They show the tool distinguishes the states. They say nothing about production.

---

## 12. 37-function verification

From the three JSON reports. The last column is the effect of §8: with the profile active and no approved policy, the nine WIP functions map to nothing and the other 28 are unaffected (tested: exactly those nine report unresolved).

| Function | Ref | Account | Name | WNG chart, before completion | Rehearsal target, completed | Local `db` (two charts) | With no approved WIP policy |
|---|---|---|---|---|---|---|---|
| `bank_default` | 1010 | EQB-001 | Equity Bank | RESOLVED_EXISTING | RESOLVED_EXISTING | RESOLVED_EXISTING | unaffected |
| `petty_cash_float` | 1030 | PETTY-001 | Petty cash | RESOLVED_EXISTING | RESOLVED_EXISTING | RESOLVED_EXISTING | unaffected |
| `accounts_receivable` | 1100 | AR-001 | Accounts Receivable (A/R) | RESOLVED_EXISTING | RESOLVED_EXISTING | RESOLVED_EXISTING | unaffected |
| `staff_advances` | 1300 | STD-001 | Short Term Debtors | RESOLVED_EXISTING | RESOLVED_EXISTING | RESOLVED_EXISTING | unaffected |
| `input_vat` | 1330 | VAT-002 | Input VAT Recoverable | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | unaffected |
| `inventory` | 1200 | IA-001 | Inventory Asset | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | unaffected |
| `wip_direct_materials` | 1211 | WIP-002 | Work in Progress:Materials | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | POLICY REQUIRED — maps to nothing |
| `wip_direct_labour` | 1212 | WIP-003 | Work in Progress:Direct Labour | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | POLICY REQUIRED — maps to nothing |
| `wip_subcontractors` | 1213 | WIP-004 | Work in Progress:Subcontractors | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | POLICY REQUIRED — maps to nothing |
| `wip_transport_logistics` | 1214 | WIP-005 | Work in Progress:Transport & Delivery | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | POLICY REQUIRED — maps to nothing |
| `wip_equipment_site` | 1215 | WIP-006 | Work in Progress:Equipment Hire & Site | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | POLICY REQUIRED — maps to nothing |
| `wip_project_utilities` | 1216 | WIP-007 | Work in Progress:Project Utilities | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | POLICY REQUIRED — maps to nothing |
| `wip_project_facilitation` | 1217 | WIP-008 | Work in Progress:Field Facilitation | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | POLICY REQUIRED — maps to nothing |
| `wip_venue_statutory` | 1218 | WIP-009 | Work in Progress:Venue & Permits | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | POLICY REQUIRED — maps to nothing |
| `wip_rework_warranty` | 1219 | WIP-010 | Work in Progress:Rework & Warranty | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | POLICY REQUIRED — maps to nothing |
| `accounts_payable` | 2100 | AP-001 | Accounts Payable (A/P) | RESOLVED_EXISTING | RESOLVED_EXISTING | RESOLVED_EXISTING | unaffected |
| `output_vat` | 2110 | VAT-001 | Output VAT Payable | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | unaffected |
| `wht_payable` | 2120 | WHT-001 | Withholding Tax Payable | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | unaffected |
| `paye_payable` | 2130 | PL-002 | Payroll liabilities:PAYE | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | unaffected |
| `statutory_payable` | 2140 | PL-003 | Payroll liabilities:Statutory & Other Deductions | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | unaffected |
| `accrued_expenses` | 2150 | AE-001 | Accrued Expenses | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | unaffected |
| `net_payroll_payable` | 2160 | 2160 | Net Payroll Payable | RESOLVED_EXISTING | RESOLVED_EXISTING | RESOLVED_EXISTING | unaffected |
| `client_deposits` | 2200 | CD-001 | Client Deposits | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | unaffected |
| `opening_balance_equity` | 3900 | OBE-001 | Opening Balance Equity | RESOLVED_EXISTING | RESOLVED_EXISTING | RESOLVED_EXISTING | unaffected |
| `project_revenue` | 4100 | SAL-002 | Sales:Project Revenue | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | unaffected |
| `cos_direct_materials` | 5100 | COS-008 | Cost of Sales:Materials | RESOLVED_EXISTING | RESOLVED_EXISTING | RESOLVED_EXISTING | unaffected |
| `cos_direct_labour` | 5200 | PE-007 | Personnel Expenses:Wages-Direct Labour | RESOLVED_EXISTING | RESOLVED_EXISTING | RESOLVED_EXISTING | unaffected |
| `cos_subcontractors` | 5300 | COS-020 | Subcontractors - COS | RESOLVED_EXISTING | RESOLVED_EXISTING | RESOLVED_EXISTING | unaffected |
| `cos_transport_logistics` | 5400 | COS-016 | Cost of Sales:Transport & Delivery | RESOLVED_EXISTING | RESOLVED_EXISTING | RESOLVED_EXISTING | unaffected |
| `cos_equipment_site` | 5500 | COS-021 | Cost of Sales:Equipment Hire & Site | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | unaffected |
| `cos_project_utilities` | 5600 | COS-022 | Cost of Sales:Project Utilities | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | unaffected |
| `cos_project_facilitation` | 5700 | COS-006 | Cost of Sales:Field Facilitation | RESOLVED_EXISTING | RESOLVED_EXISTING | RESOLVED_EXISTING | unaffected |
| `cos_venue_statutory` | 5800 | COS-023 | Cost of Sales:Venue & Permits | RESOLVED_PROPOSED_NEW | RESOLVED_EXISTING | CONFLICT | unaffected |
| `cos_rework_warranty` | 5900 | COS-018 | Other - COS | RESOLVED_EXISTING | RESOLVED_EXISTING | RESOLVED_EXISTING | unaffected |
| `inventory_adjustments` | 6800 | INV-001 | Inventory Shrinkage | RESOLVED_EXISTING | RESOLVED_EXISTING | RESOLVED_EXISTING | unaffected |
| `salaries_expense` | 7550 | PE-006 | Personnel Expenses:Salaries | RESOLVED_EXISTING | RESOLVED_EXISTING | RESOLVED_EXISTING | unaffected |
| `bank_charges` | 7800 | FIN-003 | Finance cost:Bank charges | RESOLVED_EXISTING | RESOLVED_EXISTING | RESOLVED_EXISTING | unaffected |

All 37 are accounted for on WNG's chart: 16 on existing accounts, 21 on accounts the profile creates, none in conflict, none unresolved. The six mappings Report 74 marked for the accountant's confirmation (`staff_advances`, `cos_direct_labour`, `cos_project_facilitation`, `cos_rework_warranty`, `inventory_adjustments`, `bank_charges`) are still open; resolving technically is not confirmation.

---

## 13. Tests

All against the disposable `db_test`; the harness refuses any database not named `*_test`.

**Targeted run** — `74a-evidence/04-targeted-tests.txt`: **84 passed, 0 failed** (539 assertions).

| Suite | Covers | Result |
|---|---|---|
| `Unit/Finance/ChartAccountMapTest` | `ChartAccountMap`, the resolver | 8 passed (6 existing, 2 new) |
| `Feature/Finance/WngChartCompletionTest` | chart completion, profile loading, reuse, identity, two-chart stop, WIP policy, NE-018 / NE-023, readiness under the profile, paying-account report, execution guards | 45 passed (36 from Report 74, 9 new) |
| `Feature/Finance/WngChartMappingTest` | `FinanceAccountFunctions`, the 37-function registry | passed |
| `Feature/Finance/FinanceReadinessTest` | readiness, expense-code seeding behaviour | passed |
| `Unit/Finance/RequisitionSchemaServiceTest` | unrelated unit suite in the same directory | passed |

**Regression run, on the final code** — `05-finance-regression.txt`: `tests/Feature/Finance`, `tests/Unit/Finance`, `tests/Feature/CostCollector`, `tests/Feature/PettyCash`, `tests/Feature/Seeding`:

**971 passed, 1 failed** (7,756 assertions, 832 s). The one failure is the order-dependent test examined in §14. No code changed after this run.

That run includes the payment-source guards (`PaymentArchitectureTest`, `ReceivablesPostingTest` with the Report 74 Supplier Credit guard, `SettlementAccountTest`), `WorkInProgressReleaseTest`, `JournalPostingTest`, `AccountingPeriodControlTest`, `FinanceControlCentreTest` and `InventoryFinanceTest`, all passing: the new guards do not disturb the reference-chart behaviour those suites exercise.

Not run: Projects, Stores, Procurement, HR and other non-Finance suites; and the real-data rehearsal smoke suite, which commits data and needs the rehearsal databases rebuilt. The rehearsal pipeline should be re-run before cutover, since the resolver change alters which expense codes it leaves active (NE-018 and NE-023 will no longer be).

New tests, by requirement:

| Requirement (brief) | Test |
|---|---|
| B1. NE-018 per its authoritative configuration | `WngChartCompletionTest::under_the_full_profile_the_codes_that_only_mention_an_account_stay_unresolved` — NE-018 text unchanged, no account, inactive, with `2200` mapped to `CD-001` |
| B2. NE-023 likewise | same test — with `5100` mapped to `COS-008` |
| B3. A code only in descriptive text cannot resolve | `ChartAccountMapTest::a_code_merely_mentioned_in_the_text_never_resolves_however_fully_the_chart_is_mapped` — eight texts, including three spellings of a range |
| B4. Legitimate codes still resolve | `ChartAccountMapTest::the_two_ways_the_catalogue_designates_an_account_still_resolve`; and in the feature test, 101 designated codes → 100 active, only NE-016 off, spot-checked to `WIP-002` and `FIN-003` |
| B5. Missing mappings fail visibly | `WngChartCompletionTest::a_designated_account_missing_from_the_chart_fails_readiness_by_name` — code unlinked and inactive, not redirected; readiness fails naming `OPE-022` |
| C. Default never applied | `with_no_wip_policy_set_the_profile_default_is_never_applied` |
| C. Posting refused, readiness says POLICY REQUIRED | `with_no_wip_policy_project_cost_cannot_post_and_readiness_says_policy_required` — function resolution, uncoded fallback, a code still carrying an account, an overhead that must still pass, the release, CLI readiness, API readiness, then both policies clearing it |
| C. Seed links nothing | `seeding_the_catalogue_with_no_wip_policy_links_no_job_cost_code` |
| C. Label and no cutover on a default | `the_planning_tool_labels_the_suggested_policy_and_never_cuts_over_on_it`; and `a_declared_reuse_loosens_none_of_the_execution_guards` extended |
| D. Identity, clean chart | `wngs_own_chart_is_identified_as_such` — identity and every count |
| D/E. Two charts stop; nothing merged, moved, renamed or adopted | `two_charts_in_one_database_stop_the_run_and_nothing_is_merged_moved_renamed_or_adopted` |
| D. Anything else → manual review | `a_chart_that_is_neither_is_sent_for_manual_review` |

One existing test was changed because the behaviour it described changed: `the_wip_timing_policy_is_a_switch_not_a_chart_fact` asserted that `problems('wng', null)` was empty and that an unknown policy left WIP codes out of the map. It now asserts `POLICY REQUIRED` and the marker.

---

## 14. CostCollector order-dependent test investigation

**Test:** `CostCollectorApiTest > the picker searches and filters by family`. It creates an expense code "Truck hire", searches the picker for `truck`, and expects exactly one result.

**Classification: fixture isolation defect, expressed as an order dependency. Not a genuine regression.**

**Mechanism.**

1. The expense catalogue is seeded by a migration, so every test database holds it, including `TL-HIR-001 Truck and vehicle hire`. On a freshly migrated database that row is **inactive** (the migration runs before most of the chart exists). The picker lists active codes only, so the test's own code is the single match.
2. `Tests\Feature\Finance\W7LabourConcurrencyTest` cannot use the usual wrapping transaction: it forks real processes that need to see its data, so it **commits**. Its set-up runs `ChartOfAccountSeeder` and `ExpenseCodeSeeder`, which link and **activate** the catalogue, `TL-HIR-001` included.
3. Its tear-down deletes every row with an id above a snapshot taken at set-up. That removes what it *inserted*. It does not undo what the seeders *updated* on rows that already existed. `TL-HIR-001` stays active, committed, and still pointing at a chart account the tear-down has just deleted (foreign-key checks are switched off for that delete).
4. Any later class that assumes a pristine catalogue now sees it. The picker returns two matches for `truck`.

**Evidence.**

| Run | Result | `TL-HIR-001` committed afterwards |
|---|---|---|
| `tests/Feature/CostCollector` alone (262 tests) | all pass | inactive; 2 active codes in the catalogue |
| `FinanceReadinessTest` → `CostCollectorApiTest` (control) | all pass | inactive; 2 active codes |
| `FinanceReadinessTest` → `W7LabourConcurrencyTest` → `CostCollectorApiTest` | **picker test fails**, 22 others pass | **active, pointing at account id 696 in a chart that holds 3 accounts**; **101 active codes** |
| Full regression (Finance before CostCollector) | picker test fails, 971 pass | — (a later class rebuilds the schema, which is why the database looks clean once the run ends) |

The middle row is the proof: the only difference from the control is the concurrency test, and it leaves 101 catalogue codes committed as active, each holding the id of a chart account its own tear-down then deleted.

A note on method, because it nearly produced a wrong answer: a first attempt ran the concurrency test immediately followed by the picker test, and both passed. That order proves nothing. The picker test's class is then the first in the process to use the database-refresh trait, so it rebuilds the whole schema and erases the leak before running. The experiment above puts an ordinary class first so the rebuild has already happened.

It is unrelated to Report 74 or 74A: neither test file and nothing on the picker's path was touched, and the failure appears only when the Finance directory runs before the CostCollector directory in one process.

**Not changed**, per the instruction not to alter unrelated logic to turn an order-dependent test green. The correct repair is in the tests, either of:

- `W7LabourConcurrencyTest` restoring the catalogue rows it updates (snapshot and restore `expense_codes.is_active` / `default_debit_account_id`), which fixes the leak for every later class; or
- the picker test searching for a term only its own fixture contains.

The first is the real fix; the second only hides this one symptom. No production code needs to change.

---

## 15. Files changed

`git diff --check`: clean, across the whole worktree

By this stream (74A):

| File | Change |
|---|---|
| `app/Modules/Finance/Support/ChartAccountMap.php` | `localFromGl` reads only a designated account (§6) |
| `app/Modules/Finance/Support/FinanceChartProfile.php` | WIP policy never defaulted; marker; `suggestedWipPolicy`, `wipPolicies`, `wipFunctions`, `wipPolicyBlock`, `dependsOnWipPolicy`; `problems()` reports `POLICY REQUIRED` (§8) |
| `app/Modules/Finance/Services/JournalPostingService.php` | `assertWipPolicy`, called where a posting takes its debit from an expense code (six places) |
| `app/Modules/Finance/Services/WorkInProgressReleaseService.php` | Release refused without an approved policy |
| `app/Modules/Finance/Support/FinanceReadiness.php` | New `Chart profile` check; marker not misreported as a missing account |
| `app/Modules/Finance/Controllers/FinanceReadinessController.php` | One message: no longer says "profile default" |
| `app/Modules/Finance/Console/CompleteChartCommand.php` | Target identity; two-chart and manual-review stops; duplicate names; policy label; no cutover on a default; reserved code |
| `app/Modules/Finance/Database/Seeders/ChartOfAccountSeeder.php` | `referenceCodes()` — a read-only accessor to the existing list. Seeding behaviour unchanged |
| `tests/Unit/Finance/ChartAccountMapTest.php` | 2 tests added |
| `tests/Feature/Finance/WngChartCompletionTest.php` | 9 tests added, 2 amended |
| `docs/finance-redesign/phase-2/74A_FINANCE_PRE_CUTOVER_BLOCKER_CLOSURE.md` | This report |
| `docs/finance-redesign/phase-2/74a-evidence/` | Dry runs, JSON reports, readiness output, test output |

Five of these already carried Report 73/74 edits (`CompleteChartCommand`, `FinanceChartProfile`, `FinanceReadiness`, `FinanceReadinessController`, `WngChartCompletionTest`); those edits are intact and this stream's are additional.

Not changed: the WNG profile, any migration, any seeder's behaviour, any frontend file. No backend response changed shape; the readiness endpoint returns the same keys with a different message, so no frontend work was required.

Nothing committed or pushed.

---

## 16. Remaining SOFTWARE blockers

**None for the chart cutover.**

Known and deliberately left, none blocking:

| Item | Why it is not a blocker |
|---|---|
| `W7LabourConcurrencyTest` leaks committed catalogue state (§14) | Test-suite hygiene. No production effect. |
| A database seeded under the old resolver keeps NE-018 / NE-023 linked (§6) | Applies to a database that already ran the old seed. Covered by a check in §21. |
| Several document series are not gap-free or lock-protected (Report 74 §21) | Not a chart matter; needs a numbering policy first. |
| `--classify-existing` applies the whole profile list, not a subset | Works as designed; the control is the accountant's sign-off of the list. |

---

## 17. Remaining CONFIGURATION blockers

1. `FINANCE_ACCOUNT_PROFILE=wng` set on the target — after the accountant's sign-off, as part of the cutover.
2. `FINANCE_WIP_POLICY` set on the target to the approved value, and the configuration cache rebuilt. Until then the software now refuses project-cost postings and reports `POLICY REQUIRED`.
3. Paying-account links on the target matching the profile. On a `WNG_CHART_ONLY` target the reference step sets them; the dry-run table verifies them.

---

## 18. Remaining ACCOUNTANT REVIEW items

1. If the target is `TWO_CHART_STATE`: which chart the database keeps, and what happens to any reference account carrying postings.
2. Sign-off of the 37 function mappings and the 29 new accounts, with explicit confirmation of the six flagged mappings (§12).
3. Sign-off of the 116 proposed account classifications, in particular the 13 the profile classifies with a recorded judgement.
4. OPE-026, ITX-001, LDO-001, EQE-001 — left unclassified.
5. Tax reference data against WNG's actual rates, categories and thresholds.
6. Whether CASH-001, SBM-001, NIC-001 and FK-001 are live accounts.

---

## 19. Remaining POLICY decisions

1. **WIP policy: `capitalise` or `expense_on_capture`.** Not decided here. Now enforced as a precondition.
2. M-Pesa: a balance WNG holds, or a channel settling into a bank; and which account. While it is disabled, M-Pesa receipts are refused.
3. Card: whether a company card exists and where it settles.
4. Activation of the Stanbic, KCB and Family paying accounts.
5. Finance settings: none of 15 is approved; 8 are unset (petty cash cap, surrender deadline, approval thresholds and others).
6. Document-numbering policy and continuation numbers.
7. Which historical periods are closed before go-live (all 48 are open).

---

## 20. Remaining DATA gates

1. **Target chart identity.** Unknown until §9's procedure is run on the cutover database. Everything else waits on it.
2. Opening balances, retained earnings and historical financial data — out of scope by instruction, untouched.
3. WNG's last-used document numbers, for item 6 above.

---

## 21. Exact attended-cutover prerequisites

All must be true before `--execute` is typed. Items 1–3 are reads; nothing in this list writes.

| # | Prerequisite | How it is established |
|---|---|---|
| 1 | The database is the intended target, and not the live source | `config:show` (§9 step 1) |
| 2 | **Identity is `WNG_CHART_ONLY`** | §9 step 2. `TWO_CHART_STATE` or `OTHER` → stop; go to §18 item 1 |
| 3 | The dry run shows 29 to create (or the expected remainder), 0 in conflict, 37 / 37, exit 0, no `BLOCKED` | Same output |
| 4 | WIP policy decided and signed by WNG Finance | Signed record held outside the system |
| 5 | Profile mappings and new accounts signed by the accountant | Signed copy of the dry-run report |
| 6 | Classification list signed, or `--classify-existing` omitted | Signed copy of the classification preview |
| 7 | `FINANCE_ACCOUNT_PROFILE=wng` and `FINANCE_WIP_POLICY=<signed value>` set; cache rebuilt | Dry-run line 1 reads `configured (FINANCE_WIP_POLICY)`, not `DEFAULT / NOT APPROVED FOR PRODUCTION` |
| 8 | `finance:readiness` shows `Chart profile: PASS` | Its output |
| 9 | No stale indirect expense-code link | `SELECT code, default_debit_account_id, is_active FROM expense_codes WHERE default_debit_gl NOT REGEXP '^[0-9]{4} ' AND (default_debit_account_id IS NOT NULL OR is_active = 1)` returns no rows. Any row is a decision for Finance, not something to clear automatically |
| 10 | Fresh, restore-tested backup; write freeze; queue workers stopped for the window; named operator and accountant present | Report 74 §29 steps 1–2 |

The execution sequence itself is Report 74 §29 and is unchanged, with two additions the software now enforces rather than leaving to the checklist: it will not run on anything but `WNG_CHART_ONLY`, and it will not run `--cutover` without an approved policy.

---

## 22. Verdict

**FINANCE PRE-CUTOVER SOFTWARE BLOCKERS CLOSED — TARGET IDENTITY AND HUMAN DECISIONS REMAIN**

The three software items are fixed and covered by tests: expense codes resolve only from a designated account; the WIP policy is never assumed and its absence stops project-cost accounting with `POLICY REQUIRED`; and the cutover tool identifies the chart in front of it and stops on two charts.

What remains is not software: what the production database actually holds, which only the read-only inspection on that database can answer, and the decisions listed in §17–§20.

This is not a statement that the system is production-ready, that any policy is approved, or that the cutover may proceed.

**Stopped here.** No cutover, no `--execute`, no `--cutover`, no production classification, no production seeding, no opening balances, no retained earnings, no history repair, no policy or classification decision, no queue worker started, no deployment.
