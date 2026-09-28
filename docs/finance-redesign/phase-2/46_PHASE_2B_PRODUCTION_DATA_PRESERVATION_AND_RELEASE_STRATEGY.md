# 46 — Phase 2B: Production Data Preservation Boundary & Release Strategy

**Date:** 2026-09-28
**Type:** Analysis and release planning only. **Nothing was deleted, reset, migrated, deployed, or merged. No production or staging connection was made.** W8 has not started.
**Verdict:** **DATA-1 REQUIRES ADDITIONAL WNG DECISION** (§31).

**Evidence base:**
- Code: the release branch `finance/critical-stabilization-fixes` (models, services, migrations).
- Foreign keys: the live foreign-key graph from `information_schema`, read from the local DDEV database. Its schema is master's migrations plus Phase 2B.
- Data: the *characteristics* of that local database's data (counts, date ranges, provenance patterns only; no record contents read).

**Caveat:** the local database is a production-shaped copy of **unknown date**, and it is actively written by development work. Several rows were created today. Its data profile is indicative, **not** a statement about production. §21 gives read-only queries to confirm each finding on production during the release gate.

---

## 1. Executive Summary

DATA-1 (preserve Projects and Employee Records; treat Finance development/test data as resettable) is **structurally sound**. No preserved table depends on a Finance transactional table through a restricting foreign key. Cascades flow only *from* the preserved side, so clearing Finance child rows cannot delete a Project or an Employee.

**What is not safe:** the naive rule "keep `projects` and `employees`, clear the rest". It would destroy projects and staff. Three examples:
- `clients → project_enquiries` is **ON DELETE CASCADE**.
- `departments → employees` and `departments → enquiry_tasks` are **CASCADE**.
- `project_enquiries → projects` and `→ enquiry_tasks` are **CASCADE**.

"Projects" in this system is a family rooted at `project_enquiries`, not the `projects` table alone.

**Measured cascade reach** (walking every ON DELETE CASCADE foreign key):

| Deleting one row of… | Cascades into |
|---|---|
| `project_enquiries` | **66 tables** |
| `clients` | **68 tables**, including `project_enquiries` |
| `departments` | **77 tables**, including `employees` |
| `employees` | 29 tables |

**The inspection also found real-looking Finance data that DATA-1's default would reset:**
1. **An imported petty-cash register.** `payments` holds 1,554 disbursements dated Jan 2025 – Feb 2026, predating the ERP's first project, all stamped 03:00, never linked to a requisition. That is the Excel-import signature. Alongside it: 1,628 petty-cash ledger entries, 74 top-ups, 22 requisitions, and a live float balance.
2. **Verified client receipts.** `enquiry_payments` holds 84 receipts, all verified, across 82 distinct projects.
3. **One payroll run and one salary advance.**

These are **POSSIBLY REAL** and must not be reset without WNG's explicit answer.

**Separately, a W7 go-live defect surfaced.** Since 2026-07-07 every Project Budget stays `draft` (internal budget approval was retired: `BudgetService::resolveSaveStatus()`). W7, however, only accepts budgets with `status = 'approved'`, so on real data **no project can record budgeted labour**. The W7 test fixtures build `approved` budgets, which is why Report 43 did not surface it.

**Verdict: DATA-1 REQUIRES ADDITIONAL WNG DECISION**, with three narrow data questions (§27). The W7 budget-state question is also listed because it gates W7 go-live.

## 2. Why the Release Strategy Changed

Reports 44 and 45 assumed existing Finance transactional history must be preserved and migrated. That is why they demanded a production-copy staging rehearsal, especially for migration #14, which rewrites `petty_cash_requisitions.status` and backfills `spend_vouchers`.

WNG has now stated that only Projects and Employee Records (plus what they need) are authoritative. If Finance tables can be emptied before migration, the risky migrations run against empty tables and the rehearsal requirement shrinks. This report tests whether that is true.

## 3. DATA-1 (as analysed)

The authoritative data to preserve is:
- Projects and Employee Records.
- Every record technically required for them to stay relationally valid, usable, identifiable and permission-compatible.
- Legitimate master and system data.

Finance development/test transactional data may be reset, but only through an approved, controlled release procedure. Real operational or accounting data is excluded from the reset.

## 4. Current Production Data Assumption

- **Not verified:** production contents are unknown to this environment (Report 45).
- **Proxy:** the local copy has 1,013 project enquiries (Dec 2025 → now), 535 projects, 12,333 project tasks, 54 employees, 57 users, 203 clients, and 6 suppliers.
- **Finance-transaction volumes in the proxy:** mostly zero (invoices, POs, bills, vouchers, journals), with the exceptions listed in §1.
- **Conclusions still hold:** every structural conclusion here comes from the schema, which is identical by construction. Every data conclusion must be re-confirmed on production (§21).

## 5. Project Dependency Graph

"Project" is rooted at **`project_enquiries`**, the job record carrying `job_number`, client, officer, status and financial closure.

- **Required parents** (deleting them destroys or orphans projects):
  - `clients`: **CASCADE** into `project_enquiries`.
  - `departments`: SET NULL on enquiries, but **CASCADE** into `enquiry_tasks`.
  - `users`: officers, assignees, creators.
- **Project children** (CASCADE from `project_enquiries` — the project *is* these):
  - `projects` (converted project, 535) and `project_deliverables`.
  - `enquiry_tasks` (12,333), with its own CASCADE children: `site_surveys`, `design_requirements`, `design_assets`, `task_budget_data`, `task_quote_data`, `task_materials_data`, `task_procurement_data`, `task_production_data`, `teams_tasks` (→ `teams_members`), `logistics_tasks`, `setup_tasks`, `setdown_tasks`, `handover_surveys`, `archival_reports`, `task_assignment_history`, `enquiry_task_user`.
  - Also `work_orders`, `ncr_reports`, `logistics_log_entries`, `governance_audit_logs`.
- **Project commercial basis:**
  - `quote_approvals` (201 approved), `quote_versions`, `task_quote_data`. Required by W1-2 (an invoice needs an approved commercial basis).
  - `project_elements` / `element_materials` (material planning).
- **Project budget:** `task_budget_data` (479, one per project) → `budget_additions` (6,808), `budget_versions`, `budget_approvals` (CASCADE). Classified in §11.

## 6. Employee Dependency Graph

- **Required parents:** `departments` (**CASCADE** into `employees`), and the self-reference `employees.manager_id`.
- **Linked:** `users.employee_id` (SET NULL), the login identity for staff.
- **Employee master and HR history** (CASCADE children):
  - `employee_salary_histories`, `employee_documents`, `employee_certifications`, `employee_skills`
  - `hr_actions`, `disciplinary_cases`, `performance_reviews`, `profile_update_requests`
  - `leave_requests`, `leave_handovers`, `leave_balance_adjustments`
  - `attendance_records`, `attendance_schedule_assignments`
  - `drivers`, `announcements`
- **Audit:** `hr_audit_logs` (**RESTRICT**, so an employee with audit history cannot be deleted; correct).
- **Payroll and advances** (CASCADE children, §13): `payroll_ledgers`, `payslips`, `salary_advance_requests`, `compensations`, `ot_entries`, `ledger_entries`.

## 7. Reverse Dependencies (tables referencing Projects/Employees) and What a Finance Reset Does to Them

| Referencing table | FK rule | Effect of clearing its rows | Effect on preserved record |
|---|---|---|---|
| `cost_lines` (→ enquiries, projects) | SET NULL (parent side) | Removes Finance facts only | None |
| `payments`, `bills`, `petty_cash_requisitions` (→ enquiries/projects) | SET NULL | Removes Finance facts | None |
| `project_invoices` (→ enquiries) | NO ACTION | Must be deleted child-first (lines, allocations) | None |
| `enquiry_payments` (→ enquiries) | CASCADE (from parent) | Removes client receipts | Project loses its receipt history (**§12: possibly real**) |
| `cost_line_allocations`, `project_labour_actuals` (→ enquiries) | CASCADE | Removes W6/W7 facts | None |
| `payroll_ledgers`, `payslips`, `salary_advance_requests` (→ employees) | CASCADE | Removes payroll history | Employee master intact; history lost (**§13**) |
| `technical_labours` (→ employees) | SET NULL | — | Referenced by 340 `teams_members` rows (§14) |
| `governance_audit_logs` (→ enquiries) | CASCADE | Audit trail | Keep (§9) |

**No reset of a Finance child table can cascade into `project_enquiries`, `projects` or `employees`.** The only paths that delete preserved rows run *from* `clients`, `departments` or `project_enquiries` downward. Those parents are in the preservation set and must never appear in a reset.

## 8. Master Data

These tables are **PRESERVE — WNG MASTER DATA**:
- **Clients** (203, and a CASCADE parent of projects) and **suppliers** (6).
- **Departments.**
- **Materials catalogue:** `library_materials`, `material_categories`, `material_versions`, `material_workstations`, `units_of_measure`, `element_types`, `design_types`, `workstations`, `locations`.
- **Team reference:** `team_types`, `team_categories`, `team_category_types`.
- **Finance reference** (required by Phase 2B itself, not transactional): `chart_of_accounts` (208), `accounting_periods` (48), `expense_codes` (109), `cost_centres`, `cost_causes`, `activities`, `payee_types`, `payment_sources`, `vat_treatments`, `wht_categories`, `posting_rules`, `finance_settings`, `petty_cash_requisition_types`, `payment_terms`.
- **HR reference:** `leave_types`, `payroll_tax_bands`, `payroll_variables`, `hr_action_types`.

## 9. Security / System Data

**DO NOT TOUCH:** `users`, `roles`, `permissions`, `role_has_permissions`, `model_has_roles`, `model_has_permissions`, `personal_access_tokens`, `sessions`, `password_reset_tokens`, `migrations`, `cache*`, `jobs`, `failed_jobs`, `app_notifications`, `action_logs`, `system_events`, `document_sequences`, and the audit logs (`governance_audit_logs`, `hr_audit_logs`, `finance_period_audit_logs`).

Audit rows that describe reset Finance records are **kept**. Audit history is never deleted to tidy up.

## 10. Finance Transactional Data

| Table(s) | Proxy rows | Evidence | Classification |
|---|---|---|---|
| `payments` | 1,554 | All stamped 03:00, 57 distinct days, Jan 2025 – Feb 2026, 0 linked to requisitions, all `active`. That is the petty-cash Excel import, predating ERP go-live | **POSSIBLY REAL — WNG REVIEW REQUIRED** |
| `petty_cash_ledger_entries`, `petty_cash_top_ups`, `petty_cash_activity_logs`, `petty_cash_balances` (float 8,238.26) | 1,628 / 74 / 480 / 1 | Same period and pattern; a running float | **POSSIBLY REAL — WNG REVIEW REQUIRED** |
| `petty_cash_requisitions` / `_items` | 22 / 49 | Feb–Mar 2026, several creators | **POSSIBLY REAL — WNG REVIEW REQUIRED** |
| `enquiry_payments` (client receipts) | 84 | All verified, 82 distinct projects, Mar–Sep 2026 | **POSSIBLY REAL — WNG REVIEW REQUIRED** |
| `payroll_runs`, `payroll_ledgers`, `salary_advance_requests` | 1 / 2 / 1 | Single run (Jun 2026) | **POSSIBLY REAL — WNG REVIEW REQUIRED** (small) |
| `requisitions` / `requisition_items` (procurement) | 6 / 14 | Feb–Apr 2026 | Procurement module (cross-module, §16); outside the Finance reset |
| `cost_lines` | 4 | All planned, created today by development work; regenerable (§11) | **CLEARLY DEVELOPMENT/TEST; SAFE TO RESET; REGENERATED BY PHASE 2B** |
| `journal_entries` / `journal_lines` | 0 / 2 | Created today | **CLEARLY DEVELOPMENT/TEST; SAFE TO RESET** |
| `project_invoices` (+ lines, allocations), `client_receipts`, `purchase_orders` (+ items), `goods_receipt_notes` (+ items), `bills`, `bill_payments`, `spend_vouchers` (+ allocations), `payment_allocations`, `direct_disbursement_requests`, `finance_cash_movements`, reconciliation tables, `stores_finance_postings` | 0 | Empty in proxy | **SAFE TO RESET** (nothing to do in proxy; production must be checked) |
| All 13 Phase 2B tables | — | Do not exist in production yet | Created empty by the migrations |

## 11. Project Budget Classification

`task_budget_data` (479; **exactly one per project with a budget**), `budget_additions` (6,808), `budget_versions`, `budget_approvals`.

- **Classification: PRESERVE — PROJECT OPERATIONAL DATA** (not Finance development data).
- **Why:**
  - The budget is a task inside the project workflow (`enquiry_tasks` CASCADE).
  - It is the planning artefact behind the 201 approved quotes.
  - W6-11 records it as an "existing control — preserve".
  - W6 and W7 are designed on it: the budget is the labour-planning and rate source.
  - Deleting it would erase each project's plan.
- **Existing Project CostLines:** only 4 planned lines exist in the proxy, created today. They are **REGENERATED BY PHASE 2B**. `php artisan finance:project-budgets` (with `--dry-run`) re-projects every budget into planned CostLines idempotently; `BudgetProjector::projectAll()` has no status filter.
- **Queue gap:** projection is also triggered by the queued `ProjectBudgetLines` listener. Production has no queue supervisor (see `deploy.yml` comment), so projection may never run there on its own. **The release must run `finance:project-budgets` once, after migration.**
- **W7 defect found:**
  - **Budget state:** all 479 budgets are `draft`. No code path sets `approved` since 2026-07-07 (`BudgetService::resolveSaveStatus()`).
  - **What W7 filters on:** `ProjectLabourActualService::authoritativeBudgetData()` and `getBudgetLabourLines()` filter `status = 'approved'`.
  - **Effect:** on real data, W7 finds no budget, so labour cannot be recorded against any project's budget lines. Only unbudgeted labour works.
  - **What it needs:** a WNG definition of which budget state counts as the "approved Project Budget" of W7-1/W7-2 (§27 Q4). It is not a data-reset question.

## 12. Project Billing Classification

Projects remain **structurally valid without any invoice or receipt**. `project_enquiries` does not reference them, and invoices and receipts are children.

- **Keep (project identity/commercial basis):** quotes (`quote_approvals`, `quote_versions`, `task_quote_data`). W1-2 needs them as the commercial basis.
- **Finance transactional history:**
  - `project_invoices` (0 in proxy).
  - `enquiry_payments` (84 verified receipts). These look like **real client money received**, so they are POSSIBLY REAL. Clearing them would make every project's "cash received" and "unallocated credit" (W1-4, W1-9) read zero.

## 13. Employee / Payroll Boundary

- **Employee master (PRESERVE — PRIMARY and REQUIRED):** `employees`, `departments`, `users.employee_id`, `employee_salary_histories`, `employee_documents`, `employee_certifications`, `employee_skills`, leave and attendance records, HR actions, disciplinary cases, reviews, profile requests, `drivers`, `hr_audit_logs`. These are HR records, not Finance.
- **Payroll transactions:** `payroll_runs` (1), `payroll_ledgers` (2), `payslips` (0), `salary_advance_requests` (1), `salary_advance_recoveries` (new). None is needed for Employee integrity; they are CASCADE children. They are POSSIBLY REAL (a genuine June 2026 run?) and need WNG review.
- **Finance payroll posting:** journals (none in proxy) and `payroll_runs.payment_id` (new). Resettable.
- **Salary data:** no salary figures were read for this report.

## 14. Technical Labour Boundary

`technical_labours` holds 36 rows, all created in the same second (2026-01-25 22:03:15, a bulk import). It is referenced by **340 `teams_members` rows** (historical crew assignments; FK SET NULL) and by the overtime and ledger tables.

- **Classification:** **PRESERVE — HISTORICAL COMPATIBILITY**, not personnel master. W7-10 already requires retaining the tables.
- **Deleting it would not fail**, because of SET NULL. It would **silently erase who was on 340 historical crews.**
- **It is not part of the authoritative personnel boundary.** Employee Records are.

## 15. Client / Supplier Boundary

`clients` (203) and `suppliers` (6) are **PRESERVE — WNG MASTER DATA**. `clients` is also a **CASCADE parent of `project_enquiries`**, so deleting a client deletes its projects. Suppliers are procurement master data independent of any test PO.

## 16. Cross-Module Boundary

Outside the Finance reset: Assets, Fleet/Logistics (`vehicles`, `trip_requests`, `deliveries`), Stores/Inventory (`stocks`, `inventory_logs`, `boards`, `library_materials`), Procurement requisitions, Production/Work Orders (`work_orders` 257, `production_*`), Printing, Design, HR and Projects.

- **One Finance ↔ Stores coupling:** `stores_finance_postings` (0 rows) and cost producers write `cost_lines`. Resetting `cost_lines` does not alter stock records; any stock-originated actual costs would regenerate only through new movements.
- **Recommendation:** leave stock-originated costs out of the reset if production has any. Check with §21 Q-C.

## 17. Table Classification Matrix (tables with data or with preservation significance)

| Table / Entity | Domain | Refs Project? | Refs Employee? | Authoritative? | Preserve? | Resettable? | Reason |
|---|---|:-:|:-:|---|---|---|---|
| `project_enquiries` | Projects | root | — | Yes | **PRESERVE — PRIMARY** | No | Project master |
| `projects` | Projects | Yes (CASCADE) | — | Yes | **PRESERVE — PRIMARY** | No | Converted project |
| `enquiry_tasks` + task children (§5) | Projects | Yes | — | Yes | **PRESERVE — PRIMARY** | No | The project workflow |
| `project_deliverables`, `project_elements`, `element_materials` | Projects | Yes | — | Yes | **PRESERVE — PRIMARY** | No | Scope and material plan |
| `teams_tasks`, `teams_members` | Projects | Yes | (via name) | Yes | **PRESERVE — PRIMARY** | No | Crews |
| `work_orders` + production | Production | Yes | — | Yes | **DO NOT TOUCH** (cross-module) | No | Outside Finance |
| `task_budget_data`, `budget_additions`, `budget_versions`, `budget_approvals` | Projects | Yes | — | Yes | **PRESERVE — REQUIRED DEPENDENCY** | No | Project plan; W6/W7 source |
| `quote_approvals`, `quote_versions`, `task_quote_data` | Projects | Yes | — | Yes | **PRESERVE — REQUIRED DEPENDENCY** | No | Commercial basis (W1-2) |
| `employees` | HR | — | root | Yes | **PRESERVE — PRIMARY** | No | Personnel master |
| Employee HR children (§13) | HR | — | Yes | Yes | **PRESERVE — PRIMARY** | No | HR record |
| `clients` | Master | CASCADE parent | — | Yes | **PRESERVE — REQUIRED DEPENDENCY** | **Never** | Deleting cascades to projects |
| `departments` | Master | CASCADE parent (tasks) | CASCADE parent | Yes | **PRESERVE — REQUIRED DEPENDENCY** | **Never** | Deleting cascades to employees/tasks |
| `users`, roles, permissions | Security | — | link | Yes | **DO NOT TOUCH** | No | Identity/authorization |
| `suppliers`, materials catalogue, team reference | Master | — | — | Yes | **PRESERVE — WNG MASTER DATA** | No | Operational masters |
| Finance reference (COA, periods, expense codes, sources, tax, settings, requisition types) | Finance master | — | — | Yes | **PRESERVE — WNG MASTER DATA** | No | Phase 2B depends on it |
| `technical_labours` | HR (legacy) | — | SET NULL | Historical | **PRESERVE — HISTORICAL COMPATIBILITY** | No | 340 crew links |
| `payments`, petty-cash ledger/top-ups/activity/balance, petty-cash requisitions | Finance | SET NULL | payee SET NULL | Unknown | — | — | **REVIEW BEFORE RESET** (imported register) |
| `enquiry_payments` | Finance (AR) | Yes | — | Unknown | — | — | **REVIEW BEFORE RESET** (verified receipts) |
| `payroll_runs`, `payroll_ledgers`, `salary_advance_requests` | Payroll | — | Yes | Unknown | — | — | **REVIEW BEFORE RESET** |
| `cost_lines` | Finance | SET NULL | — | No (dev rows) | — | Yes | **RESETTABLE / REGENERATED BY PHASE 2B** |
| `journal_entries`, `journal_lines` | GL | — | — | No (dev rows) | — | Yes | **RESETTABLE — DEV/TEST** |
| Invoices, POs, GRNs, bills, bill payments, vouchers, allocations, reconciliations, `client_receipts` | Finance | some | — | Empty in proxy | — | Yes | **RESETTABLE** (production to confirm) |
| `governance_audit_logs`, `hr_audit_logs`, `finance_period_audit_logs` | Audit | Yes | Yes | Yes | **DO NOT TOUCH** | No | Audit trail |
| `requisitions` (procurement), stocks, inventory | Procurement/Stores | — | — | Yes | **DO NOT TOUCH** (cross-module) | No | Outside Finance |

## 18. Minimum Safe Preservation Set

- **Always preserve:** `project_enquiries`, `projects`, `employees`.
- **Required dependencies:**
  - `clients` and `departments` (CASCADE parents).
  - `users` (the employee link and officers).
  - `enquiry_tasks` and its task children.
  - `project_deliverables`, `project_elements`, `element_materials`.
  - `teams_tasks`/`teams_members`.
  - Budgets (`task_budget_data`, `budget_additions`, `budget_versions`, `budget_approvals`).
  - Quotes (`quote_approvals`, `quote_versions`, `task_quote_data`).
  - Employee HR children.
  - `technical_labours` (historical compatibility).
- **Operational master data:** suppliers, materials catalogue, team/design/element/workstation/location/UoM reference, HR reference, Finance reference (§8).
- **Security/system data:** §9, including all audit logs.

## 19. Finance Reset Candidate Set (not executed)

**Confirmed-resettable today** (development rows, regenerable or empty):

| Table | Why resettable | FK implications / order | Auto-increment | Recreated by Phase 2B? | Preserved record references it? |
|---|---|---|---|---|---|
| `journal_lines`, then `journal_entries` | Created today by development work | Lines before entries. `enquiry_payments.journal_entry_id` and statement matches are SET NULL | Keep counters (never reuse IDs in accounting) | Postings recreate on new activity | Only via SET NULL links |
| `cost_lines` (+ `cost_line_allocations`, `cost_line_transfers` if present) | 4 dev planned lines | Allocations/transfers before lines. Planned lines are referenced by `consumes_line_id` (self), so delete all together | Keep | **Yes**: `finance:project-budgets` regenerates planned lines from preserved budgets | Payments/bills `planned_cost_line_id` / `consumes_line_id`, if any rows exist |
| Empty-in-proxy transactional tables (invoices + lines + allocations, `client_receipts`, POs + items, GRNs + items, bills, bill payments, vouchers + allocations + reviews, payment allocations, direct disbursements, cash movements, reconciliation, `stores_finance_postings`) | No authoritative content in proxy | Child-first order (items/lines/allocations → headers). PO children are RESTRICT after migration #1, so reset before migrating | Keep | Created by new workflows | No |

**Conditionally resettable** (only if WNG answers Q1–Q3 "resettable"): the petty-cash register set, `enquiry_payments`, and the payroll run/ledger/advance.

**Never in the reset:** anything in §18.

## 20. Foreign-Key / Cascade Risks

1. **Deleting `clients` or `departments` cascades into projects, tasks and employees.** These tables must be excluded, by name, from any reset script.
2. **Deleting a `project_enquiries` row cascades into 66 tables.** No reset may touch it. `clients` reaches 68 tables and `departments` 77, including `employees`.
3. **`technical_labours` deletion silently nulls 340 crew links** (SET NULL). Exclude it.
4. **`project_invoices` is NO ACTION**, and PO children become RESTRICT after migration #1. The reset must run child-first, and **before** migrating.
5. **`TRUNCATE` is unsafe here.** It bypasses FK checks only with `FOREIGN_KEY_CHECKS=0`, which would hide exactly these hazards. Use ordered `DELETE`, in a transaction, with before/after counts of the preservation set.
6. **Auto-increment:** do not reset counters on accounting tables. Document numbers and IDs must never be reused.

## 21. Data Export Requirements (and production confirmation queries)

**Before any reset:**
- Take a full database backup (§22).
- Also export the **preservation set** separately (logical dump of the §18 tables) and the **review set** (the Q1–Q3 tables). Even if reset, a copy stays retrievable for Finance.

**Read-only production confirmation**, to run in the release gate (replaces staging-only evidence for data questions):

```sql
-- Q-A preservation set counts (record before and after; must be identical)
SELECT 'project_enquiries',COUNT(*) FROM project_enquiries UNION ALL SELECT 'projects',COUNT(*) FROM projects
UNION ALL SELECT 'enquiry_tasks',COUNT(*) FROM enquiry_tasks UNION ALL SELECT 'employees',COUNT(*) FROM employees
UNION ALL SELECT 'clients',COUNT(*) FROM clients UNION ALL SELECT 'departments',COUNT(*) FROM departments
UNION ALL SELECT 'users',COUNT(*) FROM users UNION ALL SELECT 'task_budget_data',COUNT(*) FROM task_budget_data
UNION ALL SELECT 'quote_approvals',COUNT(*) FROM quote_approvals UNION ALL SELECT 'teams_members',COUNT(*) FROM teams_members
UNION ALL SELECT 'technical_labours',COUNT(*) FROM technical_labours;
-- Q-B finance volumes and provenance (decides Q1–Q3 with WNG)
SELECT 'payments',COUNT(*),MIN(created_at),MAX(created_at),SUM(TIME(created_at)='03:00:00') FROM payments
UNION ALL SELECT 'enquiry_payments',COUNT(*),MIN(created_at),MAX(created_at),SUM(verified_at IS NOT NULL) FROM enquiry_payments
UNION ALL SELECT 'project_invoices',COUNT(*),MIN(created_at),MAX(created_at),SUM(status='issued') FROM project_invoices
UNION ALL SELECT 'purchase_orders',COUNT(*),MIN(created_at),MAX(created_at),NULL FROM purchase_orders
UNION ALL SELECT 'bills',COUNT(*),MIN(created_at),MAX(created_at),NULL FROM bills
UNION ALL SELECT 'spend_vouchers',COUNT(*),MIN(created_at),MAX(created_at),NULL FROM spend_vouchers
UNION ALL SELECT 'journal_entries',COUNT(*),MIN(created_at),MAX(created_at),NULL FROM journal_entries
UNION ALL SELECT 'payroll_runs',COUNT(*),MIN(created_at),MAX(created_at),NULL FROM payroll_runs;
-- Q-C cost lines by origin (stock-originated costs stay out of the reset)
SELECT nature, source_type, COUNT(*) FROM cost_lines GROUP BY nature, source_type;
-- Q-D budget states (W7 finding)
SELECT status, COUNT(*) FROM task_budget_data GROUP BY status;
```

## 22. Backup Requirements

These are unchanged by DATA-1 and remain **mandatory**:
- A verified full logical backup, restore-tested, immediately before any reset or migration (Report 44 §20).
- The separate preservation-set and review-set exports (§21).
- The migration snapshot and code references (`60491b1` / `d24a3f7`).

A reset makes a backup *more* important: a reset is irreversible without one.

## 23. Revised Staging Requirement

**Answer depends on Q1–Q3:**
- **If WNG says the petty-cash register, client receipts and payroll run are test data (resettable):** choose **B. Reduced, preservation-focused validation is sufficient.**
  - After the reset, the HIGH/MEDIUM-risk migrations (#1 PO FKs, #12 invoice lines, #14 petty-cash ENUM and voucher backfill) run against **empty tables**, which is trivial.
  - The only Phase 2B migrations touching preserved data are **#18** (adds defaulted columns to `project_enquiries`) and the nullable-FK additions on `payroll_runs`, `salary_advance_requests` and `payroll_ledgers`. All are additive.
  - What remains to prove is that the **preservation set survives unchanged** and that clean W1–W7 transactions work. A rehearsal on **any** copy of production restored locally (for example, a production dump loaded into DDEV by an engineer with production access) proves that. It does not need the stale shared-host staging environment.
- **If any of Q1–Q3 is "preserve":** migration #14 (and #12 if invoices exist) runs on real preserved rows. **A. Full production-copy validation is still required for those tables**, as Reports 44 and 45 specified.

Option **C** (reset, then controlled production migration without a rehearsal) is **not** recommended. It is only as safe as the reset script, and that script must itself be rehearsed once on a copy.

## 24. Proposed Release Strategy (if Q1–Q3 resolve to "resettable")

1. **Backup:** verified full backup plus the preservation/review-set exports (§21–22).
2. **Rehearsal:** on a local copy of the production dump (DDEV `import-db` into a *separate* database, never `db_test`), run the whole sequence below. Record Q-A counts before and after.
3. **Preflight:** Report 44 P0–P10 plus Q-A–Q-D on production (read-only).
4. **Reset:** a reviewed, ordered-`DELETE` script for §19's confirmed candidate set only, in a transaction with Q-A equality asserted. **Before** migrations, because of RESTRICT after #1.
5. **Deploy backend:** code + 23 migrations via the pipeline (fast-forward `master`).
6. **Permissions:** `php artisan permissions:sync --dry-run`, then `permissions:sync` (B1).
7. **Regenerate:** `php artisan finance:project-budgets --dry-run`, then the real run (planned CostLines from the preserved budgets).
8. **Deploy frontend.**
9. **Validate:** Projects (Q-A equal; enquiries open; tasks/budgets/quotes load), Employees (Q-A equal; HR screens; user links), master relationships (clients ↔ projects, departments ↔ staff).
10. **Clean smoke transactions** for W1–W7 (Report 44 §22), on a designated test project, afterwards voided or reversed through normal controls.
11. **Project Costing:** planned lines match budget totals for sampled projects; the statement renders.
12. **W7 Employee integration:** blocked until Q4 is answered (otherwise only unbudgeted labour can be tested).

## 25. Validation Strategy

- **Preservation invariance:** Q-A counts identical before and after, plus spot checks. Sampled projects show the same job number, client, officer, tasks, budget total and quote. Sampled employees show the same department, manager and user link.
- **Referential integrity:** no orphans in the §18 tables. Same shape as the Report 44 P6 queries, applied to `enquiry_tasks → project_enquiries`, `employees → departments`, `project_enquiries → clients`.
- **Regeneration:** planned CostLine totals per project equal the budget totals.
- **Workflow:** the W1–W7 smoke tests on clean data.
- **Automated:** backend 1,432 tests, frontend 166, ENG-1 256/0, build. Already green on the branch.

## 26. Rollback Strategy

- **Before the reset:** abort. Nothing has changed.
- **After the reset, before migrations:** restore the full backup, or re-import only the reset tables from the review/transaction export.
- **After migrations, before real activity:** restore the full backup plus the previous code (Report 44 §24).
- **After real Finance activity on the new workflows:** fix forward only; never restore over real transactions without Finance sign-off.

A Finance reset is **irreversible without the backup**. That is the reason for §22.

## 27. WNG Decisions Still Required (narrow)

1. **Q1 — Imported petty-cash history.** Is the petty-cash register loaded into the ERP authoritative WNG history, or test data that may be reset?
   - `payments`: ~1,554 disbursements, Jan 2025 – Feb 2026.
   - Petty-cash ledger, top-ups, activity log, and the current float balance.
   - Petty-cash requisitions: ~22, Feb–Mar 2026.
2. **Q2 — Client receipts.** Are the ~84 verified client receipts (`enquiry_payments`, across ~82 projects, Mar–Sep 2026) real money received (preserve), or test entries (resettable)?
3. **Q3 — Payroll.** Are the payroll run of June 2026 and the salary advance real (preserve) or test (resettable)?
4. **Q4 — W7 budget state** (not a data question, but it gates W7 go-live). Internal budget approval was retired on 2026-07-07, so every budget stays `draft`. Which state counts as the "approved Project Budget" of W7-1/W7-2? For example:
   - the current saved budget of a project whose budget task is completed, or
   - the budget of a project whose quote is approved, or
   - another rule WNG defines.

   Until answered, W7 cannot record budgeted labour on real projects.

Confirmed without further question: Project Budgets and quotes are **preserved** project data (§11–12), `technical_labours` is **retained** (§14), and clients/departments/users are **never** reset.

## 28. Impact on Report 44

Report 44's verdict and findings stand as a record. Report 46 changes **only** its assumption that Finance history must be migrated.
- **Could shrink:** if Q1–Q3 resolve to "resettable", the migration-risk ratings for #1, #12 and #14 fall to LOW, because they would run on empty tables.
- **Still required:** B1–B3, the permission sync and the backup.
- **Two new release steps:** the controlled reset (§24.4) and `finance:project-budgets` (§24.7).
- **One new W7 go-live precondition:** Q4.

## 29. Impact on Report 45

Report 45's BLOCKED verdicts stand as a record. **If** Q1–Q3 resolve to "resettable", the stale shared-host staging environment is no longer the required venue. A rehearsal on a locally restored production copy is sufficient (§23-B). It still needs one production dump, taken by someone with production access. **If not**, Report 45's staging requirement stands unchanged.

## 30. Recommended Immediate Next Action

**WNG answers Q1–Q4 (§27).** Q1–Q3 decide between strategy B and the full staging requirement. Q4 decides whether W7 can go live with this release.

## 31. Final Verdict

### DATA-1 REQUIRES ADDITIONAL WNG DECISION

The preservation boundary is structurally confirmed:
- Projects and Employees plus their required dependencies can be isolated safely.
- No Finance reset can cascade into them, provided clients, departments, project enquiries and `technical_labours` are excluded.
- Budgets and quotes are preserved project data.

**What prevents confirming DATA-1:** the inspection found Finance data that looks **real rather than development/test**. That is the imported petty-cash register, verified client receipts, and a payroll run. DATA-1 excludes real data from reset, so WNG must classify these three items (Q1–Q3) before a reset set can be approved.

Separately, **Q4** must be answered for W7 to work on real budgets.

**Decision Register:** not updated. DATA-1 is not yet unambiguous (per the directive).

Nothing was reset, deployed, or migrated. No production or staging connection was made. W8 has not started.

---

# WNG Decision Resolution — Q1–Q4

**Appended 2026-09-28.** The analysis and the verdict above are preserved as issued.

| Question | WNG decision / resolution | Effect |
|---|---|---|
| **Q1** Imported petty-cash history | **RESET** | The petty-cash register, float balance and development-era petty-cash requisitions join the controlled reset set |
| **Q2** Existing `enquiry_payments` | **RESET** | Client receipts join the reset set. Projects, clients, quotes and the commercial basis stay protected |
| **Q3** Payroll run / ledger / salary advance | **RESET** | They join the reset set. Employee Records, salary history and all HR history stay protected |
| **Q4** W7 Project Budget authority | **CONFIRMED from repository state** | Authoritative Project Budget = the `task_budget_data` of the project's budget task, when that task is `completed`. This is the finalization signal since approval was retired (`EnquiryWorkflowService::validateTaskCompletion`). No new approval was introduced. Implemented in `ProjectBudgetAuthority`, shared by W6 and W7 (Report 47) |

**Result:**
- DATA-1 is **CONFIRMED** and recorded in the Decision Register.
- The staging requirement resolves to **§23 option B:** a reduced, preservation-focused rehearsal on a recent production copy, restorable locally. The stale shared staging environment is no longer mandatory.
- Nothing has been reset. The implementation, reset specification and remaining preconditions are in `47_PHASE_2B_DATA1_AND_W7_BUDGET_AUTHORITY_IMPLEMENTATION.md`.
