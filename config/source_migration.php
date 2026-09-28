<?php

/*
|--------------------------------------------------------------------------
| Source-to-target data migration (Reports 50 and 51)
|--------------------------------------------------------------------------
|
| The rules the migration plan is generated from, and the guards every
| migration:* command enforces. The plan itself (database/source-migration/
| plan.json) is generated from these rules and then REVIEWED; the commands
| execute the reviewed plan, never these rules directly.
|
| Stage 1 upgrades a COPY of the source ERP with this codebase's migrations, so
| the staging database has the target's table set. Every rule below therefore
| names target tables.
*/

return [

    // The staging connection. Never the application's own connection.
    'source_connection' => 'source_staging',

    // Database names that must never be used as the staging source: the live
    // source ERP is read by nobody but the operator taking its dump.
    'live_source_databases' => ['woodnork_erpsystem'],

    // Database names that are the live target. Loading into one requires
    // --cutover as well as --execute and a typed confirmation (D8).
    'live_target_databases' => ['woodnork_erp'],

    'plan_path' => database_path('source-migration/plan.json'),

    /*
    | Stage 1 ledger reconciliation. The source commit (5bf4ab3) ran these two asset
    | migrations under mis-dated names; the target renamed them (0ea8eec, contents
    | byte-identical). On the staging copy the new name is recorded only when every
    | marker proves the schema effect is already present (Report 49 rule: never mark
    | a migration run because it is old).
    */
    'renamed_migrations' => [
        '2024_01_10_add_next_service_date_to_assets_table' => [
            'renamed_to' => '2026_06_29_000010_add_next_service_date_to_assets_table',
            'markers' => ['column:assets.next_service_date'],
        ],
        '2024_01_10_create_asset_service_logs_table' => [
            'renamed_to' => '2026_06_29_000011_create_asset_service_logs_table',
            'markers' => ['table:asset_service_logs'],
        ],
    ],

    'report_path' => storage_path('app/source-migration'),

    'chunk_size' => 500,

    /*
    | Classification. A table not named below defaults to `import` (D1: no
    | silent operational-data loss).
    */
    'exclude' => [
        // DATA-1 Q1: petty-cash transactional history. `payments` is the old
        // petty_cash_disbursements, renamed by 2026_09_09_000001 during Stage 1.
        'payments' => 'DATA-1 Q1 — petty-cash disbursements (renamed from petty_cash_disbursements in Stage 1)',
        'petty_cash_disbursement_allocations' => 'DATA-1 Q1',
        'petty_cash_requisitions' => 'DATA-1 Q1',
        'petty_cash_requisition_items' => 'DATA-1 Q1',
        'petty_cash_top_ups' => 'DATA-1 Q1',
        'petty_cash_ledger_entries' => 'DATA-1 Q1',
        'petty_cash_activity_logs' => 'DATA-1 Q1',
        'petty_cash_balances' => 'DATA-1 Q1 — the float restarts from the physical count (D7)',
        'petty_cash_offline_batches' => 'DATA-1 Q1',
        'petty_cash_offline_rows' => 'DATA-1 Q1',
        'petty_cash_surrender_items' => 'DATA-1 Q1',
        'petty_cash_surrender_reviews' => 'DATA-1 Q1',
        'petty_cash_cash_counts' => 'DATA-1 Q1',
        'petty_cash_custody_handovers' => 'DATA-1 Q1',
        'direct_disbursement_requests' => 'DATA-1 Q1',
        // DATA-1 Q2: client receipts.
        'enquiry_payments' => 'DATA-1 Q2 — client receipts',
        'client_receipts' => 'DATA-1 Q2',
        'project_invoices' => 'Finance — receivables start fresh',
        'project_invoice_lines' => 'Finance — receivables start fresh',
        'project_invoice_allocations' => 'Finance — receivables start fresh',
        // DATA-1 Q3: payroll and salary-advance transactions.
        'payroll_runs' => 'DATA-1 Q3',
        'payroll_ledgers' => 'DATA-1 Q3',
        'payslips' => 'DATA-1 Q3',
        'salary_advance_requests' => 'DATA-1 Q3',
        'salary_advance_recoveries' => 'DATA-1 Q3',
        // Development/test cost lines and journals; the redesigned ledger starts fresh.
        'cost_lines' => 'DATA-1 — Finance cost lines are regenerated or start fresh',
        'cost_line_allocations' => 'Finance — starts fresh',
        'cost_line_transfers' => 'Finance — starts fresh',
        'journal_entries' => 'DATA-1 — journals start fresh (GL opening needs D3/D7)',
        'journal_lines' => 'DATA-1 — journals start fresh',
        'project_labour_actuals' => 'W7 — labour records start fresh',
        'project_labour_actual_returns' => 'W7 — starts fresh',
        'payment_allocations' => 'Finance — starts fresh',
        'spend_vouchers' => 'Finance — starts fresh',
        'spend_voucher_allocations' => 'Finance — starts fresh',
        'spend_voucher_reviews' => 'Finance — starts fresh',
        'stores_finance_postings' => 'Finance outbox — starts fresh',
        'finance_attachments' => 'Finance — starts fresh',
        'finance_cash_movements' => 'Finance — starts fresh',
        'finance_reconciliation_statements' => 'Finance — starts fresh',
        'finance_statement_transactions' => 'Finance — starts fresh',
        'finance_statement_matches' => 'Finance — starts fresh',
        'finance_work_assignments' => 'Finance — starts fresh',
        'finance_work_assignment_events' => 'Finance — starts fresh',
        'finance_period_audit_logs' => 'Finance — starts fresh',
    ],

    // D2 — CLOSED 2026-09-28: the source has no PO/GRN/bill rows, so these stay
    // held back and load nothing. Never loaded while pending.
    'decision_pending' => [
        'purchase_orders' => 'D2 — pending source-data evidence',
        'purchase_order_items' => 'D2',
        'purchase_order_amendments' => 'D2',
        'purchase_order_corrections' => 'D2',
        'goods_receipt_notes' => 'D2',
        'goods_receipt_note_items' => 'D2',
        'goods_receipt_inspections' => 'D2',
        'bills' => 'D2',
        'bill_payments' => 'D2',
        // chart_of_accounts left this list on 2026-09-28: D3 was decided (Option A,
        // WNG keeps its chart). It is replace-seeded below.
    ],

    // A WNG decision that authorises loading a table MigrationPlan otherwise holds
    // back (D2/D3). The generator stamps it on the table's plan entry as 'decision'.
    'recorded_decisions' => [
        'chart_of_accounts' => 'D3 Option A — WNG keeps its own chart (WNG decision 2026-09-28; Reports 53–54)',
    ],

    'd2_tables' => [
        'purchase_orders', 'purchase_order_items', 'goods_receipt_notes', 'goods_receipt_note_items', 'bills', 'bill_payments',
    ],

    'skip' => [
        // Transient framework state: the target creates its own; users sign in again.
        'migrations' => 'Target has its own ledger (clean 607-migration chain)',
        'cache' => 'Transient', 'cache_locks' => 'Transient',
        'jobs' => 'Transient', 'job_batches' => 'Transient', 'failed_jobs' => 'Transient',
        'sessions' => 'Transient — users sign in again',
        'password_reset_tokens' => 'Transient',
        'personal_access_tokens' => 'Transient — users sign in again',
        'user_device_tokens' => 'Transient — push devices re-register',
        // Regenerated authority: never imported as authority (Report 50 §13).
        'permissions' => 'Regenerated — RoleAndPermissionSeeder + permissions:sync',
        'role_has_permissions' => 'Regenerated — permissions:sync from RolePermissions matrix',
        // Target-only reference masters: built by the target's own migrations and
        // FinanceReferenceSeeder, identically in staging and target.
        'accounting_periods' => 'Target reference — regenerated',
        'activities' => 'Target reference — regenerated',
        'cost_centres' => 'Target reference — regenerated',
        'cost_causes' => 'Target reference — regenerated',
        'payee_types' => 'Target reference — regenerated',
        'payment_sources' => 'Target reference — regenerated',
        'payment_terms' => 'Target reference — regenerated',
        'vat_treatments' => 'Target reference — regenerated',
        'wht_categories' => 'Target reference — regenerated',
        'posting_rules' => 'Target reference — regenerated',
        'finance_settings' => 'Target reference — regenerated',
        'expense_codes' => 'Target reference — regenerated (same migrations seed staging and target)',
        'petty_cash_requisition_types' => 'Target reference — regenerated',
        'document_sequences' => 'Target sequence state — starts fresh',
        // Created by migrations deleted before the source commit (ghost ledger entries,
        // Report 49 §7). Not in the source-commit or target schema; a database that ran
        // them early may still hold them. Their row counts are reported, never dropped silently.
        'design_task_contexts' => 'Legacy table from a since-deleted migration; the target schema has no such table',
        'finance_task_contexts' => 'Legacy table from a since-deleted migration; the target schema has no such table',
        'logistics_task_contexts' => 'Legacy table from a since-deleted migration; the target schema has no such table',
    ],

    // Direct user permission grants, re-keyed by permission NAME against the
    // regenerated target permissions. Run after permissions:sync (runbook step).
    'import_mapped' => [
        'model_has_permissions' => 'Direct grants mapped by permission name; unmapped grants reported, never created',
    ],

    // Tables that target migrations seed on an empty database. Stage 1 applies the
    // same seeds on top of the source rows, so the staging rows are authoritative:
    // the target's seeded rows are replaced — only when every one of them is
    // present in staging by natural key. Keys: column list; `parent_id` resolves
    // to the parent's name.
    'replace_seeded' => [
        'leave_types' => ['code'],
        'units_of_measure' => ['code'],
        'material_item_types' => ['code'],
        'material_categories' => ['name', 'parent_id'],
        'hr_action_types' => ['code'],
        'attendance_work_schedules' => ['name'],
        'production_defect_codes' => ['code'],
        'production_root_cause_codes' => ['code'],
        // D3 Option A (2026-09-28): WNG's own chart is the chart. Staging holds
        // WNG's 120 accounts plus the 3 the target migrations insert (2160, 7150,
        // 7550), so the target's 3 are covered by code and replaced; target-owned
        // rows that referenced them (expense codes) are re-pointed by the loader.
        // The accounts WNG lacks are created after the load by finance:complete-chart.
        'chart_of_accounts' => ['code'],
    ],

    /*
    | Integer references the database does not enforce (no FK), checked by the
    | orphan scan in addition to every FK the target schema declares.
    | child.column => parent table (id). Polymorphic references carry the type.
    */
    'unenforced_references' => [
        'quote_approvals.task_id' => 'enquiry_tasks',
        'quote_approvals.enquiry_id' => 'project_enquiries',
        'requisition_items.project_enquiry_id' => 'project_enquiries',
        'requisition_items.material_id' => 'library_materials',
        'requisitions.project_id' => 'projects',
        'requisitions.employee_id' => 'employees',
        'requisitions.department_id' => 'departments',
        'hr_onboarding_cases.employee_id' => 'employees',
        'hr_onboarding_cases.department_id' => 'departments',
        'hr_offboarding_cases.employee_id' => 'employees',
        'hr_offboarding_cases.department_id' => 'departments',
        // A string column "Reference to materials task item" (create_production_elements_table):
        // it points at element_materials, not library_materials — found on real data (Report 52).
        'production_elements.material_id' => 'element_materials',
        'goods_receipt_note_items.material_id' => 'library_materials',
        'purchase_order_items.material_id' => 'library_materials',
        'inventory_logs.project_id' => 'projects',
        'inventory_logs.supplier_id' => 'suppliers',
        'client_interactions.enquiry_id' => 'project_enquiries',
        'task_production_data.task_id' => 'enquiry_tasks',
        'job_cards.worker_id' => 'technical_labours',
        'suppliers.user_id' => 'users',
        'sessions.user_id' => 'users',
        'model_has_roles.model_id' => ['table' => 'users', 'type_column' => 'model_type', 'type' => 'App\\Models\\User'],
        'model_has_permissions.model_id' => ['table' => 'users', 'type_column' => 'model_type', 'type' => 'App\\Models\\User'],
    ],

    /*
    | Headline counts the operator observed on the live source, just after the
    | rehearsal snapshot erpsystem-20260928-0918 (the earlier observation that day
    | was 731 projects; the source is live). Informational: during rehearsal the
    | restored-copy counts are the authority (Report 52: they matched these).
    */
    'reference_counts' => [
        'observed_on' => '2026-09-28 (post-snapshot)',
        'counts' => [
            'projects' => 737,
            'employees' => 93,
            'project_enquiries' => 1387,
            'task_budget_data' => 658,
            'clients' => 258,
            'users' => 65,
        ],
    ],

    /*
    | D5 — planned CostLines are regenerated for ACTIVE/OPEN projects only. A
    | budget is eligible when its enquiry is not in a closed state and, if the
    | enquiry has a project, that project is not in a closed state either.
    */
    'd5' => [
        'closed_enquiry_statuses' => ['completed', 'closed', 'cancelled'],
        'closed_project_statuses' => ['completed', 'closed', 'cancelled'],
    ],

    /*
    | File references in preserved tables (Report 50 §19). kind: path | json.
    | Values beginning `data:` are inline data, not files. Signature columns
    | (site_surveys.*_signature, archival_reports.project_officer_signature) hold
    | typed names and checklist_project_budget_file is a boolean — found in the
    | local dress rehearsal — so none of them is listed.
    */
    'file_columns' => [
        ['employees', 'profile_photo_path', 'path', 'HR'],
        ['employee_documents', 'file_path', 'path', 'HR'],
        ['hr_action_attachments', 'file_path', 'path', 'HR'],
        ['hr_candidate_documents', 'file_path', 'path', 'HR'],
        ['hr_candidates', 'background_check_documents', 'json', 'HR'],
        ['hr_offboarding_attachments', 'file_path', 'path', 'HR'],
        ['leave_requests', 'attachment_path', 'path', 'HR'],
        ['disciplinary_cases', 'attachments', 'json', 'HR'],
        ['grievances', 'attachments', 'json', 'HR'],
        ['incidents', 'evidence_paths', 'json', 'HR'],
        ['design_assets', 'file_path', 'path', 'Projects'],
        ['site_surveys', 'images', 'json', 'Projects'],
        ['site_surveys', 'survey_photos', 'json', 'Projects'],
        ['setup_task_photos', 'path', 'path', 'Projects'],
        ['setdown_task_photos', 'path', 'path', 'Projects'],
        ['handover_surveys', 'evidence_files', 'json', 'Projects'],
        ['archival_reports', 'attachments', 'json', 'Projects'],
        ['task_quote_data', 'excel_quote_file', 'path', 'Projects'],
        ['task_attachments', 'file_path', 'path', 'UniversalTask'],
        ['assets', 'image_path', 'path', 'Assets'],
        ['production_ncrs', 'image_path', 'path', 'Production'],
        ['work_order_task_evidence', 'file_path', 'path', 'Production'],
        ['work_order_rework_evidence', 'file_path', 'path', 'Production'],
        ['vehicles', 'photo_front', 'path', 'Logistics'],
        ['vehicles', 'photo_side', 'path', 'Logistics'],
        ['vehicle_maintenance_logs', 'before_photos', 'json', 'Logistics'],
        ['vehicle_maintenance_logs', 'after_photos', 'json', 'Logistics'],
        ['support_ticket_attachments', 'path', 'path', 'Support'],
    ],

    // Queue expectations for the target (Report 50 §18).
    'queue' => [
        'tables' => ['jobs', 'job_batches', 'failed_jobs'],
        'connection' => 'database',
        'cron' => '* * * * * cd {base_path} && flock -n /tmp/wng-erp-queue.lock {php} artisan queue:work database --queue=stores-finance,default --stop-when-empty --max-time=55 --timeout=50 --sleep=3 --tries=3 >> storage/logs/queue-cron.log 2>&1',
    ],
];
