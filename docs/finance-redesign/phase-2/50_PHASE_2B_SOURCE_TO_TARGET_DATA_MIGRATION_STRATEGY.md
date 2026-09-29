# 50 — Phase 2B: Source-to-Target Data Migration Strategy

**Date:** 2026-09-28

**Supersedes:** the production migration-baseline assumption of Report 49 (see Report 49, *Database Identity Resolution*). Reports 44–49 stay as the record.

**Inputs:**
- operator-verified infrastructure facts;
- Reports 46–47 (DATA-1);
- source commit `5bf4ab3a67f74069fe35a0ae41aa9fce247e7256`;
- target branch `finance/critical-stabilization-fixes` at `3093a33`.

**Evidence produced for this report** (local DDEV, MariaDB 11.8, scratch databases only, since dropped):

1. The **source schema was rebuilt** by running the source commit's own migrations from empty, in an isolated worktree with its own autoloader.
2. The **target schema was rebuilt** by running all 607 target migrations from empty.
3. The **upgrade path was tested**: the target's migrations were applied on top of the rebuilt source schema.
4. The three schemas were **compared column by column**, including FKs and indexes.

**Boundary:** no production or source connection, no source data read, no live-target initialisation, no import, no reset, no worker, no deploy, no merge, no W8.

**Appendix:** `50A_SOURCE_TABLE_CLASSIFICATION.md` classifies all 243 source tables.

**Verdict:** **READY TO BUILD SOURCE-TO-TARGET MIGRATION TOOLING** (§29). Eight narrow WNG decisions (§26) configure the tooling and must be answered before the rehearsal can pass. They do not block building it.

---

## 1. Executive Summary

- **The release model is corrected.** It is **source ERP (`woodnork_erpsystem`) → clean redesigned ERP (`woodnork_erp`)**, not an in-place upgrade.
  - The target is empty (zero tables).
  - The source is live, has a Laravel ledger and has queue tables.
- **The schemas are closer than feared. All of this is proven, not inferred.**
  - The source runs `master` as of 2026-08-19. That is an **ancestor** of the release branch.
  - Of the source's 445 loaded migrations, **none was edited** afterwards.
  - The target adds **162** migrations and renames 2 mis-dated asset migrations.
  - For every table in both schemas, **the target has every source column**.
  - Every type change is a **widening or relaxation**, except on Finance tables DATA-1 excludes.
  - **No added column is NOT NULL without a default.**
- **DATA-1's core tables are structurally identical:** `projects`, `employees`, `users`, `clients`, `enquiry_tasks`, the budget, quote and version tables, and the team tables. `project_enquiries`, `project_elements`, `element_materials`, `departments`, `suppliers`, `library_materials` and the stores tables only **gain** columns.
- **A naive "clean schema + copy rows" would still be wrong.**
  - 4 of the 162 migrations **backfill preserved operational data**: enquiry delivery-date status, board custody, return kinds, buying units.
  - Migrations also **seed rows** into 14 tables, 8 of which are imported masters (9 if the chart of accounts is imported). Copying into a clean schema would skip the backfills and collide on IDs.
- **Recommended mechanism (§21): two stages.**
  1. **Transform.** A *copy* of the source is upgraded by the target's own migrations (the 162 pending), so every backfill runs exactly as designed.
  2. **Load.** A clean target is built from the full 607-migration chain. Allow-listed tables are copied in with **original IDs preserved**.
  - Proven locally: *source schema + 162 target migrations* gives **identical columns and indexes** to the clean chain. The only difference is 13 FK rule labels (RESTRICT vs NO ACTION), which InnoDB enforces identically.
  - **No imported table has an FK into excluded Finance history,** so a selective load needs no pruning logic.
- **Identifiers: preserve every original ID (§11).** The target is empty. `users` alone is referenced by 191 FK columns across 123 tables, and many references are unenforced integers, JSON-embedded IDs or polymorphic IDs that remapping would silently break. The overtime ledger's hash chain includes `employee_id`, so remapping would invalidate it.
- **Users and authentication.**
  - Password hashes are portable: framework-default hashing in both versions.
  - Neither version uses encrypted casts, so no imported value depends on the source `APP_KEY`.
  - Roles and user-role assignments are imported. Permissions are **regenerated** from the target's `RolePermissions` matrix through `permissions:sync`.
- **Budget authority and W7 are compatible.** `ProjectBudgetAuthority` reads only `enquiry_tasks` (`type='budget'`, `status='completed'`) and `task_budget_data`, which are identical in both schemas and keep their IDs. W7 takes rates from the budget's `labour_data` snapshot and needs only `employee_id` from Employee Records.
- **Finance:**
  - DATA-1's excluded history is **not imported** (14 tables).
  - The source never had `cost_lines` or journals. Those tables did not exist at the source commit.
  - Planned cost lines, permissions and reference Finance masters are **regenerated**.
  - Commitments, accruals, labour actuals, GL, receivables and petty-cash float **start fresh at cutover**.
- **Queue.** The clean chain creates `jobs`, `job_batches` and `failed_jobs`: proven, 607/607 in 92 s. The target needs the §18 cron drain. **The source ERP's queue is not touched.**
- **Files.** Both versions use `storage/app/{public,private}` and the same `public/storage` link. Preserved rows reference files in about 20 tables. Files must be copied and every reference validated, and the target needs `storage:link`.
- **A target-environment gap remains open.** `deploy.yml` has run `migrate --force` against `woodnork_erp` on every master push since 2026-09-07, yet the database has zero tables. The pipeline's migrate step is therefore not reaching or not able to write that database. **The target must be initialised by an attended operator step, not by the pipeline,** and the cause must be found (§22).

## 2. Infrastructure Discovery

| | Source ERP | Target ERP |
|---|---|---|
| Path | `/home/woodnork/public_html/system` | `/home/woodnork/erp-backend-master` |
| Code | `master` @ `5bf4ab3` (2026-08-19), Laravel 12.28.1 | release branch @ `3093a33` (Laravel 12.59 locally) |
| Database | `woodnork_erpsystem` | `woodnork_erp`: **zero tables** |
| Migration ledger | exists | none (empty database) |
| Queue | `database`; `jobs`, `failed_jobs` and `job_batches` exist | `database` configured; no tables yet |
| Storage link | `public/storage` linked | **not linked** |
| How it is deployed | not by `deploy.yml` (that pipeline has only ever targeted `~/erp-backend-master`, briefly `~/public_html/erp-backend-master`) | `deploy.yml` on master push (§22 gap) |

## 3. Source ERP

**Operational facts (operator-verified):**

| Item | Value |
|---|---|
| Projects | 731 |
| Employees | 93 |
| Project Enquiries | 1,387 |
| Project Budgets | 658 |
| Clients | 258 |
| Users | 65 |

**Code facts (repository):**
- **Commit.** `5bf4ab3` is `master` at 2026-08-19 ("Merge branch 'fix/annual-leave-accrual-proration'"). It is an ancestor of the release branch (`git merge-base --is-ancestor` true), and 242 commits separate them.
- **Migrations.** 445 load at the source commit. None changed content later: a basename-level blob comparison found 0 modified files.
- **The source chain cannot replay from empty.** Two mis-dated files (`2024_01_10_add_next_service_date_to_assets_table`, `2024_01_10_create_asset_service_logs_table`) sort before `create_assets_table`. The target fixed this in `0ea8eec` by renaming them to `2026_06_29_000010/11`; the contents are byte-identical. The live source ledger therefore holds the **old** names, which matters for Stage 1 (§21).

## 4. Target ERP

- **Database.** `woodnork_erp` is empty and needs a full initialisation.
- **Code.** The target loads **607** migrations (Report 49A).
- **Proven locally: the full chain from empty.**
  - **607 ran, 0 pending, in 92 s.**
  - It yields 310 tables, including `jobs`, `job_batches` and `failed_jobs`.
  - This is consistent with Report 47's green suite, which rebuilds `db_test` with `migrate:fresh`.
- **Rows seeded by migrations** on the empty chain (these matter for §11):

  | Table | Rows |
  |---|---|
  | `permissions` | 52 |
  | `production_defect_codes` | 5 |
  | `production_root_cause_codes` | 5 |
  | `hr_action_types` | 1 |
  | `attendance_work_schedules` | 1 |
  | `leave_types` | 5 |
  | `material_item_types` | 6 |
  | `units_of_measure` | 17 |
  | `chart_of_accounts` | 3 |
  | `finance_settings` | 2 |
  | `material_categories` | 67 |
  | `petty_cash_requisition_types` | 8 |
  | `expense_codes` | 109 |
  | `petty_cash_balances` | 1 |

## 5. DATA-1

**DATA-1 (Reports 46–47) remains authoritative.**

**Preserve:**
- **Primary:** Projects, Employee Records.
- **Project operational data:** Project Enquiries; Project Tasks and required children; Project Budgets with their lines, additions and versions; Quotes and the approved commercial basis; Deliverables; Elements and material planning; team and crew history.
- **Master data:** Clients, Departments, Users, Suppliers, material and reference masters, HR reference data.
- **Security:** transformed, not blindly copied (§13).
- **Technical Labour:** historical relationships only.

**Do not migrate:**
- petty-cash transactional history;
- client receipts (`enquiry_payments`);
- the payroll run and ledger;
- the salary advance;
- development/test cost lines and journals.

**Scope boundary this report makes explicit.** 139 source tables are neither named for preservation nor excluded (§26 D1): stores stock, logistics, production, assets, HR recruitment and on/offboarding, support tickets, CRM leads, the generic task module, notifications. **Default: import** (no silent loss), with a WNG opt-out.

## 6. Source Schema

**Rebuilt locally from `5bf4ab3`'s own migrations:**
- 243 tables;
- 3,248 columns;
- 475 FK column links;
- 86 unique indexes.

**Caveat.** This is the **migration-declared** source schema. Report 49 found that organically grown databases can lack FKs the migrations declare, or carry drift. The live `woodnork_erpsystem` must be compared against it before any import. The operator runs these **read-only** exports on the source (or on its dump):

```sql
SELECT migration, batch FROM migrations ORDER BY id;
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'woodnork_erpsystem';
SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = 'woodnork_erpsystem' AND REFERENCED_TABLE_NAME IS NOT NULL;
SHOW TABLES;   -- count with this; information_schema.TABLES has under-reported on MariaDB before
```

**Pass condition:**
- The ledger equals the 445 source migrations, allowing for the two old asset names.
- The columns equal the rebuilt schema.
- **Missing FKs are listed.** They do not block, because §23's orphan scan handles them, but they explain orphans.
- Any other drift is reconciled in Stage 1 **before** the target migrations run.

## 7. Target Schema

**Clean chain:** 310 tables, 4,503 columns, 753 FK column links.

**Source → target comparison:**

| Category | Count | Notes |
|---|---|---|
| Tables in both | 241 | **199 identical**; 42 differ |
| Source only | 2 | `payment_methods` (unified into `payment_sources`) and `petty_cash_disbursements` (renamed `payments`). Both excluded Finance |
| Target only | 69 | Finance redesign (cost lines, journals, payments, invoices, vouchers, periods, masters), W6/W7, Design and Printing, stores counts and returns, PO amendments and corrections |
| Differing tables with **removed** source columns | 2 | `bill_payments.payment_method_id` and `petty_cash_top_ups.transaction_code`. Both Finance (excluded or pending decision) |
| NOT NULL columns added without a default | **0** | |
| Narrowing changes | 1 | `petty_cash_top_ups.payment_method` enum. Excluded |

**Upgrade-path proof:** the rebuilt source schema plus the target's 162 migrations gives **607 ran / 0 pending**.

| Compared with the clean chain | Result |
|---|---|
| Columns (name, type, nullability, default) | **0 differences** |
| Indexes (columns and uniqueness) | **0 differences** |
| FKs | Same columns and targets; 13 delete rules labelled `RESTRICT` vs `NO ACTION`. **Equivalent in InnoDB**, where both check immediately |

## 8. Source→Target Entity Map

"Same structure?" is from the database-level comparison in §7. All tables keep their **original IDs** (§11).

| Entity | Source → Target | Same structure? | Transformation | Key FK dependencies | Order | Validation |
|---|---|---|---|---|---|---|
| **Clients** | `clients` → same; `client_interactions`, `public_leads` | Identical | None | — | 1 (roots) | 258; checksum over id, name, contact fields |
| **Departments** | `departments` → same | +`labour_classification` (NULL = indirect) | None. Classification is a W7 input (§26 D6) | `manager_id`→employees (**cycle**) | 1 | Count; managers resolve |
| **Employees** | `employees` → same | Identical | None | `department_id`, `manager_id` (self), ← `users.employee_id` (**cycle**) | 1 | 93; checksum; every `department_id` resolves |
| **Users** | `users` → same | Identical | None. Hashes copied, never printed | `department_id`, `employee_id` | 1 | 65; logins (§12) |
| **Roles** | `roles`, `model_has_roles` → same | Identical | Import names and assignments; **not** permissions (§13) | `role_id`; `model_id`=users.id | 2 | Assignments per role equal |
| **Project Enquiries** | `project_enquiries` → same | +12 nullable/defaulted columns | **Stage 1** backfills `delivery_date_status`/`delivery_date_tbc_since` (`2026_08_23_120000`). `financial_closure_status` defaults `open` (§26 D5) | `client_id`, `department_id`, 4× users | 3 | 1,387; status distribution equal; backfill rule holds |
| **Projects** | `projects` → same | Identical | None | `enquiry_id` (CASCADE) | 4 | 731; every `enquiry_id` resolves |
| **Project Tasks** | `enquiry_tasks`, `enquiry_task_user`, `task_assignment_history` | Identical | None | `project_enquiry_id`, `department_id`, users | 4 | Per-enquiry task counts and statuses equal |
| **Project Budgets** | `task_budget_data` | Identical | None | `enquiry_task_id` | 5 | 658; authority count (§14) |
| **Budget lines** | inside `task_budget_data` JSON (`materials_data`, `labour_data`, `expenses_data`, `logistics_data`) | Identical | None. JSON is copied byte-for-byte | embedded element `persistent_id`s | 5 | JSON checksum equal |
| **Budget additions** | `budget_additions` | Identical | None. Historical only: the target deleted the model and nothing reads the table | budget, element, material | 6 | Count |
| **Budget versions / approvals** | `budget_versions`, `budget_approvals` | Identical | None | budget, `material_versions` | 6 | Counts |
| **Quotes** | `task_quote_data`, `quote_versions`, `quote_approvals` | +`excel_quote_extraction` (nullable); rest identical | None | task. `quote_approvals.task_id`/`enquiry_id` have **no FK**, so an orphan scan is required | 5–6 | Counts; orphan scan |
| **Deliverables** | `project_deliverables`, `deliverables_blueprints` | Identical | None | `enquiry_id` | 4 | Counts |
| **Elements / material planning** | `task_materials_data`, `project_elements`, `element_materials`, `material_versions`, `element_templates`, `element_template_materials`, `element_types` | `project_elements` +3 columns and widened; `element_materials` +`source_metadata`; others identical | None | `project_element_id` (known orphan risk, Report 46) | 5–6 | Counts; orphan scan |
| **Project team / crew** | `teams_tasks`, `teams_members`, `teams_activity_logs`, `team_categories`, `team_types`, `team_category_types` | Identical | None | `technical_labour_id` (SET NULL) | 6 | Counts |
| **Task operational children** | site surveys, design assets and requirements, logistics tasks and children, setup and setdown tasks with issues and photos, handover surveys, archival reports and items, production data and children | Identical, except `design_assets` (+2 columns, relaxed) | None | `enquiry_task_id`, `project_id` | 6–7 | Counts; files (§19) |
| **Suppliers** | `suppliers` | +7 tax-identity columns (nullable) | None. KRA PIN and VAT status are captured later in the UI | — | 1 | Count |
| **Materials / reference masters** | `library_materials`, `material_categories`, `material_item_types`, `units_of_measure`, `material_uom_conversions`, `workstations`, `material_workstations` | Additive or widened | **Stage 1** adds packaging UoMs and `Uncategorized`; Stage 2 replaces the target's seeded rows (§11) | categories, UoM | 2 | Counts equal to **staging** |
| **Stores stock** | `stocks`, `inventory_logs`, `inventory_lots`, `inventory_serial_items`, `boards`, `board_*` | `inventory_logs` +10, `boards` +14 | **Stage 1** backfills `return_kind`, board custody and `uom_id` | materials, projects | 7 | Stock quantity per material equal |
| **Technical Labour** | `technical_labours` | Identical | None. History only (§15) | `employee_id` | 2 | Count; overtime chain (§23) |

The full list of all 243 tables, including HR history, logistics, production and assets, is in **50A**.

## 9. Project Preservation Graph

**Root:** `project_enquiries` (1,387), with `projects` (731) hanging from it by `enquiry_id` (CASCADE). The 656 enquiries that never became projects are still preserved: the enquiry pipeline is project operational data.

| Aspect | Required source records |
|---|---|
| Identity | `project_enquiries` (enquiry number, job number, title, dates), `projects` |
| Client | `clients` (+ `client_interactions`) |
| Project Officer / ownership | `project_enquiries.project_officer_id`, `created_by` → `users`; `departments` |
| Workflow state | `project_enquiries.status` (16-state enum, identical), `projects.status` (identical), `enquiry_tasks.status`/`type`, `enquiry_task_user`, `task_assignment_history`, `governance_audit_logs` |
| Commercial basis | `task_quote_data`, `quote_versions`, `quote_approvals`, `project_enquiries.quote_approved_by`/`quote_waived_by` |
| Budget | `task_budget_data` (with JSON lines), `budget_versions`, `budget_approvals`, `budget_additions` |
| Tasks | `enquiry_tasks` and per-type data (`task_materials_data`, `task_procurement_data`, `task_production_data`) |
| Team | `teams_tasks`, `teams_members`, `teams_activity_logs` |
| Deliverables | `project_deliverables` |
| Materials / elements | `project_elements`, `element_materials`, `material_versions`, stores issues against projects (`inventory_logs`, `boards`) |
| Execution history | site surveys, designs, logistics, setup/setdown, handover, archival, production, work orders, NCRs |

**Left behind (DATA-1):**
- `enquiry_payments` (Q2), even though it references enquiries.
- Petty-cash rows carrying `project_id`/`enquiry_id` (Q1).
- POs, GRNs and bills: **pending §26 D2.**

## 10. Employee Preservation Graph

**Root:** `employees` (93). **Keep IDs**, because `users.employee_id`, the overtime hash chain and unenforced references depend on them.

| Aspect | Required source records |
|---|---|
| Identity | `employees` |
| User linkage | `users.employee_id` ↔ `employees` (a cycle; the load order handles it, §21) |
| Department | `departments` (also `manager_id` → employees) |
| Status and job information | `employees` columns (status, type, termination fields) |
| Salary master | `employee_salary_histories` (master data; **not** payroll transactions) |
| HR history | `hr_actions` (+attachments, `hr_action_types`), `disciplinary_*`, `performance_reviews`, `profile_update_requests`, `grievances`, `incidents`, `hr_audit_logs` |
| Leave and attendance | `leave_types`, `leave_requests`, `leave_handovers`, `leave_balance_adjustments`, `attendance_*` (records, schedules, assignments, holidays, device logs) |
| Overtime | `ot_entries`, `ot_flags`, `compensations`, `ledger_entries` (**hash chain**) |
| Documents | `employee_documents`, `employee_certifications`, `employee_skills`, `employees.profile_photo_path` (files, §19) |
| Lifecycle | onboarding and offboarding cases and their children (**no FKs**, orphan scan), recruitment (`hr_candidates`, `hr_job_postings`, …) |
| Other | `drivers` (employee FK), `technical_labours.employee_id` |

**Left behind:** `payroll_runs`, `payroll_ledgers`, `payslips` and `salary_advance_requests`, even though they reference employees. `payroll_tax_bands` and `payroll_variables` are statutory reference data: import them, then diff against the target's `PayrollSeeder` during rehearsal.

## 11. Identifier Strategy

### PRESERVE ORIGINAL ID — every imported table

**Why:**
- The target is empty, so there are no collisions except the seeded rows handled below.
- The graph is dense. `users` is referenced by 191 FK columns across 123 source tables, and `employees` ↔ `departments` ↔ `users` form cycles.
- Many references are **not FK-enforced**, so a remap would miss them silently:
  - integer columns with no FK: `quote_approvals.task_id`, `requisition_items.project_enquiry_id`/`budget_*_id`, `hr_onboarding_cases.employee_id`, `production_elements.material_id`, …;
  - polymorphic `model_id`/`loggable_id`/`taskable_id`/`entity_id`;
  - IDs **embedded in JSON** (budget and material element `persistent_id`s, notification payloads).
- `ledger_entries.chain_hash` covers `employee_id`, `technical_labour_id` and `occurred_at` (app timezone, `Africa/Nairobi` in both versions). Any remap breaks the chain.
- **Never rely on insertion order.** Rows are inserted with their explicit `id`, then each auto-increment counter is set above the table's maximum.

**Seeded-row collisions (the only REMAP-like case).**
- Eight imported masters (nine with the chart of accounts) are seeded by target migrations.
- **Stage 1 dissolves this.** The staging database gets those seed and backfill migrations applied *on top of* the source rows, so IDs are consistent and extensions (packaging UoMs, `Uncategorized`) take the next free IDs.
- Stage 2 then **replaces** the clean target's seeded rows in those tables with the staging rows: truncate, then insert with IDs. It never merges.
- Target-only seeded Finance masters (`expense_codes`, `petty_cash_requisition_types`, `finance_settings`) come from the same migrations in both databases. They are verified equal, then either copy is kept.

**Mapping tables:** none are needed. If WNG asks for deduplication (for example duplicate clients), use an explicit `import_id_map(entity, source_id, target_id, reason)` applied before load and reported line by line. That is out of scope unless requested.

## 12. Users/Authentication

| Question | Finding |
|---|---|
| Hash compatibility | **Compatible.** Neither version configures hashing (`config/hashing.php` is absent), so both use the framework default (bcrypt). Hashes are copied as opaque strings, never logged or printed. `APP_KEY` plays no part in password verification |
| Encrypted data | **None.** Neither version has encrypted casts or `Crypt::` use, so the target can keep its own `APP_KEY` |
| Preserve user IDs? | **Yes.** 191 FK columns across 123 tables, `model_has_roles.model_id`, `users.employee_id`, and audit `user_id`s depend on them |
| Employee–user relationship | By ID (`users.employee_id` → `employees.id`), so it is kept by ID preservation |
| Sessions, tokens, resets | **Not migrated** (`sessions`, `personal_access_tokens`, `password_reset_tokens`, `user_device_tokens`). Every user signs in again after cutover, and push devices re-register |
| Validation | Imported user count equals 65. At UAT, a named sample of users logs in on the rehearsal target with their own passwords. No hash is ever compared outside `Hash::check` |

## 13. Roles/Permissions

**Source:**
- Roles and permissions come from `RoleAndPermissionSeeder` at `5bf4ab3` (16 role names seeded: Accounts, Admin, Client Service, Costing, Designer, Employee, HR, Logistics, Manager, Procurement, Procurement Officer, Production, Project Manager, Project Officer, Stores, Super Admin), plus ad-hoc permission migrations.
- The `RolePermissions` matrix did not exist yet.

**Target:**
- The canonical `App\Constants\RolePermissions` matrix covers 14 roles.
- `Super Admin` is granted everything by `Gate::before` on the **role name**.
- `Procurement Officer` is still referenced in code but is not in the matrix.
- `permissions:sync` is additive, fails on unknown roles, and supports `--dry-run`/`--prune`.

**Plan:**
1. **Import** `roles` (id, name, guard) and `model_has_roles`, preserving IDs. Names drive `hasRole()` checks, so they must survive exactly.
2. **Do not import** `permissions` or `role_has_permissions`. Obsolete Finance permissions are not carried over.
3. **Regenerate:** run `RoleAndPermissionSeeder` (target), then `permissions:sync --dry-run`, review, then `permissions:sync`.
4. **`model_has_permissions`** (direct user grants): import only rows whose permission **name** exists in the target, re-keyed by name. Report every dropped grant.
5. **Roles in the source that are not in the matrix** (at least `Procurement Officer`, plus any created by hand in production) need a WNG mapping (§26 D4). Until then they are kept as names with no matrix permissions.

## 14. Project Budget Authority

- **`ProjectBudgetAuthority` (Report 47)** reads the budget as the enquiry's `enquiry_tasks` row with `type='budget'`, is **authoritative only when that task is `completed`**, and uses its `task_budget_data`. `task_budget_data.status` is explicitly not used. There is no `approved` dependency.
- **Both tables are identical in both schemas and keep their IDs**, so the authority resolves identically after import. Nothing reverts to `status='approved'`.
- **Validation:**
  - On the source copy, count enquiries with a completed budget task that has budget data.
  - On the target, count budgets `ProjectBudgetAuthority` resolves.
  - **The two must be equal.**
  - Then run `finance:project-budgets --dry-run`, which walks every `task_budget_data` row.

## 15. W7 Labour Compatibility

| Stage in the W7 chain | What it needs | Where it comes from |
|---|---|---|
| Project Budget Labour Allocation | The completed budget's `labour_data` JSON (`unitRate` snapshot) | Imported unchanged |
| → Employee Record | `project_labour_actuals.employee_id` | Imported `employees`, same IDs |
| → Labour Record | `project_labour_actuals` | Target-only table: **starts fresh** |
| → Project Officer Verify → Finance Verify | Permissions | Regenerated from the matrix (target migrations `…w6_project_costing_permissions`, `…w7_labour_cost_permissions`) |
| → Actual CostLine | `cost_lines` | Target-only: **created by W7 going forward** |

- **The rate never derives from payroll salary** (`ProjectLabourActualService`). No payroll data is needed.
- **Technical and casual labour are not restored as an active master.** `technical_labours` is imported only so the historical references in `teams_members`, `ot_entries`, `compensations` and `ledger_entries` resolve.
- **`departments.labour_classification`** is NULL on import, which means **indirect**. WNG classifies the direct-labour departments in the existing UI (`LabourClassificationController`), as a cutover step (§26 D6).

## 16. Finance Data Excluded

**Not imported (DATA-1 Q1–Q3 and schema obsolescence):**
- `petty_cash_disbursements` (target: `payments`) and `petty_cash_disbursement_allocations`;
- `petty_cash_requisitions` and `petty_cash_requisition_items`;
- `petty_cash_top_ups`, `petty_cash_ledger_entries`, `petty_cash_activity_logs`, `petty_cash_balances`;
- `enquiry_payments`;
- `payroll_runs`, `payroll_ledgers`, `payslips`;
- `salary_advance_requests`;
- `payment_methods` (dropped in the target).

**Not present in the source at all:**
- `cost_lines`, `journal_entries`/`journal_lines`, `project_invoices`, `client_receipts` and the other 69 target-only tables.
- The "development/test cost lines and journals" of DATA-1 lived in the local development database, **not** in `woodnork_erpsystem`.

**Pending WNG decision:**
- `purchase_orders` (+items), `goods_receipt_notes` (+items), `bills` and `bill_payments`. These are Report 47 `CONFIRM_ON_PRODUCTION`. Import only if they are real (§26 D2).
- `chart_of_accounts` (§26 D3).

**The audit trail of excluded data** comes from three things:
- the source dump, retained;
- per-table source counts, recorded in the reconciliation report;
- WNG sign-off.

Audit and log rows that point at excluded records keep those IDs as history (`governance_audit_logs.model_id`, `action_logs.loggable_*`, `system_events.entity_*`).

## 17. Imported vs Regenerated Data

| Data | Treatment | Mechanism |
|---|---|---|
| DATA-1 and operational tables (50A) | **IMPORTED** | Stage 2 load from staging, IDs preserved |
| Planned cost lines from Project Budgets | **REGENERATED** | `finance:project-budgets` (idempotent). Scope: all budgets vs open projects (§26 D5) |
| Project Costing projections | **REGENERATED on read** | Derived from cost lines (`CostAccountService`); the target schema has no stored projection table, so there is nothing to import |
| Permissions and role grants | **REGENERATED** | `RoleAndPermissionSeeder` + `permissions:sync` |
| Finance reference masters (expense codes, periods, payment sources, VAT/WHT, cost centres, causes, activities, payee types, requisition types, finance settings) | **REGENERATED** | Target migrations plus the target-only parts of `ReferenceDataSeeder` (`FinanceReferenceSeeder`, `PayrollSeeder` diff, `AssetCategorySeeder`). **Not** `DepartmentSeeder`, `MaterialCategorySeeder`, `Team*Seeder`, `WorkstationSeeder` or `HRActionTypeSeeder`: those masters are source-authoritative |
| Commitments (PO, petty cash) | **STARTS FRESH AT CUTOVER** | Listeners record them from new approvals. If D2 imports POs, historical approved POs get **no** commitments: no backfill command exists, and none is proposed without a decision |
| Accruals (GRN) | **STARTS FRESH** | Same reasoning |
| Labour actuals and labour cost lines (W7) | **STARTS FRESH** | Target-only |
| GL / journals | **STARTS FRESH** | Opening balances are an accountant input (§26 D7) |
| Petty-cash float | **STARTS FRESH** | `PettyCashBalance::current()` creates it at 0.00. The opening float comes from a physical count (D7) |
| Receivables (invoices, receipts), payroll runs | **STARTS FRESH** | DATA-1 Q2/Q3 |

## 18. Queue Infrastructure

- **Tables.** The clean chain creates `jobs`, `job_batches` and `failed_jobs` through the canonical `0001_01_01_000002_create_jobs_table` (proven). No `queue:table`, no duplicate migration.
- **The source ERP's queue is not touched.**

**Worker: a cron drain.** It is the smallest reliable mechanism on cPanel shared hosting, where there is no supervisor (Report 48 §11, Report 49 §13). One crontab line on the target:

```cron
* * * * * cd /home/woodnork/erp-backend-master && flock -n /tmp/wng-erp-queue.lock php artisan queue:work database --queue=stores-finance,default --stop-when-empty --max-time=55 --timeout=50 --sleep=3 --tries=3 >> storage/logs/queue-cron.log 2>&1
```

| Parameter | Value | Reason |
|---|---|---|
| Frequency | Every minute | Latency of at most about 1 minute; the listeners were designed to "land a moment later" |
| Overlap protection | `flock -n` | A second invocation exits immediately while one runs |
| `--max-time=55` | | Each run ends inside its minute |
| `--timeout=50` | | Per-job kill; must stay below `retry_after` (`DB_QUEUE_RETRY_AFTER`, 1200 in `.env.example`, default 90) |
| `--tries=3` | | Default for jobs without their own; the Finance listeners declare `$tries=3`, `$backoff=30` themselves |
| Queues | `stores-finance,default` | Covers both queues in use |
| Deploys | No `queue:restart` needed | Each run is a new process on the newly deployed code. While `php artisan down` is active, the worker does not process jobs, which suits cutover |
| Failed jobs | `failed_jobs` (driver `database-uuids`) | Watch `queue:failed` daily and `storage/logs/queue-cron.log`. Automated alerting would need a `schedule:run` cron, which is not present; decision D8 |
| PHP binary | Confirm on host | `which php`: the cPanel CLI path may differ from the web PHP |

**Enable the cron only after** the target is initialised, and in the same window. Once `jobs` exists, dispatches queue rather than fail.

**Open target-environment gap.** `deploy.yml`'s `migrate --force` has not created even a ledger in `woodnork_erp`. Candidates: the pipeline's SSH step never reaches artisan, the checkout's DB credentials fail, or the user lacks `CREATE`. **Until this is explained, the target is initialised by an attended operator command, and the next master push is watched in the Actions log.**

## 19. File/Attachment Migration

**Configuration (identical in both versions):**
- disks `local` = `storage/app/private` and `public` = `storage/app/public`;
- link `public/storage` → `storage/app/public`.

The source reports the link present. The **target does not have it**, so it needs `php artisan storage:link`.

**Preserved rows that reference files:**

| Area | Columns |
|---|---|
| Employees and HR | `employees.profile_photo_path`, `employee_documents.file_path`, `hr_action_attachments.file_path`, `hr_candidate_documents.file_path`, `hr_offboarding_attachments.file_path`, `leave_requests.attachment_path`, `disciplinary_cases.attachments`, `grievances.attachments`, `incidents.evidence_paths` |
| Projects | `design_assets.file_path`, `site_surveys.images`/`survey_photos`/signatures, `setup_task_photos.path`, `setdown_task_photos.path`, `handover_surveys.evidence_files`, `archival_reports.attachments`/signature/budget file, `task_quote_data.excel_quote_file`, `task_attachments.file_path` |
| Operations | `assets.image_path`, `production_ncrs.image_path`, `work_order_*_evidence.file_path`, `vehicles.photo_*`, `vehicle_maintenance_logs.*_photos`, `support_ticket_attachments.path` |

Several of these are **JSON arrays** of paths.

**Plan:**
1. **Copy** the source `storage/app/public` and `storage/app/private` into the target `storage/app` with `rsync -a`, **read-only on the source**. The rehearsal copies to the rehearsal location. Record size and file count.
2. **Create the link:** `php artisan storage:link` on the target.
3. **Validate references with a new read-only command.** For every file column in imported rows (JSON arrays expanded), check the file exists on the target disk. Report:
   - missing files, split into missing-on-source-too and lost-in-copy;
   - absolute URLs (values starting `http`) that would still point at `www.woodnorkgreen.co.ke`. If any exist, rewriting them is a named, reviewed transform.
4. **Cutover:** a final `rsync` delta after the source freeze (§24).

## 20. Active vs Completed Projects

**Vocabulary is identical in both versions:**
- `projects.status`: `planning`, `in_progress`, `completed`, `closed`, `cancelled`;
- `project_enquiries.status`: 16 states, from `client_registered` to `cancelled`.

**Treatment:**
- **All 731 projects and 1,387 enquiries are imported identically.** None is deleted or truncated by state.
- **Two questions need WNG input** (§26 D5), and this report does not invent the answers:
  1. **Regeneration scope.** Should planned cost lines be projected for all 658 budgets (the command's default), or only for open projects? Historical planned lines are analytical noise without actuals, which start fresh.
  2. **Financial closure.** After import, `financial_closure_status` defaults to `open` for every project. Should completed, closed and cancelled projects be bulk-marked closed at cutover? That would be a recorded step with an actor and a reason.
- **Operator query for the distribution** (read-only):

```sql
SELECT status, COUNT(*) FROM projects GROUP BY status;
SELECT status, COUNT(*) FROM project_enquiries GROUP BY status;
SELECT et.status, COUNT(*) FROM enquiry_tasks et WHERE et.type = 'budget' GROUP BY et.status;
```

## 21. Migration Tooling

### Stage 1 — Transform (a source *copy* becomes the staging database)

1. Restore the source dump into `woodnork_erp_staging` on the rehearsal host. The source itself is never touched.
2. **Schema check against §6.** Reconcile any drift.
3. **Ledger reconciliation, the only ledger write, marker-verified.** The copy's ledger holds `2024_01_10_*` for the two asset migrations. Verify `assets.next_service_date` and `asset_service_logs` exist (Report 49 tooling), then insert ledger rows for `2026_06_29_000010_add_next_service_date_to_assets_table` and `…_000011_create_asset_service_logs_table`. Without this, `migrate` would try to re-create them and fail.
4. `migrate:status` must list **exactly the 162** target-only migrations as pending.
5. `php artisan migrate`, using target code configured for staging. The backfills and seeds run on real data, exactly as designed.
6. **Schema-equivalence gate** against the clean chain: columns, indexes, FK targets. **It must be exact**, allowing only the RESTRICT/NO ACTION labels.

### Stage 2 — Load (clean target ← staging)

**New Artisan command in the target codebase** (to build): `migration:import-source`, reading a second, read-only DB connection (`source_staging`).

**It is driven by a reviewed plan file**, generated from 50A and edited by the D1–D4 decisions. The plan gives each table one mode:

| Mode | Meaning |
|---|---|
| `import` | Copy all rows, IDs preserved |
| `replace-seeded` | Truncate the clean target's seeded rows, then import |
| `import-mapped` | Only `model_has_permissions`, re-keyed by permission name |
| `skip` | Not copied |
| `exclude` | Not copied; excluded by DATA-1 |
| `regenerate` | Built on the target by a named command |

**Command options:**

| Option | Behaviour |
|---|---|
| `--dry-run` | Default. Writes nothing. Reports per-table source counts, the schema gate result, planned actions and a **pre-load orphan scan** over every target FK and the known unenforced references |
| `--execute` | Loads. Refuses unless the target has a clean-chain ledger (607 ran, 0 pending) and every `import` table is empty (or seeded-only, for `replace-seeded`) |
| `--table=` | Per-table runs, for resumability |
| `--validate` | Post-load reconciliation (§23) only |

**How loading works:**
- **Transactions are per table.** Rows move in chunked `INSERT` statements with explicit `id`.
- `FOREIGN_KEY_CHECKS=0` is set **for the session only**, so the `employees`↔`departments`↔`users` cycles and self-references load without ordering tricks.
- **After the load, an FK-integrity scan runs over every imported table and fails closed.**
- Auto-increment counters are set above each table's maximum.

**Idempotent and fail-closed:**
- A re-run on a loaded table is refused.
- Recovery is to drop and rebuild the rehearsal target from the clean chain, which takes 92 s.

**Output:** a JSON and Markdown report in `storage/app/imports/<run-id>/`.
- No row content.
- **Password columns are never read into logs.**
- `users` is copied table-to-table, never printed.

**Companion commands (to build, read-only):**
- `migration:verify-files` (§19);
- `migration:verify-overtime-chain`, which recomputes `LedgerEntry::generateHash` along each chain and compares it with the stored `chain_hash`.

**Why not a plain SQL dump and import:** the schemas differ (162 migrations). The backfills must run on real rows, and exclusions must be explicit. A dump and import would either skip the transforms or carry excluded Finance history and source drift into the target.

## 22. Rehearsal Environment

**Isolated databases** on a non-production host: local DDEV, or a separate cPanel database the target app never points to.

| Database | Role |
|---|---|
| `woodnork_erpsystem_copy` | Restored source dump; read-only after restore |
| `woodnork_erp_staging` | Stage 1 |
| `woodnork_erp_rehearsal` | Stage 2 target, built by the clean chain |

**Parity requirements:**
- **Engine and version** equal to the production host (operator to confirm MySQL or MariaDB, and version). The 64-character identifier limit has bitten MariaDB before.
- **PHP 8.4**, the version `deploy.yml` uses.
- **The DDEV always-on worker disabled** during queue tests.

**Rehearsal sequence:**

1. Restore the source copy and record the source counts (§23).
2. Stage 1 (§21), including the schema gate.
3. **Initialise the target:** clean chain, then `migrate:status` shows 607 ran and 0 pending, and the queue tables are verified.
4. Run `RoleAndPermissionSeeder` and `permissions:sync --dry-run`.
5. Import: `migration:import-source --dry-run`, review, then `--execute`.
6. **Validate** (§23): counts, checksums, FK scan, authority count, overtime chain.
7. Run `permissions:sync` and the target-only reference seeders (with diffs).
8. **Regenerate:** `finance:project-budgets --dry-run`, then the real run (D5 scope).
9. **Files:** `rsync`, `storage:link`, `migration:verify-files`.
10. **Queue:** the §18 cron, or an equivalent loop, then Report 48 §10 R1–R6b and Report 49 §19 Q1–Q6.
11. **Smoke tests:** W1–W7 (Report 44/45 list).
12. **Logins:** a named sample of users on real accounts; role-gated screens per role.
13. **Interfaces:** Project and Employee screens for 5 named projects and 5 named employees, compared with the source UI side by side.
14. **Timings** for every step, to size the cutover window.

**Pass means** every §23 control is green, W1–W7 pass, and WNG signs off UAT. **Only then** is `woodnork_erp` initialised for real.

## 23. Reconciliation Controls

| Control | Rule |
|---|---|
| Headline counts | Source must equal target: Projects **731**, Employees **93**, Project Enquiries **1,387**, Project Budgets **658**, Clients **258**, Users **65**. These are verified against the operator's live numbers **and** the copy's |
| Every `import` table | Target count = staging count. Staging count = source count, **except** rows added by Stage 1 migrations (seeds such as packaging UoMs and `Uncategorized`), which are listed by name |
| Differences | **None may be silent.** Each is a named rule (a seeded addition, a WNG-approved dedup, or an orphan row excluded with its ID listed) |
| Content | A per-table checksum over the primary key and business columns for projects, enquiries, budgets (including JSON), quotes, employees, clients and users, taken source copy → staging → target. Stage 1 transforms change only the named backfilled columns |
| Referential integrity | Zero orphans on every target FK for imported tables. **Pre-existing** source orphans (e.g. `element_materials`) are reported, and are either excluded with WNG approval or loaded as-is behind the scan's explicit allow-list |
| Budget authority | Completed budget tasks with data (source copy) = budgets resolved by `ProjectBudgetAuthority` (target) |
| Overtime ledger | `migration:verify-overtime-chain`: 100% of entries verify |
| Security | Users per role equal; `permissions:sync --dry-run` is clean after sync; every dropped direct grant is listed |
| Files | Every file reference resolves, or appears on the missing-on-source-too list |
| Excluded data | Source counts for the 14 excluded tables (and the D2/D3 tables if excluded) are recorded in the report and signed off by WNG |

## 24. Cutover Strategy

**Pre-cutover:**
1. A final **source backup**: `mysqldump --single-transaction --routines --triggers woodnork_erpsystem` plus a `storage/app` archive, verified by a scratch restore.
2. A **source freeze.** The source becomes read-only for users: WNG announces the window, and `php artisan down` is run on the **source app only with WNG approval**. The source *database* is never written.
3. Final source counts.
4. **A target backup**, even though it is empty; it records the baseline.
5. **Target initialisation by an attended operator step:**
   - clean chain;
   - `migrate:status` shows 607/0;
   - queue tables verified;
   - `storage:link`;
   - `RoleAndPermissionSeeder`.

**Data migration:**
1. Stage 1 on a fresh copy of the frozen source.
2. Stage 2 load.
3. Files: `rsync` delta.
4. **Mapping and integrity validation (§23).** This is a hard gate.

**Regeneration:**
1. `permissions:sync`.
2. Target-only reference seeders.
3. `finance:project-budgets` (D5 scope).
4. Department labour classification (D6).
5. Opening float and GL opening inputs (D7).

**Validation:**
- Projects, Employees, logins and roles;
- W1–W7 smoke tests;
- a Project Costing view on sample projects;
- queue: enable the §18 cron, then Q1/Q2/Q5;
- permissions.

**Switchover:** only after PASS and WNG acceptance. Point users and the URL at the target. The source stays running, read-only, for an agreed acceptance period.

## 25. Rollback Strategy

- **The source is never modified**, so rollback means returning users to the source ERP and lifting its freeze.
- **Before switchover:** abandon or re-initialise the target. It can be rebuilt from the clean chain in about 2 minutes.
- **After switchover, inside the acceptance period:** anything entered in the target would be lost on rollback. Define the rule in advance (D8): either a short acceptance window with manual re-entry, or dual-entry of critical records. There is **no automated reverse migration**, and none is proposed.
- **Retained artefacts:** the source dump and storage archive, the staging database, the import reports, and the target backup taken just before switchover.

## 26. Remaining WNG Decisions

These are narrow. They configure the plan file and must be answered **before the rehearsal can PASS**; none blocks building the tooling.

| # | Decision | Default if WNG agrees |
|---|---|---|
| **D1** | The 139 non-DATA-1 operational tables (stores stock, logistics, production and work orders, assets register, HR recruitment and on/offboarding, support tickets, CRM leads, generic tasks and workflows, notifications): carry them or opt some out? | Import all (no silent loss); WNG lists opt-outs |
| **D2** | Source `purchase_orders`/items, `goods_receipt_notes`/items, `bills`, `bill_payments`: real history or development/test? If imported, historical commitments and accruals are **not** backfilled | Show WNG the counts from the copy; exclude if dev/test |
| **D3** | `chart_of_accounts`: keep the source chart, or start from the target's reference chart (`seed_reference_chart` is off in production)? | Accountant decides before any GL opening entry |
| **D4** | Source roles missing from the target matrix (at least `Procurement Officer`, plus any hand-made role): map each to a matrix role, or retire it | Keep the name; map per WNG |
| **D5** | Historical projects: (a) planned cost-line regeneration for all 658 budgets, or open projects only; (b) bulk financial closure of completed, closed and cancelled projects at cutover | Open projects only; closure decided by Finance |
| **D6** | Which departments are **direct** labour (W7 classification) | Set in the UI at cutover |
| **D7** | Opening petty-cash float (physical count) and GL opening balances | Accountant input at cutover |
| **D8** | Cutover window, source freeze method, URL switch, acceptance period and rollback rule, and whether a `schedule:run` cron is added (failed-job alerting, daily escalations) | WNG sets dates |

**Operator inputs (not decisions):**
- source schema and ledger exports (§6);
- DB engine and version;
- the cause of the `deploy.yml` migrate gap on the target (§18), with target DB grants;
- `storage/app` size;
- PHP CLI path;
- the project status distribution (§20).

## 27. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| The live source schema differs from `5bf4ab3`'s migrations | HIGH until checked | §6 export and comparison; Stage 1 reconciliation; §21 schema gate |
| Pre-existing orphans in the source (unenforced FKs, e.g. `element_materials`) | MEDIUM | Pre-load orphan scan; explicit, WNG-approved handling; never silent |
| Backfills skipped by a naive copy | HIGH if ignored | The two-stage design runs them on real data |
| Seeded-row ID collisions (8–9 masters) | MEDIUM | Stage 1 consistency, then `replace-seeded` |
| Target pipeline `migrate` not working (§18) | HIGH for cutover | Attended initialisation; root cause found before cutover |
| A future master push runs `migrate --force` against the live target during or after import | MEDIUM | Initialise the target at exactly the release commit; freeze master pushes during the cutover window |
| File references break (missing files, absolute URLs) | MEDIUM | `migration:verify-files`; reviewed rewrite |
| Overtime hash chain invalidated | HIGH if IDs or timezone change | IDs preserved; `Africa/Nairobi` in both; chain verifier |
| Users locked out after cutover | LOW | Hashes portable; forced re-login; UAT login sample |
| Data entered in the source after the rehearsal | MEDIUM | Tooling is repeatable: the final run uses a fresh frozen copy, never a delta |
| Engine or version mismatch between rehearsal and host | MEDIUM | Operator confirms; rehearse on the same major version |

## 28. Immediate Next Step

1. **Build the tooling** (§21): the `migration:import-source` command with plan-file generation from 50A, dry-run, schema gate, orphan scan, execute and validate modes, plus `migration:verify-files` and `migration:verify-overtime-chain`. Include tests against local scratch databases.
2. **In parallel, collect** the §26 operator inputs and WNG decisions D1–D8.
3. **Then run the §22 rehearsal on a recent source dump.**

## 29. Final Verdict

### READY TO BUILD SOURCE-TO-TARGET MIGRATION TOOLING

**The schema analysis is sufficient, and was done at database level, not inferred:**
- The source schema was rebuilt from its commit.
- The target schema was rebuilt from the branch.
- The upgrade path was proven column- and index-identical to the clean chain.
- The DATA-1 tables are identical or additive.
- No imported table references excluded history.
- Budget authority, W7, authentication and the queue are all confirmed compatible.

**The remaining unknowns are operator facts** (the live source schema, and the engine), which the tooling checks before loading anything. WNG decisions configure the plan without changing its design.

**Production is not ready. Nothing was migrated, initialised, reset, deployed or merged.**
