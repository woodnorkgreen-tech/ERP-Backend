# 44 — Phase 2B Controlled Release Readiness Gate

**Date:** 2026-09-28
**Type:** Readiness audit and release plan. **No deployment, no merge to `master`, no production migration, no production data access, no W8.**
**Branches audited:** `finance/critical-stabilization-fixes` in `ERP-Backend` (tip `c857187`) and `ERP-Frontend` (tip `5c0c65f`), both compared with `origin/master` (fetched 2026-09-28).
**Verdict:** **CONDITIONAL PASS — RELEASE AFTER LISTED PRECONDITIONS** (§29).

---

## 1. Release Scope

Both feature branches are strict fast-forwards of `origin/master`: 0 commits behind, so a merge introduces nothing unexpected.

| Repo | Commits over master | Diff |
|---|---|---|
| Backend | 11 (5 feature, 1 wiring, 5 docs) | 222 files, +31,746 / −644 |
| Frontend | 6 | 46 files, +5,951 / −162 |

**Backend release scope:**
- 23 migrations (§3).
- Finance services and controllers for STAB, W1–W7.
- Procurement workflow (PO guard, amendments, correction, senior approval, staged billing, duplicates).
- Petty cash controls.
- HR salary-advance payout and recovery.
- W6 costing services.
- W7 labour.
- 19 new permission constants.
- 1 console command (`petty-cash:clear-all-non-production`, which refuses to run in production).
- `FinanceSettingsSeeder` additions (all `null`).
- Route additions; the HR Technical Labour routes are commented out.
- `config/notifications.php` (new notification types).

There are **no** scheduler/cron changes and **no** new queued jobs. Postings stay synchronous as on master.

**Frontend release scope:**
- 46 files: finance cost-collector (W6/W7 panels, modals, types, composable), receivables modal, petty cash, payment vouchers, procurement PO/bill screens and panels, HR advances/payroll/offboarding views, the TeamsTask/ReportTask pickers, and 7 spec files.
- **No route or navigation changes.** Everything lands inside existing screens.

## 2. Released Finance Functionality

| Area | Backend | Frontend | Migrations | Permissions | Accounting impact | User-visible impact |
|---|---|---|---|---|---|---|
| STAB (1, 3–7) | `ChartAccountMap` routing; PO edit guard; GL-failure flags + retry; clear-all removed; payroll → Payment; petty-cash single posting | Retry/flag display | 09_22 ×3 | none new | STAB-7 removes duplicate petty-cash cost recognition going forward; payroll now creates a Payment | Approved POs can no longer be edited directly; GL failures visible |
| W1 Receivables | Check/return/resubmit, quote exception, discounts, payment terms, financial position, attachments | `EnquiryFinanceModal`, receivables | 09_23_1–4 | `finance.receivables.invoice_check` | Invoice net unchanged; discount explicit | **Issue now requires a prior check (§9, B1)** |
| W2 Procurement / AP | Senior approval (inactive), evidence, staged billing, amendments, duplicate detection, PO return | PO/Bill screens, panels | PS 09_22_1, 09_23_1–5 | `procurement.orders.approve_senior`, `.amend`, `procurement.bills.override_duplicate` | Commitment re-posting on amendment; cumulative 3-way match | New buttons; duplicates warned/blocked |
| W3 Expenses | Cash-purchase class, advance payout (Payment only, no journal), duplicate receipts, surrender return/reversal, advance recovery | Petty cash, MyAdvances, Payroll, Offboarding | 09_23_6 | `finance.expenses.override_duplicate` | Surrender reversal posts compensating entries | New return/reversal actions |
| W4 Payment Vouchers | Return/correct/resubmit/reject, senior approval (inactive) | `PaymentVouchersView` | 09_23_6 | `finance.spend_vouchers.approve_senior` | None new | New review actions |
| W5 Petty Cash | Custody, cash counts, thresholds (legacy defaults), surrender ageing, advance control | `PettyCashControlsPanel`, forms | 09_23_7 | `finance.petty_cash.manage_custody`, `.review_cash_count`, `.advance_exception` | Counts post nothing (variance GL open) | New controls panel |
| W6 Project Costing | Direct margin + completeness, portfolio margin, allocation, transfer, financial closure/reopen | `CostAccountsView`, `CostAccountPanel`, modals | 09_24_1–4 | `finance.costs.portfolio`, `.allocate`, `.transfer`, `.close`, `.reopen` | Allocation/transfer are analytical CostLines; closure blocks new costs | Margin column, allocate/transfer/close actions |
| W7 Labour | Labour actuals lifecycle, analytical CostLines, corrections via W6-4 | `ProjectLabourPanel`; crew pickers on Employee Records | 09_24_5–7, 09_28 | `finance.labour.*` (5) | Analytical only (`postsIndependently=false`); no journals | Labour panel in project cost sheet |

## 3. Migration Inventory (23)

"PS" means `app/Modules/ProcurementStores/Database/Migrations`; all others are in `database/migrations`. All are dated after master's newest migration (`2026_09_21_000003`), so they run strictly after existing ones.

| # | Migration | Workflow | Tables / columns | Data mutation? | Destructive? | Lock risk | Rollback (`down`) | Risk |
|---|---|---|---|---|---|---|---|---|
| 1 | PS `2026_09_22_000001_restrict_purchase_order_cascade_deletes` | STAB-3 | Drops and re-creates the FK on `purchase_order_items`, `goods_receipt_notes`, `bills` (`purchase_order_id`) as RESTRICT | No | No (FK rule only) | Low–Med (FK rebuild on 3 tables) | Yes (back to CASCADE) | **MEDIUM** |
| 2 | PS `2026_09_23_000001_add_return_for_correction_to_purchase_orders` | W2-6 | `purchase_orders` + 4 nullable cols, FK→users | No | No | Low | Yes | LOW |
| 3 | PS `2026_09_23_000002_add_senior_approval_to_purchase_orders` | W2-1 | `purchase_orders` + 3 cols (bool default false), FK | No | No | Low | Yes | LOW |
| 4 | PS `2026_09_23_000003_add_duplicate_detection_to_bills_and_bill_payments` | W2-5 | `bills`, `bill_payments` + 4 nullable cols each, self/user FKs | No | No | Low | Yes | LOW |
| 5 | PS `2026_09_23_000004_create_purchase_order_amendments_table` | W2-4 | New table; UNIQUE(po, number); FK RESTRICT | No | No | None | Yes (drop) | LOW |
| 6 | PS `2026_09_23_000005_create_purchase_order_corrections_table` | W2-6 | New table; UNIQUE(po, number) | No | No | None | Yes | LOW |
| 7 | `2026_09_22_000002_add_advance_gl_posting_status_to_petty_cash_requisitions` | STAB-4 | + 2 nullable cols | No | No | Low | Yes | LOW |
| 8 | `2026_09_22_000003_add_payment_id_to_payroll_runs` | STAB-6 | `payroll_runs.payment_id` nullable FK→payments | No | No | Low | Yes | LOW |
| 9 | `2026_09_23_000001_create_payment_terms_table` | W1-7 | New table | No | No | None | Yes | LOW |
| 10 | `2026_09_23_000002_create_finance_attachments_table` | W2-2 | New table | No | No | None | Yes | LOW |
| 11 | `2026_09_23_000003_add_review_workflow_to_project_invoices` | W1-1/2/7 | `project_invoices` + 12 nullable cols (5 FKs) | No | No | Low–Med (many ALTERs on one table) | Yes | LOW |
| 12 | `2026_09_23_000004_add_discount_to_project_invoice_lines` | W1-8 | + `gross_amount`, `discount_amount` | **Yes:** sets `gross_amount = net_amount`, `discount_amount = 0` on every existing line | No | Med (full-table UPDATE) | Yes (drops cols) | **MEDIUM** |
| 13 | `2026_09_23_000005_add_cost_gl_posting_status_to_payments` | STAB-4 | `payments` + 2 nullable cols | No | No | Low | Yes | LOW |
| 14 | `2026_09_23_000006_add_wave_3_expense_controls` | W3/W4/W5 | `spend_vouchers` + 11 cols (`review_state` default `submitted`); **backfill** of `review_state` from `status`; new `spend_voucher_reviews`; **ENUM `MODIFY`** of `petty_cash_requisitions.status` (adds `surrender_returned`); `payments.transaction_classification`; `petty_cash_requisitions` + 11 cols; new `petty_cash_surrender_reviews`; `petty_cash_surrender_items` + 6 cols + self-FK; `salary_advance_requests` + 4 cols + FK; `payroll_ledgers` + FK; new `salary_advance_recoveries` (UNIQUE) | **Yes** (`review_state` backfill) | No | Med (ENUM rewrite + ~9 ALTERs) | Yes | **HIGH** |
| 15 | `2026_09_23_000007_create_petty_cash_control_records` | W5-3/7 | `petty_cash_balances.held_by`; 2 new tables with FK→`petty_cash_balances` (default id 1) | No | No | Low | Yes | LOW |
| 16 | `2026_09_24_000001_create_cost_line_allocations_table` | W6-3 | New table; UNIQUE(line, project) | No | No | None | Yes | LOW |
| 17 | `2026_09_24_000002_create_cost_line_transfers_table` | W6-4 | New table | No | No | None | Yes | LOW |
| 18 | `2026_09_24_000003_add_financial_closure_to_project_enquiries` | W6-5 | `project_enquiries` + 6 cols (`financial_closure_status` default `open`, indexed) | No (default fills) | No | Low–Med | Yes | LOW |
| 19 | `2026_09_24_000004_add_w6_project_costing_permissions` | W6 | Creates 5 permissions; grants 4 to `Accounts` | Yes (permission rows) | No | None | Yes | LOW |
| 20 | `2026_09_24_000005_create_project_labour_actuals_table` | W7 | New table | No | No | None | Yes | LOW |
| 21 | `2026_09_24_000006_add_w7_labour_cost_permissions` | W7 | Creates 5 permissions; grants to Accounts/Admin/Super Admin/PM/Operations/Costing (**`Operations` role does not exist, so that grant is skipped silently**) | Yes (permission rows) | No | None | Yes | LOW |
| 22 | `2026_09_24_000007_harden_project_labour_actuals` | W7 | + `budget_id` FK, rate columns, 2 UNIQUE (new table) | No | No | None | Yes | LOW |
| 23 | `2026_09_28_000001_w7_labour_remediation` | W7 | Actuals + 5 cols/FKs; new `project_labour_actual_returns`; `cost_line_transfers.transfer_type` + UNIQUE(`source_cost_line_id`) | No | Drops a plain index after adding the UNIQUE | None (tables from this release) | Yes (verified up → down → up) | LOW |

**Why #14 is HIGH:**
- **Size:** it is the largest migration, performing ~9 structural changes plus a backfill.
- **Not atomic:** MariaDB/MySQL DDL is not transactional. If any step fails part-way (for example the ENUM `MODIFY` meeting an unexpected value), the migration is left **half-applied and will not re-run cleanly**, because the columns already exist. The fix would be manual.
- **ENUM rewrite:** the `MODIFY` rebuilds the table and fails under strict mode if any existing status value is outside the list.
- **Mitigation:** preflight P3/P4 (§4), a backup, and a staging dry-run on production data.

**Why #1 and #12 are MEDIUM:**
- **#1** rebuilds foreign keys on three procurement tables and changes delete semantics. Deleting a PO that has items, GRNs or bills becomes impossible, which is intended (STAB-3).
- **#12** runs a full-table UPDATE. It is correct (no historical discounts existed), but it mutates every invoice line.

No migration is CRITICAL. None drops a column or table, renames anything, or adds NOT NULL without a default to an existing table.

## 4. Migration Safety Audit

| Check | Finding |
|---|---|
| DROP COLUMN / DROP TABLE / rename | None in `up()` |
| Destructive ALTER | ENUM `MODIFY` in #14. It is a **strict superset** of master's list (master: `…,'surrender_pending','surrendered'`, from `2026_09_12_000001`), so it is safe unless production was altered outside migrations (P3/P4) |
| Changed FKs / cascade | #1: CASCADE (or NO ACTION) → RESTRICT on 3 PO child FKs. The migration skips any FK that does not exist |
| New UNIQUE constraints | All on **new** tables (amendments, corrections, allocations, labour actuals ×2, returns, recoveries, transfers). No existing data can collide (§5) |
| NOT NULL additions | Only with defaults (`senior_approval_required`, `review_state`, `financial_closure_status`, `discount_amount`, counters), or on new tables |
| Default changes | None on existing columns |
| Indexes | New indexes on added columns; the plain transfers index is replaced by a UNIQUE (same release) |
| Backfills | #12 (invoice lines), #14 (`spend_vouchers.review_state`). Deterministic, from existing columns |
| Ordering dependencies | `payment_terms` (#9) before `project_invoices.payment_term_id` (#11); `payments` before #8/#14; `cost_line_transfers` (#17) before #23; labour table (#20) before #22/#23. Correct by timestamp |
| Large-table locking | Altered tables in dev are small (largest: `payments` ~1,560 rows). Production volumes are measured by P9. ALTERs with `->after()` may rebuild tables on older engines; P0 records the engine/version |
| Rollback assumptions | Every migration has a `down()`, but rolling back after use destroys workflow data (§24) |

**Read-only preflight (run on production before release; validated for syntax on dev 2026-09-28):**

```sql
-- P0 engine / version / strict mode
SELECT VERSION() AS db_version, @@sql_mode AS sql_mode;
-- P1 Phase 2B migrations already applied (expect 0)
SELECT COUNT(*) FROM migrations WHERE migration >= '2026_09_22';
-- P2 new-table name collisions (expect no rows)
SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
 ('payment_terms','finance_attachments','spend_voucher_reviews','petty_cash_surrender_reviews','salary_advance_recoveries',
  'petty_cash_cash_counts','petty_cash_custody_handovers','cost_line_allocations','cost_line_transfers','project_labour_actuals',
  'project_labour_actual_returns','purchase_order_amendments','purchase_order_corrections');
-- P3 petty cash statuses outside the new ENUM (expect no rows)
SELECT status, COUNT(*) FROM petty_cash_requisitions WHERE status NOT IN
 ('pending','approved','rejected','disbursed','received','surrender_pending','surrender_returned','surrendered') GROUP BY status;
-- P4 current definition (expect the master ENUM ending 'surrender_pending','surrendered')
SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='petty_cash_requisitions' AND COLUMN_NAME='status';
-- P5 FKs to be tightened (0–3 rows; missing ones are skipped by the migration)
SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k
 JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
 WHERE k.TABLE_SCHEMA = DATABASE() AND k.COLUMN_NAME = 'purchase_order_id' AND k.TABLE_NAME IN ('purchase_order_items','goods_receipt_notes','bills');
-- P6 orphans that would block re-creating those FKs (expect 0, 0, 0)
SELECT (SELECT COUNT(*) FROM purchase_order_items i LEFT JOIN purchase_orders p ON p.id=i.purchase_order_id WHERE i.purchase_order_id IS NOT NULL AND p.id IS NULL) AS orphan_po_items,
       (SELECT COUNT(*) FROM goods_receipt_notes g LEFT JOIN purchase_orders p ON p.id=g.purchase_order_id WHERE g.purchase_order_id IS NOT NULL AND p.id IS NULL) AS orphan_grns,
       (SELECT COUNT(*) FROM bills b LEFT JOIN purchase_orders p ON p.id=b.purchase_order_id WHERE b.purchase_order_id IS NOT NULL AND p.id IS NULL) AS orphan_bills;
-- P7 petty cash float rows (new cash-count/handover tables default to balance id 1)
SELECT id, current_balance FROM petty_cash_balances ORDER BY id;
-- P8 roles the permission migrations grant to
SELECT name FROM roles WHERE name IN ('Accounts','Admin','Super Admin','Project Manager','Operations','Costing');
-- P9 volumes of altered tables (lock sizing)
SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
 ('payments','project_enquiries','purchase_orders','bills','bill_payments','spend_vouchers','project_invoices','project_invoice_lines',
  'petty_cash_requisitions','petty_cash_surrender_items','payroll_runs','payroll_ledgers','salary_advance_requests') ORDER BY TABLE_ROWS DESC;
-- P10 open work touched by new gates
SELECT (SELECT COUNT(*) FROM project_invoices WHERE status='draft') AS draft_invoices_needing_check,
       (SELECT COUNT(*) FROM spend_vouchers WHERE status IN ('draft','pending_approval')) AS open_vouchers,
       (SELECT COUNT(*) FROM petty_cash_requisitions WHERE status IN ('disbursed','received','surrender_pending')) AS open_advances;
```

**Stop criteria:**
- P1 ≠ 0, P2 non-empty, P3 non-empty, or P6 ≠ 0 → **stop the release.**
- P7 has no row with id 1 → cash counts must pass the balance id explicitly (they do in code); record it.
- P0 is not MariaDB ≥ 10.4 / MySQL ≥ 8 → schedule the release for low traffic.

## 5. Unique-Constraint Collision Analysis

Every new UNIQUE constraint is on a table **created in this release**, so it starts empty:
- `purchase_order_amendments` (po, number)
- `purchase_order_corrections` (po, number)
- `cost_line_allocations` (line, project)
- `salary_advance_recoveries` (advance, run)
- `project_labour_actuals` (`cost_line_id`; `reversal_of_id`)
- `project_labour_actual_returns` (actual, cycle)
- `cost_line_transfers` (`source_cost_line_id`)

**No existing production row can violate them.** P2 guards against name collisions. No UNIQUE constraint is added to any existing table, and CostLine source keys are unchanged (idempotency remains application-level, as on master).

## 6. Foreign-Key Safety

- **New FKs on existing tables:** all sit on **new nullable columns** (`payroll_runs.payment_id`; `project_invoices.checked_by`/`returned_by`/exception approvers/`payment_term_id`; `purchase_orders.returned_by`/`senior_approved_by`; `bills` and `bill_payments` duplicate links; `salary_advance_requests.payment_id`; `payroll_ledgers.salary_advance_request_id`; `petty_cash_surrender_items.duplicate_of_*`). They start `NULL`, so there is **no orphan risk**.
- **Changed FKs:** #1 only; orphan check P6.
- **New tables:** FKs reference `users`, `purchase_orders`, `cost_lines`, `project_enquiries`, `employees`, `payments`, `petty_cash_balances` and `task_budget_data`, all existing parents. `petty_cash_cash_counts` and `petty_cash_custody_handovers` default to `petty_cash_balance_id = 1` (P7).
- **Delete behaviour:** RESTRICT on PO children, amendments and corrections. Users referenced as requesters/returners are RESTRICT, so deleting such a user will be refused, which is intended for audit.

## 7. Data Backfill Requirements

| Field / data | Classification | Note |
|---|---|---|
| `project_invoice_lines.gross_amount/discount_amount` | **NO BACKFILL REQUIRED** (done by #12) | gross = net, discount 0 |
| `spend_vouchers.review_state` | **NO BACKFILL REQUIRED** (done by #14) | Approved/paid/posted/reversed → `approved`; rejected → `rejected`; others keep the default `submitted` |
| Invoice review metadata (`checked_by/at`) on existing drafts | **NO BACKFILL — do not invent** | Existing drafts must be checked by a permission holder before issue (P10, B1). Issued invoices are unaffected |
| `payroll_runs.payment_id` for historical runs | **OPTIONAL BACKFILL** (STAB-6 decision pending) | Forward-only by decision |
| Historical labour actuals / CostLines | **NO BACKFILL** (W7-9 forward-only) | |
| `financial_closure_status` | **NO BACKFILL REQUIRED** | Defaults to `open` for every project |
| `petty_cash_balances.held_by` | **OPTIONAL, AFTER RELEASE** | Record the physical custodian through the new custody handover |
| `surrender_due_at` for open advances | **OPTIONAL, AFTER RELEASE** | Null means no overdue state (W5-8 policy value open) |
| STAB-7 historical double-recognised petty-cash cost | **REQUIRED AFTER RELEASE only if Finance decides to remediate** | Diagnostic in Report 15; not changed by this release |
| Permission rows and grants | **REQUIRED AT RELEASE** | `permissions:sync` (§9) |

## 8. Accounting Integrity Release Check

| Risk | Evidence it is not introduced |
|---|---|
| Duplicate GL postings | Every journal goes through `JournalPostingService` with source idempotency. Full suite `JournalPostingTest`, `LedgerCorrectionTest` pass |
| Duplicate CostLines | Cost Collector source-key idempotency; W7 Finance-verify race test (1 line from 4 racers) |
| Duplicate payment movements | `PaymentSettlementService` idempotency keys; `PaymentArchitectureTest`, `SettlementAccountTest` pass; W2-5 duplicate detection |
| Petty-cash expense recognition | STAB-7 fix; `Stab7PettyCashTriplePostingTest` (KES 2,000 spend → 2,000 recognised) |
| Labour GL duplication | W7 analytical only; ledger-delta test shows zero journals |
| Invoice double-posting | Issue is single-transition (draft → issued) under lock; `ReceivablesPostingTest` passes |
| Supplier payment duplication | `SupplierPaymentGateTest`, `DuplicateDetectionTest` pass |
| Payroll payment duplication | STAB-6 settles once per run (`PayrollIntegrityTest`) |

Backend full suite: **1,427 / 0 failures** (§17). The release does **not** correct historical STAB-7 exposure; that stays a Finance decision.

## 9. Permission Readiness

**Technical existence vs operational holders.** Ten permissions are created by migrations (W6, W7). The other nine exist only in `Permissions::all()` and are created by `php artisan permissions:sync`. That command is **not** in `deploy.yml`, so it must run by hand after migrations. The matrix (`RolePermissions::matrix()`) grants those nine to **Super Admin only**. A missing permission denies safely: `CheckPermission` uses `can()` and returns 403. Super Admin bypasses all checks.

| Permission | Workflow | Controls | Holder after release (per migrations/matrix) | If nobody holds it | If too many hold it |
|---|---|---|---|---|---|
| `finance.receivables.invoice_check` | W1-1 | Check / return an invoice; **issue requires a check** | Super Admin only | **Only Super Admin can make any invoice issuable. Client billing stops for Accounts (B1)** | Segregation weakened (the preparer still cannot check their own invoice) |
| `procurement.orders.amend` | W2-4 | Propose an amendment to an approved PO | Super Admin only | Approved POs cannot be changed except by Super Admin (direct edit is already blocked) | Uncontrolled amendments (still need approval) |
| `procurement.orders.approve_senior` | W2-1 | Senior PO approval | Super Admin only | Nothing while the threshold is null | — |
| `procurement.bills.override_duplicate` | W2-5 | Override a duplicate bill | Super Admin only | A genuine duplicate-looking bill needs Super Admin | Duplicate control weakened |
| `finance.spend_vouchers.approve_senior` | W4-2 | Senior voucher approval | Super Admin only | Nothing while the threshold is null | — |
| `finance.expenses.override_duplicate` | W3-5 | Override a duplicate receipt | Super Admin only | Duplicates stay blocked | Control weakened |
| `finance.petty_cash.manage_custody` | W5-3 | Custody handover | Super Admin only | Handovers only by Super Admin (new feature) | — |
| `finance.petty_cash.review_cash_count` | W5-7 | Independent count review | Super Admin only | Counts unreviewed (new feature) | — |
| `finance.petty_cash.advance_exception` | W5-9 | Approve despite an overdue advance | Super Admin only | Overdue-advance block has no exception path (dormant while due dates are null) | Control weakened |
| `finance.costs.portfolio`, `.allocate`, `.transfer`, `.close` | W6 | Portfolio margin, allocate, transfer, close | Accounts (+ Super Admin), via migration | — | — |
| `finance.costs.reopen` | W6-6 | Reopen a closed project | **None by design** (Super Admin bypass) | Late costs need Super Admin | — |
| `finance.labour.view/record/po_verify` | W7 | Labour capture and PO verify | Accounts (view/record), Admin, Costing, Project Manager, Super Admin | — | — |
| `finance.labour.finance_verify/correct` | W7 | Finance verify / correct | Accounts, Admin, Super Admin | — | — |

These holders are confirmed on dev after `permissions:sync`. Production holders are **not verified** here (no production access), so check with P8 and a post-sync query. **No role assignment is invented here.** Assigning real holders is WNG's decision (ROLE-1).

## 10. Unresolved Role Decisions

| Decision | Classification | Why |
|---|---|---|
| **ROLE-1** (which real positions hold each function) | **RELEASE BLOCKER, for one permission only** (`finance.receivables.invoice_check`), otherwise GO-LIVE CONFIGURATION REQUIRED | Without a named checker holder, invoice issuing stops for everyone but Super Admin. The other new controls are dormant or have safe fallbacks |
| **ROLE-2** (clerk/lead split of Accounts) | **CAN FOLLOW AFTER RELEASE** | Current grants mirror today's single Accounts role |
| **ROLE-3** (exception / self-approval override holders) | **GO-LIVE CONFIGURATION REQUIRED** | The override and exception permissions default to Super Admin, so controls fail closed. Name holders before the relevant thresholds and due dates are activated |

## 11. Configuration Readiness

| Setting | State | Effect of absence |
|---|---|---|
| `purchase_order_senior_approval_threshold` (W2-1) | No value (seeded null) — decision pending | Gate inactive; approvals as today |
| `spend_voucher_senior_approval_threshold` (W4-2) | No value — decision pending | Gate inactive |
| `petty_cash_low/critical_balance_threshold` (W5-5) | No value — decision pending | Legacy defaults (1,000 / 500) used |
| `petty_cash_surrender_due_days` / `due_soon_days` (W5-8) | No value — decision pending | No derived due date; no overdue state (W5-9 block dormant) |
| W5-9 advance count/amount limits | Not configurable yet — decision pending | Only the overdue rule exists |
| Cash-count frequency (W5-7) | Decision pending | No schedule enforced |
| Payment terms (W1-7) | **No rows seeded** by design | Invoices keep free-form due dates; Finance can create terms in the UI |
| Voucher escalation (W4-3) | Decision pending; not built | — |
| Finance account mappings (`finance_accounts.map`) | Map drafted but commented out | Hard-coded codes used, as on master (§12) |

**None blocks deployment.** Every absent value leaves its mechanism inactive or on legacy behaviour. Registering the setting rows (`FinanceSettingsSeeder`) is optional and only makes them visible. There is no settings UI; values need controlled DB configuration.

## 12. Chart of Accounts Safety (STAB-1)

`ChartAccountMap::local($code)` returns the mapped code, **or the original reference code when unmapped**. With the map empty (it is fully commented out), posting behaviour is **identical to master's hard-coded codes**, which STAB-1 verified as behaviour-unchanged. `JournalPostingService::accountByCode()` resolves only *postable* accounts; a code missing from the live chart yields `null`.
- **Petty-cash and payment paths:** the failure is flagged and retryable (STAB-4).
- **Other paths:** it fails as on master.

It **cannot silently mis-post to an arbitrary account**. Phase 2B therefore adds no new chart dependency beyond what production already runs. Live-chart verification remains advisable but is **not a release blocker**. Posting paths added or changed in this release (the payroll Payment, surrender reversal) use the same resolver.

## 13. Open Accountant Decisions

| Decision | Classification |
|---|---|
| STAB-2 WIP vs COGS | FUTURE ACCOUNTING ENHANCEMENT (current treatment unchanged) |
| STAB-7 historical remediation | DOES NOT BLOCK RELEASE BUT BLOCKS FEATURE (historical correction) |
| W1-10 credit-note WIP reversal | DOES NOT BLOCK RELEASE BUT BLOCKS FEATURE |
| W2-9 supplier credits | DOES NOT BLOCK RELEASE BUT BLOCKS FEATURE |
| W3-2 / W3-8 advance payout and recovery GL | DOES NOT BLOCK RELEASE BUT BLOCKS FEATURE (payout creates a Payment with no journal, by design) |
| W5-7 cash-count variance GL | DOES NOT BLOCK RELEASE BUT BLOCKS FEATURE |
| W6-1A overhead | FUTURE ACCOUNTING ENHANCEMENT |
| W6-7 write-off | DOES NOT BLOCK RELEASE BUT BLOCKS FEATURE |
| W7-24 / W7-25 / W7-26 | FUTURE ACCOUNTING ENHANCEMENT |
| Equity / opening balances | FUTURE ACCOUNTING ENHANCEMENT (Balance Sheet) |

**No accountant decision blocks the release.**

## 14. Frontend Release Audit

- **Routes and navigation:** there are **no route or navigation changes** in this release. `src/router/finance.ts` and both `navigation.ts` files are unchanged versus master.
- **API contract alignment:** every new API call in the frontend diff was matched against the backend route table (`route:list` on the release branch). All 46 endpoints exist, including labour, allocation, closure, payment terms, financial position, invoice check/issue/return and attachments, voucher return/reject/resubmit/senior-approve, PO amendments, correction and senior approval. **The one exception is `/api/hr/technical-labour`, which the release intentionally removes** (below).
- **States:** permission visibility, empty, loading, error, unauthorized and closed states for the W6/W7 panels are covered by `wave6Controls.spec` and `wave7Labour.spec`. W1/W2/W3 states are covered by `enquiryFinanceModal.spec`, `wave2Controls.spec` and `wave3Controls.spec`. No UI redesign was performed.

**Known compatibility gap (non-blocking by WNG scope):** the overtime and compensation request modals (`OvertimeRequestModal.vue`, `CompensationRequestModal.vue`) still call `/api/hr/technical-labour`, whose routes this release comments out. They call it inside a `Promise.all` together with the employee list, so after release **their employee picker loads empty**. WNG has stated overtime is not in use (Report 41 §4). If overtime is used before this is fixed, managers cannot pick staff there. Recorded as a known issue, not changed.

## 15. Type-Check (ENG-1)

`vue-tsc --build --force` (2026-09-28): **256 errors**. The normalised error set is **identical** to the committed pre-Phase-2B HEAD baseline: **0 new, 0 removed**. **PASS against ENG-1.**

## 16. Known Build Defects

The two warnings are esbuild's "This assignment will throw because it is a constant" in `ReceiveStockModal.vue` (`quickCreate`) and `ResolveMaterialModal.vue` (`newMaterial`).

- **Source:** both come from the template binding `v-model="quickCreate"` / `v-model="newMaterial"` on `QuickCreateMaterialFields`, where the parent value is a `const … = reactive(...)`. Vue compiles that binding to an `onUpdate:modelValue` handler that reassigns the constant.
- **Reachability:** the child (`QuickCreateMaterialFields.vue`) declares `const model = defineModel(...)` and **only mutates nested fields** (`v-model="model.material_name"`, `model.value.issue_disposition = …`, `model.value.base_uom_id = …`). It never reassigns `model.value`. `defineModel` emits `update:modelValue` only on whole-value reassignment, so **the throwing handler is never invoked** in the current code. Nested edits reach the parent's reactive object directly.
- **Classification:** **latent defect, not reachable today, non-blocking.** It becomes a runtime `TypeError` the moment anyone adds `model.value = {…}` to the child. The recommended hardening, after release, is to bind `:model-value` instead of `v-model` in both parents, or make the parent value a `ref`, with a regression test. It was not changed here: the gate only authorises fixes for reachable runtime defects.

## 17. Automated Test Gate — Backend

`vendor/bin/phpunit` in DDEV (PHP 8.4, MariaDB 11.8, db_test), JUnit `storage/logs/release-gate-junit.xml`: **1,427 tests, 9,421 assertions, 0 failures, 0 errors, 0 skipped**, in **8 min 11 s**. db_test was verified clean afterwards.

## 18. Frontend Test Gate

These are three separate gates:

| Gate | Result |
|---|---|
| Finance specs (`tests/unit/finance`) | 56 passed |
| Procurement specs (`tests/unit/procurement`) | 35 passed |
| Procurement module specs (`src/modules/procurement-stores`) | 36 passed |
| Project specs (`tests/unit/projects`, incl. the TeamsTask Employee picker) | 7 passed |
| HR / Employee frontend specs | **None exist** in the repository (coverage gap, recorded) |
| **Complete unit suite** | **27 files, 166 tests, all passed** |
| **Type-check** | **256, ENG-1 baseline, 0 new** |
| **Production build** | **Success**: exit 0, 1,891 modules, 29.3 s |

## 19. CI/CD Audit (repository evidence: `.github/workflows/deploy.yml` in both repos)

| Question | Finding |
|---|---|
| Trigger | `push` to `master` (production) or `staging` (staging) |
| Merge to master deploys? | **Yes, automatically**, in each repo independently |
| Migrations automatic? | **Yes.** The backend runs `php artisan migrate --force` in the same SSH script as `git pull` and `composer install` |
| Other steps | `config:cache`, `route:cache`, `view:cache`, `stores:process-finance-postings`. **No** `permissions:sync`, **no** seeders (deliberately; `docs/seeding-in-production.md`) |
| Tests / type-check in CI | **None.** There is no test or type-check job; `deploy.yml` is the only workflow |
| Backend/frontend coupling | **Independent**; each repo deploys on its own push. The frontend builds on the runner and rsyncs `dist/` |
| Staging environment | **Exists** (`~/erp-backend-staging`, `~/public_html/erp-frontend-staging`, `stagingapi.woodnorkgreen.co.ke`). **But it is stale:** backend `origin/staging` is 223 commits behind master (last 2026-08-25), frontend 186 |
| Stop between code and migration | **No.** Pull and migrate run in one unattended script |
| Automated rollback | **None** |
| Automated DB backup | **None** |

## 20. Backup Requirement (minimum; to be performed by WNG/Engineering)

1. **Production database:** a full logical dump taken immediately before release, with routines, triggers and a consistent snapshot, e.g. `mysqldump --single-transaction --routines --triggers <db> > pre-phase2b-<timestamp>.sql`. Credentials come from the server's environment; none are stated here. Verify the dump by restoring it to a scratch database and counting rows in `payments`, `project_invoices`, `petty_cash_requisitions` and `journal_entries`.
2. **Migration snapshot:** `php artisan migrate:status` output saved, plus `SELECT * FROM migrations`.
3. **Backend release reference:** the current production commit (`git -C ~/erp-backend-master rev-parse HEAD`; expected `60491b1`).
4. **Frontend release reference:** a copy of `~/public_html/erp-frontend-master/` and the current master commit (`d24a3f7`).
5. **Environment:** a copy of the backend `.env` (not committed anywhere).
6. **Storage:** backend `storage/app` (existing uploads), because new attachment features write there.

## 21. Staging Trial

Staging exists but is stale, so the trial needs preparation.

1. Restore a **recent production dump** into the staging database, replacing stale staging data.
2. Fast-forward `staging` to `origin/master` and let it deploy (brings staging code to production parity; migrations up to `2026_09_21`). Record `migrate:status`.
3. Run preflight P0–P10 on staging.
4. Push `finance/critical-stabilization-fixes` to `staging` in the **backend** first. The pipeline pulls, migrates and caches; watch the migration output for #14.
5. Run `php artisan permissions:sync --dry-run`, review it, then run `php artisan permissions:sync`.
6. Push the same branch to `staging` in the **frontend**.
7. Run the smoke tests (§22) on staging.
8. Walk through each Finance workflow with production-like data.
9. Check `storage/logs/laravel.log` for errors.
10. Run the integrity queries: row counts unchanged in pre-existing tables; `spend_vouchers.review_state` distribution; `project_invoice_lines` gross = net + discount.

**Not performed:** pushing to `staging` deploys, and the trial needs WNG approval.

## 22. Release Smoke Test Plan

Use a designated test project/enquiry and small amounts; void or reverse through the normal controls afterwards.

| Area | Checks |
|---|---|
| Receivables | Create a draft invoice → (preparer cannot check own) → holder checks → issue → the journal exists once → financial position shows invoiced/outstanding separately. Return-for-correction path on a second draft |
| Procurement | Create PO → submit → return for correction → resubmit → approve → propose amendment → approve → GRN → bill (staged: bill below remaining) → duplicate supplier invoice number warned → payment |
| Expenses | Requisition → approve → disburse → surrender with receipt → return → resubmit → reconcile. Cost recognised once |
| Payment Vouchers | Create → return → correct → resubmit → approve → post. Reject path |
| Petty Cash | Top-up, disbursement, cash count recorded (variance shown, no journal), custody handover |
| Project Costing | Approved budget → planned lines → verified cost → statement → portfolio margin shows provisional direct margin → close (pre-check) → recording a cost is blocked → reopen (Super Admin) |
| Labour | Record against a budget line → PO verify → Finance verify → Project Costing labour row increases; no journal created |
| General Finance | Ledger opens; trial balance; reconciliation screen; periods; reports page; a 403 for a user lacking a new permission |

## 23. Release Order

Compatibility was checked against the actual contracts:

- **New frontend + old backend: not safe.** Labour, closure, allocation, invoice-check, financial-position, voucher-review and amendment calls would return 404.
- **Old frontend + new backend: partly safe.** Existing screens keep working, **except client invoice issuing**, which now requires a check the old UI cannot perform. Petty-cash and voucher statuses are additive.
- **Conclusion:** a **coordinated backend-first release in one window**, with the frontend following within minutes.

The pipeline makes backend code and migrations effectively one step (pull → migrate within seconds), so the order is:

**backup → backend push (code + migrations) → `permissions:sync` → frontend push → smoke tests.**

## 24. Rollback Strategy

**Before migrations:** revert by not pushing. If the backend push fails before `migrate` (for example `composer install`), fix it or `git reset` the server checkout to `60491b1`.

**After migrations, before user activity:**
- **Preferred:** restore the pre-release dump plus the previous code references (backend `60491b1`, frontend `d24a3f7`).
- **Acceptable alternative:** redeploy the old code only. The migrations are additive, so old code runs against the new schema, with two exceptions: master's code does not know `surrender_returned` (no rows will have it yet), and the PO FKs are now RESTRICT (only affects PO deletion, which was already guarded in practice).
- **Do not** rely on `migrate:rollback` across 23 migrations. #14 is non-atomic, and `down()` drops tables that may already hold records.

**After real Finance transactions:**
- **Do not roll back schema or restore the dump.** Doing so would destroy real accounting records (invoice checks, amendments, allocations, labour actuals, reviews, recoveries).
- Fix forward. If a specific feature misbehaves, withdraw its permission via `permissions:sync`/role edit to disable it, and correct data only through the existing reversal and correction mechanisms.
- A dump restore after transactions is a last resort requiring Finance sign-off and re-entry of every post-release transaction.

## 25. Post-Deployment Verification (first hour)

**Immediately:**
1. `php artisan migrate:status` shows all 23 as Ran.
2. The site loads and login works.
3. `storage/logs/laravel.log` has no new errors.
4. `php artisan route:list | grep labour-actuals` returns the routes.

**Then:**
- **Permissions:** `permissions:sync --dry-run` reports "Already in sync" after the real run. P11 confirms holders.
- **Finance routes:** each Finance menu page opens for an Accounts user.
- **Journal posting:** issue one test invoice → exactly one journal.
- **CostLines:** one test verified cost → exactly one CostLine.
- **Payments:** one voucher/bill payment → one Payment.
- **Petty cash:** one disbursement → the advance journal exists; no GL-failure flag.
- **Project Costing:** a statement renders and the margin shows as provisional.
- **Labour:** the panel loads for a budgeted project.
- **Queues:** none required. `stores:process-finance-postings` ran in the deploy; check its output.

**Monitor at 15, 30 and 60 minutes:**
- the Laravel log for 500s
- GL-posting failure flags (`advance_gl_posting_failed_at`, `cost_gl_posting_failed_at` non-null)
- 403 permission-denied log lines (they show who needs a grant)
- user reports from Accounts and Procurement

## 26. Release Blockers

| Blocker | Severity | Evidence | Required action | Owner | Release impact |
|---|---|---|---|---|---|
| **B1: no operational holder for `finance.receivables.invoice_check`** | HIGH | `…/invoices/{id}/check` requires it (route middleware). `issueProjectInvoice` aborts 422 without `checked_at`. The matrix grants it to Super Admin only (dev, after sync) | WNG names the checker role(s) (ROLE-1 subset). Grant through the role matrix and `permissions:sync`, or an explicit role grant, before or at go-live. Alternatively WNG accepts Super Admin checking temporarily | WNG Management + Finance Lead | Without it, client invoices cannot be issued by Accounts after release |
| **B2: no automated backup and no stop point before `migrate --force`** | HIGH | `deploy.yml` | A verified manual production backup and migration snapshot (§20) immediately before the backend push | Engineering / hosting owner | Without it, a failed #14 has no clean recovery |
| **B3: preflight not yet run on production** | HIGH (gating) | §4 | Run P0–P10; all stop criteria clear | Engineering | Unknown data could fail the ENUM `MODIFY` or FK rebuild |

There are no code blockers. Tests, ENG-1 type-check and build all pass.

## 27. Non-Blocking Debt

- **Strongly recommended precondition, not a blocker:** a staging trial on a fresh production copy (staging is stale).
- **Legacy TypeScript:** 256 errors (ENG-1).
- **Latent defects:** `v-model`-on-const in `ReceiveStockModal`/`ResolveMaterialModal` (§16).
- **Overtime/compensation modals** call the removed Technical Labour route (§14); overtime is not in use.
- **Phantom role:** `Operations` in the W7 permission migration.
- **Test and CI gaps:** no CI tests or type-check; no HR/Employee frontend specs; no backend tests for Production or Teams.
- **Deferred accountant decisions** (§13) and **deferred business decisions:** thresholds, deadlines, limits, W2-7..10, W4-3/4, W3-4, ROLE-2.
- **Unused Technical Labour residue** (work orders, overtime).
- **Documentation reconciliation items** (Report 42 §22).
- **Future work:** the UX redesign, W8, W9 and W10.

## 28. Production Readiness Matrix

| Area | Code Ready | Data Ready | Config Ready | Permissions Ready | Tests Ready | Release Ready |
|---|---|---|---|---|---|---|
| STAB | Yes | Yes (preflight) | Yes (map unchanged = master behaviour) | Yes | Yes | **Yes, after B2/B3** |
| W1 | Yes | Yes (#12 backfill) | Yes (terms optional) | **No: invoice_check holder (B1)** | Yes | **After B1** |
| W2 | Yes | Yes (P6) | Yes (threshold dormant) | Partial: `orders.amend` Super Admin only (go-live config) | Yes | **Yes, after B2/B3** (amend holder recommended) |
| W3 | Yes | Yes | Yes | Yes (overrides fail closed) | Yes | **Yes, after B2/B3** |
| W4 | Yes | Yes (#14 backfill) | Yes (threshold dormant) | Yes | Yes | **Yes, after B2/B3** |
| W5 | Yes | Yes (P3/P7) | Yes (legacy defaults) | Partial: custody/count review Super Admin only (new features) | Yes | **Yes, after B2/B3** |
| W6 | Yes | Yes | Yes | Yes (Accounts via migration; reopen unassigned by design) | Yes | **Yes, after B2/B3** |
| W7 | Yes | Yes (forward-only) | Yes | Yes (Accounts/Admin/Costing/PM via migration) | Yes | **Yes, after B2/B3** |

## 29. Release Verdict

### CONDITIONAL PASS — RELEASE AFTER LISTED PRECONDITIONS

Code, tests (backend 1,427 / frontend 166), the ENG-1 type-check and the build are release-ready. No migration is destructive, and every new UNIQUE constraint and FK is collision-free by construction.

Release may proceed only after these preconditions:
1. **B1:** WNG names and grants the `finance.receivables.invoice_check` holder, or explicitly accepts Super Admin checking temporarily.
2. **B2:** a verified production backup and migration snapshot.
3. **B3:** preflight P0–P10 is clean.
4. **Strongly recommended:** a staging trial on a fresh production copy.
5. **Confirmed:** overtime/compensation are not in use at release time (§14).

**Nothing was deployed.**

## 30. Release Runbook (for human execution after the preconditions)

**PRE-DEPLOYMENT**
1. Announce a Finance maintenance window (about 30 minutes). Ask users to pause Finance/Procurement entry.
2. Confirm B1 is resolved (holder role named; matrix or grant prepared) and item 5.
3. Confirm the staging trial result (§21), if performed.

**DATABASE BACKUP**
4. Take the production dump (§20.1) and verify the restore.
5. Save `php artisan migrate:status`, `git rev-parse HEAD` for both apps, the frontend `dist` copy, `.env`, and `storage/app`.

**PREFLIGHT**
6. Run P0–P10 (§4). Stop if any stop criterion trips.

**BACKEND DEPLOY + MIGRATIONS** (one pipeline step)
7. Merge backend `finance/critical-stabilization-fixes` → `master` by fast-forward and push. The pipeline pulls, `composer install`s, runs `migrate --force`, caches, and runs `stores:process-finance-postings`.
8. Watch the GitHub Actions log. Confirm all 23 migrations say DONE. **If #14 fails part-way: stop, do not retry, and go to the rollback decision.**
9. On the server: `php artisan permissions:sync --dry-run`, review, then `php artisan permissions:sync`. Apply the B1 grant if it was not placed in the matrix.
10. Optional: `php artisan db:seed --class=App\\Modules\\Finance\\Database\\Seeders\\FinanceSettingsSeeder --force` to register the null policy rows. Only after reviewing it on staging, because `ReferenceDataSeeder` is not yet safe to run whole (`docs/seeding-in-production.md`).

**FRONTEND DEPLOY**
11. Merge frontend `finance/critical-stabilization-fixes` → `master` by fast-forward and push. Confirm the Actions build and rsync succeeded.

**SMOKE TESTS**
12. Run §22 with test data.

**POST-DEPLOYMENT CHECKS**
13. Run §25 immediate checks.

**MONITORING**
14. Run §25 checks at 15, 30 and 60 minutes, then daily GL-failure flag checks for the first week.

**ROLLBACK DECISION POINTS**
- **At step 8 (migration failure):** restore the dump and redeploy old code (`60491b1` / `d24a3f7`).
- **At steps 12–13, before real transactions:** restore the dump plus old code, or old code only (§24).
- **After real transactions:** fix forward only; disable features via permissions; no schema rollback.

---

## PHASE 2B RELEASE POSITION

**Release Verdict:** CONDITIONAL PASS — RELEASE AFTER LISTED PRECONDITIONS
**Backend Regression:** 1,427 tests, 9,421 assertions, 0 failures, 0 errors, 0 skipped (8 min 11 s)
**Frontend Regression:** 27 files, 166 tests, all passed (finance 56, procurement 35 + 36, projects 7)
**Type-Check:** 256 errors, identical to the ENG-1 baseline (0 new); passes ENG-1
**Production Build:** Success (exit 0, 1,891 modules)
**Migration Count:** 23 (6 procurement-module, 17 core)
**Migration Risk:** 1 HIGH (#14 Wave 3, a non-atomic ENUM rewrite plus backfill), 2 MEDIUM (#1 FK tightening, #12 invoice-line backfill), 20 LOW; none destructive; all UNIQUE constraints on new tables
**Permission Readiness:** 19 new permissions. W6/W7 are granted via migrations. 9 W1–W3 permissions need `permissions:sync` (manual) and are Super Admin-only until WNG assigns holders. `finance.receivables.invoice_check` must have a real holder before go-live
**Configuration Readiness:** All policy values unset; mechanisms dormant or on legacy defaults; nothing blocks deployment
**Accounting Policy Blockers:** None block release (§13)
**Release Blockers:** B1 invoice-check holder; B2 verified backup and snapshot (pipeline has none); B3 production preflight
**Required Preconditions:** B1–B3; strongly recommended staging trial on a fresh production copy; confirm overtime unused
**Recommended Deployment Strategy:** One coordinated window: backup → backend fast-forward push (code + migrations) → `permissions:sync` → frontend fast-forward push → smoke tests
**Rollback Position:** Dump-restore plus previous commits before real transactions; fix-forward only after real Finance transactions; never `migrate:rollback`
**Production Deployment Performed:** NO
**W8 Started:** NO
