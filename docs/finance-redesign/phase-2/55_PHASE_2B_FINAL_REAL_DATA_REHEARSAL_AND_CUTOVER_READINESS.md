# Report 55 — Phase 2B: Final Real-Data Rehearsal and Cutover Readiness

**Date:** 2026-09-28
**Predecessors:**
- Report 52 (real-data source-copy rehearsal);
- Report 53 (D3 mapping);
- Report 54 (WNG chart completion).

**Scope:** complete the remaining configuration in the rehearsal only, then rebuild the rehearsal cleanly from the verified snapshot and prove W1–W7 end to end on real WNG data.

**Where it ran:** the local rehearsal databases only:
- `wng_source_pristine`
- `wng_source_rehearsal`
- `wng_target_rehearsal`

They were built from `database/erpsystem-20260928-0918.sql.gz`, SHA-256 `28bced26bad9b49ddbc4731f288b4c0bd1095e47076d254bafa99604c9e945e2`.

**Evidence:** everything is in `storage/app/wng_rehearsal/rehearsal-reports/`:
- the final run: `run55-final.log`, plus the smoke JSON it names;
- dry-run, import, reconciliation, chart and overtime reports;
- frontend: `fe55-*`, `routes55.json`;
- backend regression: `regress55.log`.

---

## 1. Executive Summary

**Result:** the rehearsal was rebuilt from the verified snapshot by one scripted command, and every Finance workflow W1–W7 ran end to end on real WNG projects, employees and users.
- **Technical failures: 0.**
- **Technical defects found and fixed:** 2 backend, plus 1 frontend permission-gate defect.
- **The workflows that remain blocked are blocked by named business decisions, not by code.**

| Question | Answer |
|---|---|
| Migration / reconciliation | PASS. 229 tables reconciled, 0 mismatched, 0 orphans introduced, identical ids, and Finance history exclusions hold. |
| Chart | 152 accounts; 37/37 functions; a rerun creates 0; 116 existing accounts classified; 4 pending the accountant. |
| WIP | Working configuration CAPITALISE. Release proven on a real job across 9 families: partial, full, idempotent and void. Production approval pending accountant sign-off. |
| M-Pesa / Card | Both kept **unlinked and disabled**. Neither is invented or mapped to a bank. **WNG decision required.** |
| Loans payable | Not configured (no evidence). It blocks nothing. |
| W1–W7 smoke | **71 PASS, 0 FAIL, 2 BLOCKED BY WNG DECISION** (R-2 custody, MIG-P1 M-Pesa receipts), 4 INFO. |
| Finance readiness (API) | `ready=true`, 37/37, every integrity counter 0. |
| Backend regression | **1,512 / 1,512 passed** on the standard `db_test` harness. |
| Frontend | 169/169 unit tests; 256 TS errors = ENG-1 baseline (0 new); build OK; API contract 215/216 matched (one dead call, never used). |
| Verdict | **PARTIAL — PHASE 2B TECHNICALLY READY WITH BUSINESS DECISIONS OUTSTANDING** |

**Defects found and fixed during this report:**
1. Readiness counted analytical W7 labour lines as "verified costs without a journal". The stored `source_type` is the short string `ProjectLabourActual`, not the class name. Fixed with a shared constant and a regression test.
2. Recording a client receipt into a **disabled** paying account returned **HTTP 500**. It now returns 422 with a field error. Regression test added.
3. Frontend: the petty-cash requisition quick actions (Approve / Disburse / Reconcile) were gated on permissions that do not exist, so they were invisible to everyone. Fixed (§34).

The remaining smoke corrections were test-harness errors, not product defects. §14 lists them.

---

## 2. Safety / Isolation

| Rule | Held? | How |
|---|---|---|
| `woodnork_erpsystem` not touched | Yes | Never connected to or queried. The rehearsal reads only the checksum-verified snapshot file. |
| Live `woodnork_erp` not touched | Yes | Every command ran against the `wng_*` databases through the rehearsal checkout's own `.env` (`DB_DATABASE=wng_target_rehearsal`). |
| No production migrations, import, accounts, opening balances or journals | Yes | The only journals created are rehearsal smoke journals in `wng_target_rehearsal`. There are no opening balances anywhere. |
| No production queue workers | Yes | The rehearsal drains its own `database` queue with `queue:work --stop-when-empty` inside the test process. |
| No URL, DNS or deploy changes; the existing ERP stays running; no W8 | Yes | Nothing was pushed. The branch `finance/critical-stabilization-fixes` is local. `master` was not touched. |
| Personal data | Held | The snapshot is excluded via `.git/info/exclude`. No password hash or salary value is printed in this report or in the logs. The payroll step reports counts only. |

---

## 3. Decisions Applied

| Decision | Applied as |
|---|---|
| MIG-D3a WIP timing | `FINANCE_WIP_POLICY=capitalise` in the rehearsal `.env` (§4). |
| MIG-D3b classification of existing accounts | `finance:complete-chart --classify-existing` fills NULL `account_type` / `normal_balance` only (§9). |
| M-Pesa / Card | Kept unlinked. They are **disabled** (not deleted) by `finance:complete-chart --disable-unlinked-sources` because they are unlinked and unused (§6, §7). |
| Loans payable (2300) | Recorded as intentionally unconfigured. Readiness reports it as off by design, not as a gap (§8). |
| D2 | Stays **CLOSED**: no historical POs/GRNs/bills imported (§15). |
| R-1 | Reconfirmed with the project's real Project Officer (§11). |
| R-2 | Stays **OPEN**. Options reported, custody not granted (§12). |
| R-3 | Reconfirmed through the API as Accounts (§13). |

The decision register (`03_WNG_FINANCE_DECISION_REGISTER.md`) was updated:
- MIG-D3a, MIG-D3b and MIG-D4 revised;
- new entries MIG-P1 (M-Pesa), MIG-P2 (Card), MIG-P3 (Loans), MIG-H1 (payroll master data) and MIG-H2 (D6 department classification).

Reports 52–54 were corrected where they named the custody permission `finance.petty_cash.custody`. The real name is `finance.petty_cash.manage_custody`.

---

## 4. WIP Working Policy

**WIP WORKING CONFIGURATION = CAPITALISE**

**PRODUCTION ACCOUNTING POLICY APPROVAL = PENDING ACCOUNTANT SIGN-OFF**

What this means:
- Actual job cost is held in the family's WIP account (1211–1219).
- The cost is released to that family's cost-of-sales account in proportion to billing:

  billed fraction = (posted, non-void invoice totals) ÷ (latest approved quote amount)

- The policy is an environment setting, not code. `expense_on_capture` remains available and is defined in the profile. If the accountant chooses it, the change is one `.env` value with no migration.
- This is a working configuration for the rehearsal and for cutover preparation. It is **not** an approved production accounting policy.

---

## 5. WIP Release Validation

Proven on a **real WNG job**: enquiry #1473 (ENQ-09-2026-095), latest approved quote KES 55,680.00.

**Nine families were exercised.** Each got its own actual cost through a real expense code, disbursed from petty cash and drained through the queue:

| Family | WIP | Expense code | Cost |
|---|---|---|---|
| Direct materials | WIP-002 | DM-WD-001 | 1,000 |
| Direct labour | WIP-003 | DL-CAS-001 | 2,000 |
| Subcontractors | WIP-004 | SC-FAB-001 | 3,000 |
| Transport / logistics | WIP-005 | TL-HIR-001 | 4,000 |
| Equipment / site | WIP-006 | EQ-HIR-001 | 5,000 |
| Project utilities | WIP-007 | PU-PWR-001 | 6,000 |
| Project facilitation | WIP-008 | PF-MEA-001 | 7,000 |
| Venue / statutory | WIP-009 | VS-VEN-001 | 8,000 |
| Rework / warranty | WIP-010 | RW-MAT-001 | 9,000 |

| Proof | Result |
|---|---|
| Each cost moved **only its own family's** WIP (no cross-family posting) | PASS, all 9 |
| **Partial release:** invoice 40% (KES 22,272), fraction 0.400000 | PASS. Each family released exactly 40% of its own WIP to its own COS (e.g. 1211→5100 400.00, 1212→5200 800.00 … up to 3,600.00 for the KES 9,000 family). |
| **Idempotency:** releasing the same invoice again | PASS. No journal, no lines. |
| **No double release** | PASS. The repeat run creates nothing, and the second invoice releases only the remaining 60%. |
| **Full release:** invoice the remaining KES 33,408 | PASS. Every family has WIP 0 and COS equal to its full cost. |
| **Void/reversal:** void the second invoice | PASS. Its release reverses, and every family is back to the 40% position. The first invoice's release is untouched. |
| No cross-family release | PASS. The profile refuses a family whose WIP is shared with another family. A family whose WIP and COS are the same account is skipped rather than posting a self-journal. |

The W1 issue also produced exactly one invoice journal plus one "Cost of sales released" journal, because that job was already carrying WIP (§24).

---

## 6. M-Pesa

**KEEP MPESA UNLINKED — WNG DECISION REQUIRED** (MIG-P1).

Evidence reviewed in the source copy:
- **63 of 160** client receipts were received by M-Pesa.
- **70** FIN-005 "M-Pesa fee" lines were paid.
- **Every** petty-cash disbursement was recorded as `cash`.
- Petty-cash top-ups came from NCBA, Equity and KCB.
- No chart account names an M-Pesa balance.

This shows M-Pesa is used as a **channel**. It does not show whether WNG holds a till/paybill balance (which needs its own asset account) or whether M-Pesa settles straight into a bank (which needs the source to point at that bank).

The evidence is not conclusive, so no account was invented and no bank was assumed. The MPESA payment source exists, is unlinked, and is **disabled** until WNG decides. That keeps the paying-account picker from offering an account that cannot post.

**Impact, proven in the smoke (§24):** while M-Pesa is disabled, an M-Pesa client receipt is refused (422). Because 39% of historical receipts came by M-Pesa, this decision is a **cutover blocker** (§37). It is not a technical defect.

---

## 7. Company Card

**KEEP CARD UNLINKED — WNG DECISION REQUIRED** (MIG-P2).

There is no evidence of a company card anywhere in the source:
- no card account in the chart;
- no card-paid transactions;
- the only "Visa" matches are the client *VISA CEMEA*.

Card was **not** mapped to Equity or to any other bank. The CARD source exists, is unlinked, and is **disabled** until WNG says whether a card exists and which account settles it.

---

## 8. Loans Payable

**Loans Payable = NOT CONFIGURED — NO CURRENT EVIDENCE** (MIG-P3).

- Catalogue reference 2300 has `account: null`, with that note, in `wng-chart-profile.json`.
- The loan-repayment expense code stays inactive.
- `FinanceChartProfile::intentionallyUnconfigured()` lists it. Readiness reports "1 code(s) are off by design: the chart profile leaves 2300 unconfigured." It does **not** count this as a mapping gap.
- It blocks no other workflow. This is covered by the test `test_the_unconfigured_loan_code_is_reported_but_does_not_block_readiness`.

If WNG later takes a loan, the fix is one profile entry plus `finance:complete-chart`.

---

## 9. Existing Account Classification

**MIG-D3b, applied in the rehearsal only.** `finance:complete-chart --classify-existing` reads `existing_classification` from the profile.

**Safety rules the command enforces:**
- It fills `account_type` / `normal_balance` **only where NULL**.
- A differing non-null value is refused ("never overwritten").
- It never changes code, name, id, parent/hierarchy or balances.
- It runs in the same transaction as the rest of completion.

| Rule | Accounts | Result |
|---|---|---|
| COS-* and PE-007 (direct labour) | 20 | `direct_cost`, debit |
| COS-019 (not a job cost family) | 1 | `overhead`, debit |
| Genuine operating expense accounts | 75 | `opex`, debit |
| Assets / liabilities / equity by category | 19 | `balance_sheet`; debit for assets, credit for liabilities and equity (DIV-001 dividends: debit) |
| RI-001 Return Inwards | 1 | `revenue`, **credit**. It must stay credit-normal, because the P&L signs amounts by normal balance. |
| **Total classified** | **116** | |

**Left unclassified pending a decision (4), reported separately:**

| Account | Why |
|---|---|
| OPE-026 Set Up Casuals | Reviewed separately as instructed. It could be project setup labour (direct) or general casual labour (opex). No history line uses it, so there is no evidence either way. Never auto-classified as direct. |
| ITX-001 Income tax expense | A below-operating-profit tax charge. None of direct_cost / overhead / opex is correct, and the ERP has no tax section. |
| LDO-001 Loss on discontinued operations | Below the line, same reason as ITX-001. |
| EQE-001 Equity in earnings of subsidiaries | Recorded as EQUITY, but normally an income-statement item. The category itself is ambiguous. |

Until decided, these four appear under `unclassified` on the P&L. Nothing posting to them is refused.

Chart after classification:
- 152 accounts;
- 148 postable (classified);
- 4 NULL-type (the four above);
- the 123 pre-existing accounts are identical to the source on every column except the filled NULLs.

---

## 10. Duplicate ERP Accounts

7150 and 7550 are ERP-seeded reference accounts that duplicate WNG's own accounts. In the rebuilt rehearsal they have:
- **0** journal lines;
- **0** expense codes pointing at them;
- **0** payment sources.

**Status: DEPRECATION CANDIDATE.** They were not deleted or deactivated. Retiring them is a post-cutover housekeeping decision for the accountant.

---

## 11. R-1 — Project Officer Records Labour

**Reconfirmed with a real Project Officer.** User #76 is the Project Officer of real enquiry #1478 (ENQ-09-2026-100). The user:
- recorded labour against the real budget line for a real employee;
- PO-verified it;
- was refused (**403**) on Finance verification;
- was refused on another officer's project (#1558), so the scope holds.

Finance verification by Accounts then created the actual CostLine. The line is analytical under W7-12: no journal, and it is linked to an Employee, never to technical labour.

---

## 12. R-2 — Petty Cash Custody

**R-2 REMAINS OPEN.** Custody was not granted to anyone.

Current permission matrix (target, after the permission migrations):

| Permission | Held by |
|---|---|
| `finance.petty_cash.manage_custody` | Super Admin only |
| `finance.petty_cash.review_cash_count` | Super Admin only |
| `finance.petty_cash.create_disbursement` / `edit_disbursement` | Super Admin, Admin, Manager, Accounts |
| `finance.petty_cash.create_top_up` | Super Admin, Admin, Accounts |

The smoke records custody as **BLOCKED:R-2**. The cash-count and handover steps were run as Super Admin only to prove the mechanism works.

**Options for WNG:**
1. **Grant the permission directly to the named custodian.** This is the narrowest option: one person, auditable.
2. **Create a dedicated "Petty Cash Custodian" role** holding `manage_custody` (plus disbursement if the custodian also pays out). This suits a custodian who changes.
3. **Grant it to Accounts.** This is simplest, but the people who approve and reconcile requisitions would also hold the cash, which is a **segregation-of-duties concern**. `review_cash_count` should then stay with someone outside Accounts.

---

## 13. R-3 — Finance Reports Access

**Reconfirmed through the API.** Accounts holds `finance.reports.view` and opened every `api/finance/reports/*` GET endpoint on the rebuilt target: `api/finance/reports/profit-and-loss` and `api/finance/reports/receivables-ageing` (the two report endpoints the backend exposes). Both returned 200.

---

## 14. Clean Rebuild

The rebuild is fully scripted and reproducible:

```
scripts/rehearsal/run-wng-rehearsal.sh --smoke
```

**Host side:**
1. Verify the snapshot SHA-256 and gzip integrity (refuse on mismatch).
2. Drop and recreate the three `wng_*` databases.
3. Restore the snapshot into pristine and rehearsal.
4. Pipe `wng-rehearsal-pipeline.sh` into the web container.

**Container side, in the rehearsal checkout:**
1. Config and evidence before Stage 1.
2. Stage 1.
3. Clean target migration chain.
4. Dry run (schema gate, orphan allow-list).
5. Import.
6. Permissions and mapped grants.
7. `finance:complete-chart`: dry run, then execute with `--classify-existing`, then a rerun, which must create 0.
8. Reference regeneration.
9. `--disable-unlinked-sources`. This must follow reference regeneration, which creates the payment sources on a fresh target.
10. Planned CostLines, twice (idempotency).
11. Reconciliation evidence on both connections.
12. Overtime chain.
13. File check.
14. Target readiness.

Final run: **PASS**: `run55-final4.log` and smoke JSON `rehearsal-smoke/20260928-224116.json`.

| Step | Result |
|---|---|
| Snapshot | SHA-256 verified; gzip OK |
| Import | VALIDATION PASS (229 tables, 0 mismatched, 0 orphans introduced, exclusions PASS) |
| Permissions | Already in sync |
| Chart | 37/37 (dry run and execute); 116 classified; pending EQE-001, ITX-001, LDO-001, OPE-026; catalogue references 14/15 (2300 by design); paying accounts linked 7/9 (MPESA, CARD by design); **rerun creates 0** |
| Unlinked sources | MPESA: disabled; CARD: disabled |
| Planned lines | 3,877 projected, then 3,877 again (idempotent; 0 retired, 0 adopted) |
| Overtime chain | PASS, no break |
| Target readiness | 607/607 migrations, 0 pending; queue tables present; only cron and `storage:link` outstanding (host items) |
| Smoke | `OK (1 test)`: 71 PASS / 0 FAIL / 2 BLOCKED / 4 INFO |

The run was repeated from scratch after each smoke correction. The smoke commits data, so it is never rerun on a used target.

**Smoke-harness corrections made on the way (test errors, not product defects):**
- the store-linked PO response is a list (`data.0.id`);
- voucher correction is `PUT`;
- W4 needed its own unbilled GRN accrual;
- posting must be by a **third** user (the requester and approver are refused, which is correct three-way segregation);
- payroll must choose employees with a non-zero salary;
- a column name.

W1 client receipts and W2 WHT were **added** to the smoke in this report so that every part of the W1/W2 scope is exercised.

---

## 15. Migration Execution

| Stage | Result |
|---|---|
| Schema gate | PASS: 121 classified drifts accepted (MIG-S1). |
| Orphan scan | 24 inherited broken relationships, all on the explicit allow-list (MIG-O1). 0 introduced. |
| Import | VALIDATION PASS: 229 tables reconciled, mismatched none, orphans introduced 0, exclusions PASS (381 s). Permissions sync: already in sync. |
| D2 | **CLOSED.** Source POs/GRNs/bills: 0 historical rows imported. W2 used new rehearsal documents only. |
| Finance history exclusions (DATA-1) | PASS (§20). |

---

## 16. Source/Target Reconciliation

Source (`wng_source_rehearsal`) and target (`wng_target_rehearsal`) are identical, with id ranges preserved:

| Table | Rows | Ids |
|---|---|---|
| projects | 737 | identical range |
| project_enquiries | 1,387 | identical |
| clients | 258 | identical |
| project budgets | 658 | identical |
| employees | 93 | identical |
| users | 65 | identical |
| enquiry_tasks | 17,009 | identical |
| quote_approvals | 393 | identical |

Content checks:
- **63** project, **41** employee, **12** budget-authority and **18** W7 figures compared field by field: **0 differences**.
- **95/95** role assignments identical.
- Import validation: **229 tables** reconciled, **0** mismatched, **0** orphans introduced.

---

## 17. Projects

- All 737 projects and 1,387 enquiries are preserved with their ids, statuses, officers, budgets and quote approvals (MIG-D5).
- Planned CostLines were regenerated for open projects only: **3,877** lines. The second projection created no duplicates.
- A budget save during the smoke re-projected the job with **no duplicates** (Q R1).

---

## 18. Employees

- 93/93 employees are identical on id, department and manager.
- 61 users are linked to employees.
- Salary figures were compared by equality only and are **never printed**.

**Finding (MIG-H1, WNG HR data):** the source holds almost no payroll master data for the current month:
- only 1 active employee has a non-zero base salary;
- 22 of 74 active employees have a salary history valid for 2026-09, and most of those histories are 0.

A real payroll run can therefore pay only those employees. The migration is not at fault: it copies the values exactly. HR must enter current salaries before the first live payroll.

---

## 19. Authentication

- 65/65 users are identical on id, password hash (compared by equality, never printed) and employee link.
- The smoke logged in as real users and exercised role-scoped API access:
  - Project Officer #76;
  - an Accounts user;
  - two Super Admins for maker/checker.

---

## 20. Finance History Exclusions

The rebuilt target holds **no** legacy Finance history (DATA-1):

| Table | Source | Target |
|---|---|---|
| enquiry_payments | 160 | 0 |
| petty_cash_ledger_entries | 1,628 | 0 |
| payroll_runs | 1 | 0 |
| salary_advance_requests | 1 | 0 |
| journal entries | — | 0 before smoke |
| non-planned cost lines | — | 0 before smoke |
| payments / client_receipts | — | 0 |

---

## 21. Chart of Accounts

- **152 accounts:**
  - 123 pre-existing;
  - 29 created in Report 54's completion, recreated identically by the clean rebuild.
- **37/37** posting functions resolve.
- A **rerun creates 0**.
- 116 existing accounts classified (§9) and 4 pending.
- 7150/7550 are deprecation candidates (§10).

---

## 22. Expense Codes

- **109** expense codes.
- Codes are activated only when they resolve a postable account.
- The loan-repayment code is inactive by design (§8).
- Readiness counts no configuration gap.

**102 active.** The 7 inactive codes are all non-expense codes (NE-*) whose account varies with each transaction, so they carry no fixed default account by design:
- NE-002 transfer between accounts;
- NE-004 staff advance retired;
- NE-015 tax paid;
- NE-016 loan principal repayment (2300, §8);
- NE-017 drawings/dividend;
- NE-020 asset purchase pending review;
- NE-021 hire asset.

Readiness `expense_codes` and `expense_code_mapping`: OK.

---

## 23. Payment Sources

| Source | State |
|---|---|
| PC-MAIN (PETTY-001), BANK-MAIN Equity (EQB-001), BANK-ALT NCBA (NCBA-001) | Linked, active. |
| BANK-KCB (KCB-001), BANK-STANBIC (STB-001), BANK-FAMILY (FMB-001) | Linked to their own accounts, never to Equity. **Seeded inactive** by design ("availability is Finance's to confirm"). The source shows petty-cash top-ups from **KCB**, so Finance should activate BANK-KCB, and Stanbic/Family only if used (§36). |
| MPESA | Unlinked, **disabled** (MIG-P1). |
| CARD | Unlinked, **disabled** (MIG-P2). |
| AP (AP-001) | Supplier credit. Never offered as a paying account; still guarded at every layer. |

**Rules for disabling:**
- A source is disabled only if it exists, has no GL account, is active, and no referencing table uses it.
- A linked or in-use source is left active and reported.
- The step creates nothing.

---

## 24–30. W1–W7 Results

Final clean run, `run55-final.log`:

| W | Scope (WNG label) | Steps | Result |
|---|---|---|---|
| W1 | Invoicing / receivables / receipts | 6 PASS, 1 BLOCKED | **PASS** for invoicing and bank receipts. **BLOCKED BY WNG DECISION (MIG-P1)** for M-Pesa receipts. |
| W2 | Procurement / GRN / bill / AP / WHT | 9 PASS | **PASS** |
| W3 | Petty cash (requisitions) | 5 PASS (+2 queue) | **PASS** |
| W4 | Spend vouchers / accruals | 5 PASS | **PASS** |
| W5 | Inventory / Stores, and the petty-cash float | 7 PASS (+2 queue), 1 BLOCKED | **PASS** for Stores and float operations. **BLOCKED BY WNG DECISION (R-2)** for naming the custodian. |
| W6 | Payroll posting and project costing/close | 10 PASS | **PASS** (mechanism). Payroll master data is a WNG HR input (MIG-H1). |
| W7 | Labour | 7 PASS | **PASS** |
| WIP | Release proof | 13 PASS | **PASS** |
| Q | Queue drains R1–R6 | 7 PASS | **PASS**, 0 failed jobs |

No step is rated **FAIL — DEFECT**.

### 24. W1 — Invoicing / Receivables / Receipts
Real client project: enquiry #1478 (ENQ-09-2026-100).
- Accounts drafts an invoice.
- **The preparer cannot check their own invoice.** Super Admin B checks it.
- Issue posts **exactly one** invoice journal (#10), plus one "Cost of sales released" journal, because the job carried WIP (§5).
- **Receipt:** Accounts records a KES 1,000 bank transfer into BANK-MAIN. Nothing reaches the ledger until **independent verification** by Super Admin B. That posts **one** journal: `d:EQB-001 1,000.00 / c:CD-001 1,000.00`. Money received before it is earned is a client-deposit liability, not revenue.
- **M-Pesa receipt:** refused with **422**, because MPESA is disabled until linked. **BLOCKED:MIG-P1.** This matters operationally: 63 of 160 historical receipts (39%) arrived by M-Pesa. Until WNG decides, M-Pesa money can only be recorded as a bank transfer into the account it settles to.

**Rating:** PASS / BLOCKED BY WNG DECISION (M-Pesa).

### 25. W2 — Procurement / GRN / Bill / AP / WHT
D2 stays closed, so only new rehearsal documents were created.
1. Requisition (Super Admin A).
2. Approval (Super Admin B).
3. PO: commitment recorded by the queue.
4. GRN: accrual by the queue.
5. Stores confirms the line into stock.
6. Supplier bill of KES 10,000 **with WHT 200**.
7. Verification (Super Admin B) posts the bill journal: `d:AE-001 8,620.68, d:VAT-002 1,379.32, c:WHT-001 200.00, c:AP-001 9,800.00`. Input VAT is recoverable, withholding is retained for KRA, and the supplier is owed the net.
8. The supplier payment of **KES 9,800** (invoice less WHT) settles the balance.

**Rating:** PASS.

### 26. W3 — Petty Cash
- The project's Project Officer raises a requisition.
- Accounts approves it, and the queue records the commitment.
- The requester cannot edit an approved requisition.
- Accounts edits it, which returns it to pending and releases the commitment through the queue.
- It is then re-approved, disbursed, surrendered and reconciled: status `surrendered`, with 1 actual project cost line.

**Rating:** PASS.

### 27. W4 — Spend Vouchers / Accruals
- An unbilled goods receipt (requisition → PO #2 → GRN) creates a payable accrual.
- Accounts creates a payment voucher against it.
- Super Admin B returns it; Accounts corrects it (`PUT`) and resubmits.
- Super Admin B approves it.
- **Super Admin A posts it.** The requester and approver are both refused posting, which is three-way segregation.

**Rating:** PASS.

### 28. W5 — Inventory / Stores
**Stores:**
- The required material is received into stock: inventory asset IA-001 debit.
- It is issued to the real project against its approved requirement: materials WIP on the job 2,455.97 → 3,205.97.

**Petty-cash float:**
- rehearsal top-up (the real opening float is MIG-D7);
- direct disbursement: the queue records the actual cost line;
- void: the queue reverses it;
- cash count with variance (no journal);
- custody handover confirmed by the incoming holder.

The custody steps ran as Super Admin only to prove the mechanism. The custodian is **BLOCKED:R-2**.

**Rating:** PASS / BLOCKED BY WNG DECISION (R-2).

### 29. W6 — Payroll Posting
**Payroll:**
- A run for 2026-09 is created and processed by Super Admin A.
- It is **locked by another user**, which posts a balanced accrual: `d:PE-006 / c:2160 / c:PL-002 / c:PL-003`.
- It is **marked paid by a third user**, which posts `d:2160 / c:EQB-001` (balanced).

**Project costing:**
- regenerated planned lines are present;
- budget-labour, closure-check and labour-actuals endpoints open for Accounts;
- financial close works; recording labour on a closed project is refused; reopen works with a reason.

**Findings (WNG inputs, not defects):**
- **MIG-H1:** only 1 of 74 active employees has a non-zero salary for 2026-09 in the source, so a real run pays 1 person. The source data is identical in the pristine copy. HR must load current salaries.
- **MIG-H2 / D6:** no department is labour-classified yet, so all gross pay books to PE-006 (office salaries) and none to PE-007 (direct labour).

Salary values are not printed anywhere.

**Rating:** PASS (mechanism); master data outstanding.

### 30. W7 — Labour
Real Project Officer #76 on their own project:
- records labour for a real employee and PO-verifies it;
- **cannot Finance-verify (403)**;
- is refused on another officer's project.

Accounts Finance-verifies it, which creates an actual CostLine. The line is analytical (W7-12): no journal, and linked to an Employee.

**Rating:** PASS.

---

## 31. Queue

| Check | Result |
|---|---|
| R1: a budget save queues re-projection | PASS; planned lines unchanged at 15, no duplicates |
| R2: PO commitment | PASS |
| R3: GRN accrual | PASS |
| R4: petty-cash commitment | PASS |
| R5: petty-cash actual cost | PASS |
| R6a / R6b: commitment release / cost reversal | PASS |
| `failed_jobs` after the run | **0** |

After the run, 20 notification jobs (mail/push) are still queued. The final steps queued them after the last drain. They cannot reach anyone from the rehearsal: `MAIL_MAILER=log`, and push no-ops without OneSignal credentials, which are absent.

The rehearsal drained its own `database` queue with a bounded worker (`--stop-when-empty`) after each step. **No production worker was started.**

Before go-live, the host needs a supervised `queue:work` and the scheduler cron. `migration:target-readiness` flags cron and `storage:link` as the only remaining host items.

---

## 32. Backend Regression

- **Full suite on the standard harness (`db_test`): 1,512 passed, 0 failed** (10,450 assertions, 857 s). This includes the 5 W7 concurrency tests, which only run on `db_test`.
- Written after the full run and run separately: `ReceivablesPostingTest` **18/18**, including the new disabled-source test.
- Chart, integrity and mapping suites: 39/39.
- ENG-1 (frontend type baseline): unchanged, see §33.

**New tests in this report:**
- `test_classification_fills_only_null_columns_and_touches_nothing_else`
- `test_an_existing_classification_is_never_overwritten`
- `test_unlinked_unused_paying_accounts_are_disabled_not_given_an_invented_account`
- `test_the_unconfigured_loan_code_is_reported_but_does_not_block_readiness`
- `test_analytical_w7_labour_lines_are_not_reported_as_missing_journals`
- `test_a_receipt_into_a_disabled_paying_account_is_refused_with_a_reason`

---

## 33. Frontend Regression

The frontend was validated against the backend's real route table (`routes55.json`). Every Finance-related API call in the frontend source was matched to a live route and method:

| Area | Calls | Matched |
|---|---|---|
| W1 receivables | 35 | 35 |
| W2 procurement | 81 | 81 |
| W4 vouchers/payments | 12 | 12 |
| W5 petty cash + Stores | 47 | 46 (see §34) |
| W3/W5 `pettyCashService` | 20 | 20 |
| W6 payroll + project costing | 3 | 3 |
| W7 labour | 10 | 10 |
| Finance setup/reports | 7 | 7 |

- **Unit tests:** 169/169 passed.
- **Type check:** 256 errors, equal to the ENG-1 baseline, so **zero new** (0 in the changed file).
- **Production build:** 1,891 modules, exit 0.

---

## 34. Frontend Usability Findings

These are recorded separately and are not defects in the backend:

1. **Fixed, needs WNG to see it working.** On the petty-cash requisition list, the Approve, Disburse and Reconcile quick actions were gated on permissions that do not exist: `finance.petty_cash.approve` and `finance.petty_cash.disburse`. They were **hidden from everyone**, so users had to open each requisition. The gates now match the backend policies:
   - Approve and Reconcile use `edit_disbursement`;
   - Disburse uses `create_disbursement`.
2. **Fixed (backend).** Recording a client receipt into a disabled paying account returned a generic "Failed to log payment" with **HTTP 500**. It is now a 422 field error on the receiving account. The receipt form already shows the API message and lists only active accounts. With MPESA disabled, choosing the "M-Pesa" method therefore offers no receiving account. That is the intended MIG-P1 behaviour, but users will need to be told why.
3. **Dead code.** `usePettyCash.ts:141` `updateDisbursement` calls `PUT /api/finance/petty-cash/disbursements/{id}`, which has no route. No screen calls it. This is a cleanup item and is non-blocking.
4. **Carried into the redesign:** Finance screens are functional but fragmented across modules. This is the justification for the Finance frontend redesign stream (§40), which was **not started**.

---

## 35. Remaining Accountant Decisions

| Id | Decision |
|---|---|
| MIG-D3a | Sign off the WIP policy (`capitalise` is the working configuration) for production. |
| MIG-D3b | Confirm the 116 classifications, and classify OPE-026, ITX-001, LDO-001 and EQE-001. |
| MIG-D7 | GL opening balances and the verified physical petty-cash float at cutover. |
| MIG-P3 | Confirm no loan liability exists (or name its account). |
| 7150/7550 | Deprecate after cutover (optional housekeeping). |

---

## 36. Remaining WNG Operational Decisions

| Id | Decision |
|---|---|
| R-2 | Name the petty-cash custodian and pick option 1, 2 or 3 (§12). |
| MIG-P1 | M-Pesa: an own balance (asset account) or a channel into a named bank. |
| MIG-P2 | Company card: does one exist, and which account settles it. |
| MIG-H1 | HR enters current salaries / salary histories before the first live payroll. |
| MIG-H2 / MIG-D6 | Classify the 13 departments direct/indirect. Until then, all payroll books to PE-006 and none to PE-007. |
| Banks | Activate BANK-KCB (in use per the source), and Stanbic/Family only if WNG banks with them. |
| MIG-D8 | Cutover date and window. |

---

## 37. Production Blockers

These must be resolved **before** cutover:

These are business inputs, not technical defects:

| # | Blocker | Owner | Why it blocks |
|---|---|---|---|
| 1 | **WIP policy production sign-off** (MIG-D3a) | Accountant | Determines when job cost reaches the P&L. The working configuration is not an approved policy. |
| 2 | **Opening balances and the verified physical petty-cash float** (MIG-D7) | Accountant / Finance | The ledger starts from them. None exist anywhere yet. |
| 3 | **Payroll master data** (MIG-H1) | WNG HR | The first live payroll would pay 1 of 74 active staff. |
| 4 | **M-Pesa decision** (MIG-P1) | WNG Finance | 39% of client receipts arrive by M-Pesa, and they cannot be recorded as M-Pesa until the account is linked. |
| 5 | **Petty-cash custodian** (R-2) | WNG | Only Super Admin can hold custody. Someone must be named before the float is loaded. |
| 6 | **Host items**: supervised queue worker cron, scheduler, `storage:link`, production mail/push configuration | Engineering at cutover | Queue-driven Finance steps do not run without the worker. |
| 7 | **Cutover date/window** (MIG-D8) | WNG Management | Deferred by design. |

**Technical production blockers: none.**

---

## 38. Non-Blocking Follow-Ups

These can be configured after go-live:
- Company Card linkage (MIG-P2). It stays disabled, and no workflow depends on it. M-Pesa is **not** in this list: it blocks M-Pesa receipts (§37).
- Loans payable (MIG-P3). Off by design until a loan exists.
- The 4 pending account classifications. They report as "unclassified" on the P&L until decided, and nothing is refused.
- Deprecating 7150/7550.
- Removing the dead `updateDisbursement` frontend call.
- The Finance frontend redesign.

---

## 39. Cutover Readiness Matrix

| Area | Status | Evidence | Blocks Cutover? |
|---|---|---|---|
| Snapshot → rehearsal rebuild (reproducible) | PASS | `run-wng-rehearsal.sh`; checksum verified | No |
| Source/target reconciliation, ids | PASS | 229 tables, 0 mismatched, 0 orphans introduced | No |
| Projects / enquiries / budgets / clients | PASS | identical counts and ids; 0 differences in field checks | No |
| Employees / users / roles / authentication | PASS | 93/93, 65/65, 95/95; hashes compared, not printed | No |
| Finance history exclusions (DATA-1) | PASS | 0 legacy Finance rows in target | No |
| Chart of accounts (152, 37/37, rerun 0) | PASS | chart report | No |
| Existing-account classification | 116 applied; 4 pending | profile + command | No (shown as unclassified) |
| Expense codes (109) | PASS | readiness OK | No |
| Payment sources | Linked 7/9; MPESA/CARD disabled | disable step | No for CARD; **yes for M-Pesa** (receipts) |
| KCB / Stanbic / Family banks | Linked but seeded inactive | payment_sources | No (Finance activates the ones in use; KCB is in use) |
| WIP working policy | CAPITALISE (working) | §4, §5 | **Yes, until accountant sign-off** |
| WIP release | PASS | 13 steps on a real job | No |
| W1 invoicing / receipts | PASS / BLOCKED (M-Pesa) | smoke | M-Pesa only |
| W2 procurement / AP / WHT | PASS | smoke | No |
| W3 petty cash | PASS | smoke | No |
| W4 vouchers / accruals | PASS | smoke | No |
| W5 Stores / float | PASS / BLOCKED (R-2) | smoke | **Yes (custodian)** |
| W6 payroll posting | PASS (mechanism) | smoke | **Yes (HR master data)** |
| W6 department classification (D6) | OPEN | all to PE-006 | No (set at cutover in the UI) |
| W7 labour, R-1 | PASS | smoke | No |
| R-3 reports access | PASS | smoke | No |
| Queue (R1–R6) | PASS, 0 failed | smoke | Worker cron is a host item: **Yes** |
| Finance readiness API | `ready=true`, integrity 0 | smoke | No |
| Backend regression | 1,512/1,512 | `regress55-final.log` | No |
| Frontend regression (ENG-1) | 169/169; 0 new TS errors; build OK | `fe55-*` | No |
| Opening balances / float | NOT STARTED (by rule) | — | **Yes** |
| Cutover date | DEFERRED | MIG-D8 | **Yes** |

---

## 40. Exact Next Action

**Take the decision list to WNG and the accountant, in one sitting:**
- MIG-D3a WIP sign-off;
- MIG-D3b's 4 accounts;
- MIG-D7 opening balances and float;
- MIG-P1 M-Pesa;
- MIG-P2 Card;
- R-2 custodian;
- MIG-H1 payroll master data;
- MIG-H2 department classification;
- activate BANK-KCB (and Stanbic/Family if used).

Record each answer in `03_WNG_FINANCE_DECISION_REGISTER.md`. Then re-run `scripts/rehearsal/run-wng-rehearsal.sh --smoke` against the answers. The two BLOCKED rows must turn PASS before a cutover date is set.

**The next engineering stream, now that Phase 2B passes technically, is the full Finance frontend redesign.** It is named here and **not started**.

---

## 41. Final Verdict

**PARTIAL — PHASE 2B TECHNICALLY READY WITH BUSINESS DECISIONS OUTSTANDING**

Technically, the migration, the chart, the WIP release and every W1–W7 workflow pass on real WNG data, with 0 failures and a green regression. What remains are named accountant and WNG decisions (§35–37), none of which needs a code change.

Phase 2B is **not** cut over. Nothing in this report touched production, and cutover remains deferred (MIG-D8).
