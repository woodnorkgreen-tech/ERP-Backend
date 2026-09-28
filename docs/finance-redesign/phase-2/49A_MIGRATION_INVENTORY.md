# 49A — Migration Inventory (appendix to Report 49)

**Generated:** 2026-09-28 by `49_baseline_tools/classify_migrations.py` from branch `finance/critical-stabilization-fixes`. It is read-only and derived from source.

**Scope:** the 607 migrations Laravel loads. That is `database/migrations` plus the 11 module directories registered with `loadMigrationsFrom()`. `app/Modules/Finance/PettyCash/Database/Migrations/` is **not** registered, so its one file never runs and is excluded.

**Order:** execution order. Laravel merges all paths and sorts by file basename, so `#` is the order a ledgerless `migrate` would attempt.

**Column meanings**

- **Baseline class** (mutually exclusive):
  - **LIKELY PRESENT**: the migration is in the 419-entry ledger of the 2026-07-27 local "after-database-import" snapshot (dev ledger batches 1–74). It is present in production only if that import was a production dump, and even then only as of that date. **It still requires marker verification.**
  - **SCHEMA COMPARISON**: on `master`, but added after that ledger (dev batch 75). Its production state is unknown.
  - **PHASE 2B NEW**: on the release branch only, never on `master`.
- **Tags** (any combination):
  - **DATA**: the migration writes rows (inserts, updates, deletes, seeders, model calls).
  - **DESTRUCTIVE**: it drops or renames a table or column, calls `->change()`, runs raw DROP/TRUNCATE/DELETE, or deletes rows.
  - **IDEMPOTENT**: it guards itself with `hasTable`/`hasColumn`/`IF NOT EXISTS`/upsert and does nothing destructive.
- **Verify:**
  - `auto (n)`: n schema markers (table exists, column exists, column absent, rename done) that `verify_markers.py` checks.
  - `superseded`: a later migration undoes this one's markers, so its presence must be judged together with that later migration.
  - `MANUAL`: no automatic marker (permission or data seeding, index, FK or raw-SQL-only). Needs a hand-written check (Report 49 §10.4).

**Totals:** {'LIKELY PRESENT': 406, 'DESTRUCTIVE': 53, 'IDEMPOTENT': 63, 'DATA': 80, 'SCHEMA COMPARISON': 178, 'PHASE 2B NEW': 23}

**Self-test on the fully migrated local database:** 507 PRESENT, 19 SUPERSEDED, 81 MANUAL, 0 ABSENT, 0 PARTIAL.

**Negative control** (Phase 2B tables and columns removed from the export): all 20 markable Phase 2B migrations were flagged ABSENT, with 0 false flags on the 584 `master` migrations.

| # | Migration | Module | Baseline class | Tags | Verify | Notes |
|---|---|---|---|---|---|---|
| 1 | `0001_01_01_000000_create_users_table` | root | LIKELY PRESENT |  | auto (3) | unguarded create |
| 2 | `0001_01_01_000001_create_cache_table` | root | LIKELY PRESENT |  | auto (2) | unguarded create |
| 3 | `0001_01_01_000002_create_jobs_table` | root | LIKELY PRESENT |  | auto (3) | unguarded create |
| 4 | `2024_01_21_000003_create_work_centers_table` | Production | LIKELY PRESENT |  | auto (1) | unguarded create |
| 5 | `2024_01_21_000007_drop_quality_checks_table` | root | LIKELY PRESENT | DESTRUCTIVE | auto (1) |  |
| 6 | `2025_01_21_000001_create_task_production_data_table` | root | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 7 | `2025_01_21_000002_create_production_elements_table` | root | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 8 | `2025_01_21_000003_create_production_quality_checkpoints_table` | root | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 9 | `2025_01_21_000004_create_production_issues_table` | root | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 10 | `2025_01_21_000005_create_production_completion_criteria_table` | root | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 11 | `2025_09_07_104217_create_personal_access_tokens_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 12 | `2025_09_15_160036_create_departments_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 13 | `2025_09_16_091527_create_permission_tables` | root | LIKELY PRESENT |  | MANUAL |  |
| 14 | `2025_09_16_091600_create_employees_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 15 | `2025_09_16_091601_add_fields_to_departments_table` | HR | LIKELY PRESENT |  | auto (5) |  |
| 16 | `2025_09_16_092504_add_employee_and_department_to_users_table` | HR | LIKELY PRESENT |  | auto (4) |  |
| 17 | `2025_09_16_093357_add_description_to_roles_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 18 | `2025_09_16_192010_add_missing_fields_to_employees_table` | HR | LIKELY PRESENT | DATA DESTRUCTIVE | auto (9) | ->change() x1 |
| 19 | `2025_09_23_130446_create_clients_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 20 | `2025_09_30_073807_create_project_enquiries_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 21 | `2025_09_30_224943_create_enquiry_tasks_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 22 | `2025_10_02_120500_add_department_id_to_enquiry_tasks_table` | root | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 23 | `2025_10_02_145824_add_assignment_fields_to_enquiry_tasks_table` | root | LIKELY PRESENT |  | auto (5) |  |
| 24 | `2025_10_02_145855_create_task_assignment_history_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 25 | `2025_10_02_195853_make_department_id_nullable_in_enquiry_tasks_table` | root | LIKELY PRESENT | DESTRUCTIVE | auto (1) | ->change() x1 |
| 26 | `2025_10_05_090941_add_assigned_to_to_enquiry_tasks_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 27 | `2025_10_12_093952_create_projects_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 28 | `2025_10_12_094036_create_workflow_templates_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 29 | `2025_10_12_094121_create_workflow_template_tasks_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 30 | `2025_10_12_094151_create_workflow_instances_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 31 | `2025_10_12_094222_create_workflow_tasks_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 32 | `2025_10_12_123243_update_projects_foreign_key_to_project_enquiries` | root | LIKELY PRESENT |  | MANUAL |  |
| 33 | `2025_10_12_123300_drop_enquiries_table` | root | LIKELY PRESENT | DESTRUCTIVE | auto (1) |  |
| 34 | `2025_10_13_132514_create_site_surveys_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 35 | `2025_10_14_134117_rename_enquiry_id_to_project_enquiry_id_in_site_surveys_table` | root | LIKELY PRESENT |  | MANUAL |  |
| 36 | `2025_10_15_082647_add_enquiry_task_id_to_site_surveys_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 37 | `2025_10_15_094101_update_site_survey_enquiry_task_id` | root | LIKELY PRESENT | DATA | MANUAL |  |
| 38 | `2025_10_16_114232_create_design_assets_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 39 | `2025_10_18_081254_enhance_enquiry_tasks_table_with_departmental_fields` | root | LIKELY PRESENT | IDEMPOTENT | auto (11) |  |
| 40 | `2025_10_22_173156_create_element_templates_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 41 | `2025_10_22_173240_create_element_template_materials_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 42 | `2025_10_22_173359_create_task_materials_data_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 43 | `2025_10_22_175520_create_project_elements_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 44 | `2025_10_22_175637_create_element_materials_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 45 | `2025_10_27_133819_create_task_budget_data_table` | root | LIKELY PRESENT |  | auto (2) | unguarded create |
| 46 | `2025_10_27_152355_create_petty_cash_top_ups_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 47 | `2025_10_27_152426_create_petty_cash_disbursements_table` | root | LIKELY PRESENT |  | auto (1) superseded | unguarded create |
| 48 | `2025_10_27_152554_create_petty_cash_balances_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 49 | `2025_10_29_083659_add_foreign_key_constraints_and_indexes_to_task_tables` | root | LIKELY PRESENT | IDEMPOTENT | auto (4) |  |
| 50 | `2025_11_07_125114_create_task_quote_data_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 51 | `2025_11_09_074932_create_quote_approvals_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 52 | `2025_11_09_115739_create_budget_additions_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 53 | `2025_11_09_141802_add_is_additional_to_element_materials_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 54 | `2025_11_10_072711_add_job_number_to_project_enquiries_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 55 | `2025_11_11_015344_enhance_budget_additions_table_with_refined_schema` | root | LIKELY PRESENT |  | auto (8) |  |
| 56 | `2025_11_18_074550_create_task_procurement_data_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 57 | `2025_11_19_120000_create_logistics_tasks_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 58 | `2025_11_19_120100_create_transport_items_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 59 | `2025_11_19_120200_create_logistics_checklists_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 60 | `2025_11_19_120300_create_logistics_checklist_items_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 61 | `2025_11_19_144328_add_images_to_site_surveys_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 62 | `2025_11_20_041152_create_team_categories_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 63 | `2025_11_20_041219_create_team_types_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 64 | `2025_11_20_041241_create_teams_tasks_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 65 | `2025_11_20_041310_create_team_category_types_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 66 | `2025_11_20_074809_create_locations_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 67 | `2025_11_20_091840_create_logistics_log_entries_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 68 | `2025_11_20_094619_add_status_to_logistics_log_entries_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 69 | `2025_11_20_121524_create_announcements_table` | HR | LIKELY PRESENT |  | auto (2) | unguarded create |
| 70 | `2025_11_20_123207_add_project_officer_id_to_project_enquiries_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 71 | `2025_11_21_035634_add_survey_photos_to_site_surveys_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 72 | `2025_11_22_000001_create_archival_reports_table` | ArchivalTask | LIKELY PRESENT |  | auto (1) | unguarded create |
| 73 | `2025_11_22_000002_create_archival_setup_items_table` | ArchivalTask | LIKELY PRESENT |  | auto (1) | unguarded create |
| 74 | `2025_11_22_000003_create_archival_item_placements_table` | ArchivalTask | LIKELY PRESENT |  | auto (1) | unguarded create |
| 75 | `2025_11_23_081437_add_budget_version_tracking_to_task_quote_data` | root | LIKELY PRESENT |  | auto (4) |  |
| 76 | `2025_11_23_095706_create_quote_versions_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 77 | `2025_11_23_114230_update_procurement_items_json_structure` | root | LIKELY PRESENT | DATA | MANUAL |  |
| 78 | `2025_11_23_160000_create_enquiry_task_user_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 79 | `2025_11_23_160001_migrate_task_assignments_to_pivot_table` | root | LIKELY PRESENT | DATA IDEMPOTENT | MANUAL |  |
| 80 | `2025_11_24_064752_add_setdown_time_to_logistics_log_entries_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 81 | `2025_11_24_101054_make_project_id_nullable_in_logistics_tasks_table` | root | LIKELY PRESENT | DESTRUCTIVE | auto (1) | ->change() x1 |
| 82 | `2025_11_25_075111_create_material_versions_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 83 | `2025_11_25_075112_create_budget_versions_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 84 | `2025_11_26_032000_add_last_import_date_to_task_budget_data` | root | LIKELY PRESENT |  | auto (1) |  |
| 85 | `2025_11_26_063900_add_approval_columns_to_task_quote_data` | root | LIKELY PRESENT |  | auto (6) |  |
| 86 | `2025_11_26_111205_create_events_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 87 | `2025_11_27_002900_make_teams_tasks_project_id_nullable` | root | LIKELY PRESENT | DESTRUCTIVE | auto (1) | ->change() x1 |
| 88 | `2025_11_27_062000_recreate_teams_activity_logs_table` | root | LIKELY PRESENT | DESTRUCTIVE | auto (1) | DROP-THEN-CREATE; unguarded create |
| 89 | `2025_11_27_062500_recreate_handover_surveys_table` | root | LIKELY PRESENT | DESTRUCTIVE | auto (1) | DROP-THEN-CREATE; handover_surveys created by 2 migrations; unguarded create |
| 90 | `2025_11_27_063000_ensure_teams_members_table` | root | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 91 | `2025_11_27_070000_add_team_id_to_logistics_tasks_table` | root | LIKELY PRESENT |  | auto (1) superseded |  |
| 92 | `2025_11_27_071500_create_setup_tasks_tables` | root | LIKELY PRESENT |  | auto (3) | unguarded create |
| 93 | `2025_11_27_083157_create_element_types_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 94 | `2025_11_27_093333_add_access_token_to_handover_surveys_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 95 | `2025_11_28_094630_recreate_handover_surveys_with_json_structure` | root | LIKELY PRESENT | DESTRUCTIVE | auto (1) | DROP-THEN-CREATE; handover_surveys created by 2 migrations; unguarded create |
| 96 | `2025_11_28_105214_add_alternative_feedback_fields_to_handover_surveys` | root | LIKELY PRESENT |  | auto (5) |  |
| 97 | `2025_11_28_143056_ensure_setdown_tables_exist` | root | LIKELY PRESENT | IDEMPOTENT | auto (3) |  |
| 98 | `2025_11_28_152114_add_project_id_to_setdown_tasks_table` | root | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 99 | `2025_11_28_152417_add_user_tracking_columns_to_setdown_tasks_table` | root | LIKELY PRESENT | IDEMPOTENT | auto (2) |  |
| 100 | `2025_12_01_000001_create_tasks_table` | UniversalTask | LIKELY PRESENT |  | auto (1) | unguarded create |
| 101 | `2025_12_01_000002_create_task_dependencies_table` | UniversalTask | LIKELY PRESENT |  | auto (1) | unguarded create |
| 102 | `2025_12_01_000003_create_task_assignments_table` | UniversalTask | LIKELY PRESENT |  | auto (1) | unguarded create |
| 103 | `2025_12_01_000004_create_task_issues_table` | UniversalTask | LIKELY PRESENT |  | auto (1) | unguarded create |
| 104 | `2025_12_01_000005_create_task_experience_logs_table` | UniversalTask | LIKELY PRESENT |  | auto (1) | unguarded create |
| 105 | `2025_12_01_000006_create_task_comments_table` | UniversalTask | LIKELY PRESENT |  | auto (1) | unguarded create |
| 106 | `2025_12_01_000007_create_task_attachments_table` | UniversalTask | LIKELY PRESENT |  | auto (1) | unguarded create |
| 107 | `2025_12_01_000008_create_task_history_table` | UniversalTask | LIKELY PRESENT |  | auto (1) | unguarded create |
| 108 | `2025_12_01_000010_create_task_templates_table` | UniversalTask | LIKELY PRESENT |  | auto (1) | unguarded create |
| 109 | `2025_12_01_160800_add_main_category_to_transport_items_table` | root | LIKELY PRESENT |  | auto (2) |  |
| 110 | `2025_12_02_073612_create_task_saved_views_table` | UniversalTask | LIKELY PRESENT |  | auto (1) | unguarded create |
| 111 | `2025_12_02_080230_create_task_time_entries_table` | UniversalTask | LIKELY PRESENT |  | auto (1) | unguarded create |
| 112 | `2025_12_02_080534_add_project_id_to_setdown_tasks_table` | root | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 113 | `2025_12_02_190022_add_documentation_and_issues_to_setdown_tasks_table` | root | LIKELY PRESENT | IDEMPOTENT | auto (2) |  |
| 114 | `2025_12_03_033516_create_setdown_checklists_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 115 | `2025_12_10_065045_rename_project_dates_in_site_surveys_table` | root | LIKELY PRESENT | DESTRUCTIVE | auto (2) | renameColumn x2 |
| 116 | `2025_12_15_000001_add_checklist_and_record_fields_to_archival_reports_table` | ArchivalTask | LIKELY PRESENT |  | auto (11) |  |
| 117 | `2025_12_15_000001_add_driver_to_logistics_log_entries_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 118 | `2025_12_15_073934_add_meeting_fields_to_events_table` | root | LIKELY PRESENT |  | auto (5) |  |
| 119 | `2025_12_16_082008_add_tax_field_to_petty_cash_disbursements_table` | root | LIKELY PRESENT |  | auto (1) superseded |  |
| 120 | `2025_12_16_114703_add_checklist_to_production_quality_checkpoints_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 121 | `2025_12_19_120000_modify_task_assignment_history_nullable` | root | LIKELY PRESENT | DESTRUCTIVE | auto (1) | ->change() x1 |
| 122 | `2025_12_20_000000_add_expires_at_to_task_assignments_table` | UniversalTask | LIKELY PRESENT |  | auto (1) |  |
| 123 | `2025_12_24_073900_change_project_scope_to_json` | root | LIKELY PRESENT | DESTRUCTIVE | auto (1) | ->change() x1; raw SQL x1 |
| 124 | `2026_01_02_140000_create_workstations_table` | MaterialsLibrary | LIKELY PRESENT |  | auto (1) | unguarded create |
| 125 | `2026_01_02_140050_create_material_categories_table` | MaterialsLibrary | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 126 | `2026_01_02_140100_create_library_materials_table` | MaterialsLibrary | LIKELY PRESENT |  | auto (1) | unguarded create |
| 127 | `2026_01_02_171109_add_library_material_id_to_materials_tables` | root | LIKELY PRESENT |  | auto (2) |  |
| 128 | `2026_01_02_174313_add_unit_cost_to_materials_tables` | root | LIKELY PRESENT |  | auto (2) |  |
| 129 | `2026_01_02_193000_add_soft_deletes_to_library_materials_table` | MaterialsLibrary | LIKELY PRESENT |  | MANUAL |  |
| 130 | `2026_01_02_194000_add_soft_deletes_to_workstations_table` | MaterialsLibrary | LIKELY PRESENT |  | MANUAL |  |
| 131 | `2026_01_07_140000_add_skipped_status_to_enquiry_tasks` | root | LIKELY PRESENT |  | MANUAL | raw SQL x1 |
| 132 | `2026_01_11_170514_create_action_logs_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 133 | `2026_01_11_225828_add_viewer_settings_to_task_quote_data_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 134 | `2026_01_12_142702_create_public_leads_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 135 | `2026_01_12_150145_add_how_did_you_hear_to_public_leads` | root | LIKELY PRESENT |  | auto (1) |  |
| 136 | `2026_01_12_152025_add_service_interest_string_to_public_leads` | root | LIKELY PRESENT |  | auto (1) |  |
| 137 | `2026_01_13_085152_create_suppliers_table` | ProcurementStores | LIKELY PRESENT |  | auto (1) | unguarded create |
| 138 | `2026_01_13_094000_create_stocks_table` | ProcurementStores | LIKELY PRESENT |  | auto (1) | unguarded create |
| 139 | `2026_01_13_094100_create_inventory_logs_table` | ProcurementStores | LIKELY PRESENT |  | auto (1) | unguarded create |
| 140 | `2026_01_13_151718_add_date_disbursed_to_petty_cash_disbursements_table` | root | LIKELY PRESENT |  | auto (1) superseded |  |
| 141 | `2026_01_14_065515_create_requisitions_table` | ProcurementStores | LIKELY PRESENT |  | auto (2) | unguarded create |
| 142 | `2026_01_14_092426_create_purchase_order_table` | ProcurementStores | LIKELY PRESENT |  | auto (3) | unguarded create |
| 143 | `2026_01_19_000001_create_work_orders_table` | Production | LIKELY PRESENT |  | auto (1) | unguarded create |
| 144 | `2026_01_20_073637_add_approval_fields_to_purchase_orders` | ProcurementStores | LIKELY PRESENT | IDEMPOTENT | auto (3) | raw SQL x1 |
| 145 | `2026_01_21_000002_create_daily_job_cards_table` | Production | LIKELY PRESENT | DESTRUCTIVE | auto (1) | DROP-THEN-CREATE; unguarded create |
| 146 | `2026_01_21_000003_create_daily_tasks_table` | Production | LIKELY PRESENT |  | auto (1) | unguarded create |
| 147 | `2026_01_21_000004_create_daily_issues_table` | Production | LIKELY PRESENT |  | auto (1) | unguarded create |
| 148 | `2026_01_21_095925_add_requisition_id_to_purchase_orders_table` | ProcurementStores | LIKELY PRESENT |  | auto (1) |  |
| 149 | `2026_01_22_060829_transform_invoices_to_bills_system` | ProcurementStores | LIKELY PRESENT | DATA DESTRUCTIVE | auto (7) | renameColumn x2; raw SQL x1; unguarded create |
| 150 | `2026_01_22_111236_add_workflow_task_fields_to_project_enquiries_table` | root | LIKELY PRESENT |  | MANUAL |  |
| 151 | `2026_01_22_141538_add_workflow_task_fields_to_project_enquiries_table` | root | LIKELY PRESENT |  | auto (2) |  |
| 152 | `2026_01_24_075600_add_persistent_id_to_element_materials_table` | root | LIKELY PRESENT | DATA DESTRUCTIVE | auto (2) | ->change() x1 |
| 153 | `2026_01_24_085020_add_persistent_id_to_project_elements_table` | root | LIKELY PRESENT | DATA DESTRUCTIVE | auto (2) | ->change() x1 |
| 154 | `2026_01_25_091243_create_technical_labours_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 155 | `2026_01_25_091723_add_technical_labour_id_to_teams_members_table` | HR | LIKELY PRESENT |  | auto (1) |  |
| 156 | `2026_01_25_104144_add_client_assets_to_transport_items_main_category_enum` | root | LIKELY PRESENT | DESTRUCTIVE | auto (1) | ->change() x1 |
| 157 | `2026_01_26_073910_add_tax_to_petty_cash_disbursements_table` | root | LIKELY PRESENT | IDEMPOTENT | auto (1) superseded |  |
| 158 | `2026_01_26_083054_add_pricing_to_requisitions` | ProcurementStores | LIKELY PRESENT |  | auto (3) |  |
| 159 | `2026_01_26_084730_update_petty_cash_classifications_enum` | root | LIKELY PRESENT |  | MANUAL | raw SQL x1 |
| 160 | `2026_01_26_094843_add_archived_to_petty_cash_tables` | root | LIKELY PRESENT |  | auto (6) |  |
| 161 | `2026_01_27_053726_rename_notes_to_reference_number_in_bill_payments_table` | ProcurementStores | LIKELY PRESENT | DESTRUCTIVE | auto (1) | renameColumn x1 |
| 162 | `2026_01_27_071700_create_goods_receipt_notes_table` | ProcurementStores | LIKELY PRESENT |  | auto (1) | goods_receipt_notes created by 2 migrations; unguarded create |
| 163 | `2026_01_27_071701_create_goods_receipt_note_items_table` | ProcurementStores | LIKELY PRESENT |  | auto (1) | goods_receipt_note_items created by 2 migrations; unguarded create |
| 164 | `2026_01_27_084900_add_previous_balance_to_petty_cash_top_ups_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 165 | `2026_01_27_092150_modify_goods_receipt_note_items_material_id_column` | ProcurementStores | LIKELY PRESENT |  | MANUAL |  |
| 166 | `2026_01_27_094900_update_payment_method_enum_in_petty_cash_top_ups` | root | LIKELY PRESENT |  | MANUAL | raw SQL x1 |
| 167 | `2026_01_27_094901_update_payment_method_enum_in_petty_cash_disbursements` | root | LIKELY PRESENT |  | MANUAL | raw SQL x1 |
| 168 | `2026_01_27_111200_add_date_topped_up_to_petty_cash_top_ups_table` | root | LIKELY PRESENT |  | auto (1) | raw SQL x1 |
| 169 | `2026_01_28_000001_add_placement_accuracy_to_archival_setup_items_table` | ArchivalTask | LIKELY PRESENT |  | auto (1) |  |
| 170 | `2026_01_29_064120_add_batch_number_to_inventory_logs_table` | ProcurementStores | LIKELY PRESENT |  | auto (1) |  |
| 171 | `2026_01_29_163000_ensure_grn_tables_exist` | ProcurementStores | LIKELY PRESENT | IDEMPOTENT | auto (2) | goods_receipt_notes created by 2 migrations; goods_receipt_note_items created by 2 migrations |
| 172 | `2026_01_30_080943_add_base_and_reason_to_material_versions` | root | LIKELY PRESENT |  | auto (2) |  |
| 173 | `2026_01_30_084201_add_change_log_to_material_versions` | root | LIKELY PRESENT |  | auto (1) |  |
| 174 | `2026_01_30_130000_update_daily_issues_status_enum` | root | LIKELY PRESENT | DESTRUCTIVE | auto (1) | dropColumn x1 |
| 175 | `2026_02_02_000001_fix_job_card_time_columns` | root | LIKELY PRESENT | DESTRUCTIVE | auto (4) | ->change() x4 |
| 176 | `2026_02_02_000002_update_job_cards_worker_id_foreign` | root | LIKELY PRESENT | DESTRUCTIVE | auto (1) | ->change() x1 |
| 177 | `2026_02_05_100006_create_petty_cash_requisitions_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 178 | `2026_02_05_100105_create_petty_cash_requisition_items_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 179 | `2026_02_05_100704_add_requisition_id_to_petty_cash_disbursements_table` | root | LIKELY PRESENT |  | auto (1) superseded |  |
| 180 | `2026_02_05_111826_add_payee_to_petty_cash_requisitions_table` | root | LIKELY PRESENT |  | auto (2) |  |
| 181 | `2026_02_05_113121_add_payee_and_receipt_to_petty_cash_requisition_items_table` | root | LIKELY PRESENT |  | auto (4) |  |
| 182 | `2026_02_05_132218_add_project_to_petty_cash_requisitions` | root | LIKELY PRESENT |  | auto (2) |  |
| 183 | `2026_02_06_000001_add_public_token_to_job_cards_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 184 | `2026_02_06_071017_add_signing_token_to_petty_cash_requisitions` | root | LIKELY PRESENT |  | auto (1) |  |
| 185 | `2026_02_06_132508_add_received_by_to_petty_cash_requisitions_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 186 | `2026_02_07_110700_add_requisition_id_to_petty_cash_top_ups_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 187 | `2026_02_09_035646_add_deleted_at_to_petty_cash_requisitions_table` | root | LIKELY PRESENT |  | MANUAL |  |
| 188 | `2026_02_09_121757_add_transaction_cost_and_code_to_petty_cash_disbursements` | root | LIKELY PRESENT |  | auto (1) superseded |  |
| 189 | `2026_02_11_084856_add_project_name_and_venue_to_petty_cash_requisitions` | root | LIKELY PRESENT |  | auto (2) |  |
| 190 | `2026_02_11_091711_add_venue_to_petty_cash_disbursements` | root | LIKELY PRESENT |  | auto (1) superseded |  |
| 191 | `2026_02_12_000005_create_work_order_scrap_logs_table` | Production | LIKELY PRESENT |  | auto (1) | unguarded create |
| 192 | `2026_02_13_000006_add_workflow_completed_steps_to_work_orders_table` | Production | LIKELY PRESENT |  | auto (1) |  |
| 193 | `2026_02_13_000007_create_work_order_tasks_table` | Production | LIKELY PRESENT |  | auto (1) | unguarded create |
| 194 | `2026_02_13_000008_create_work_order_task_assignees_table` | Production | LIKELY PRESENT |  | auto (1) | unguarded create |
| 195 | `2026_02_13_000009_add_status_to_work_order_tasks_table` | Production | LIKELY PRESENT |  | auto (4) |  |
| 196 | `2026_02_13_000010_create_work_order_mid_qc_checks_table` | Production | LIKELY PRESENT |  | auto (1) | unguarded create |
| 197 | `2026_02_13_000011_add_status_reason_to_work_order_tasks_table` | Production | LIKELY PRESENT |  | auto (1) |  |
| 198 | `2026_02_13_000012_create_work_order_task_evidence_table` | Production | LIKELY PRESENT |  | auto (1) | unguarded create |
| 199 | `2026_02_13_000013_add_qc_stage_to_work_order_mid_qc_checks_table` | Production | LIKELY PRESENT | DATA | auto (1) |  |
| 200 | `2026_02_13_000014_add_safety_checks_to_work_order_tasks_table` | Production | LIKELY PRESENT |  | auto (1) |  |
| 201 | `2026_02_13_000015_create_work_order_final_qc_checks_table` | Production | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 202 | `2026_02_13_000016_create_work_order_reworks_table` | Production | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 203 | `2026_02_14_000001_create_work_order_rework_evidence_table` | Production | LIKELY PRESENT |  | auto (1) | unguarded create |
| 204 | `2026_02_14_000002_add_rework_fields_to_work_order_reworks_table` | Production | LIKELY PRESENT | IDEMPOTENT | auto (5) |  |
| 205 | `2026_02_17_115911_create_notifications_table` | root | LIKELY PRESENT | IDEMPOTENT | auto (1) superseded |  |
| 206 | `2026_02_17_143527_add_budget_category_to_petty_cash_disbursements_table` | root | LIKELY PRESENT |  | auto (1) superseded |  |
| 207 | `2026_02_18_094751_add_onesignal_player_id_to_users_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 208 | `2026_02_19_000003_add_project_id_to_work_orders_table` | Production | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 209 | `2026_02_19_000004_create_production_defect_taxonomy_tables` | Production | LIKELY PRESENT | IDEMPOTENT | auto (2) |  |
| 210 | `2026_02_19_000005_create_production_ncr_tables` | Production | LIKELY PRESENT | IDEMPOTENT | auto (4) |  |
| 211 | `2026_02_19_000006_add_google_form_fields_to_production_ncrs_table` | Production | LIKELY PRESENT | IDEMPOTENT | auto (23) |  |
| 212 | `2026_02_19_000007_make_work_order_id_nullable_on_production_ncrs_table` | Production | LIKELY PRESENT | DESTRUCTIVE | auto (1) | ->change() x1 |
| 213 | `2026_02_19_000008_add_items_rejected_status_to_production_ncrs_table` | Production | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 214 | `2026_02_19_000009_add_reinspection_status_fields_to_production_ncrs_table` | Production | LIKELY PRESENT | IDEMPOTENT | auto (2) |  |
| 215 | `2026_02_19_194008_add_custom_description_to_requisition_items_table` | root | LIKELY PRESENT | DESTRUCTIVE | auto (3) | ->change() x1 |
| 216 | `2026_02_21_081820_update_purchase_order_items_for_custom_items` | ProcurementStores | LIKELY PRESENT | DESTRUCTIVE | auto (2) | ->change() x1 |
| 217 | `2026_02_21_095050_add_bill_id_to_petty_cash_requisitions_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 218 | `2026_02_21_161227_add_payee_phone_to_petty_cash_requisition_items` | root | LIKELY PRESENT |  | auto (1) |  |
| 219 | `2026_02_21_161802_add_payee_phone_to_petty_cash_requisitions` | root | LIKELY PRESENT |  | auto (1) |  |
| 220 | `2026_02_25_000001_create_work_order_handovers_table` | Production | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 221 | `2026_02_27_074237_create_petty_cash_activity_logs_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 222 | `2026_03_01_134227_add_remarks_to_petty_cash_requisition_items_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 223 | `2026_03_03_090604_add_public_fields_to_petty_cash_requisitions_table` | root | LIKELY PRESENT | DESTRUCTIVE | auto (4) | ->change() x1 |
| 224 | `2026_03_05_071032_create_chart_of_accounts_table` | Finance | LIKELY PRESENT |  | auto (1) | unguarded create |
| 225 | `2026_03_10_131208_add_finance_gate_to_project_enquiries_table` | root | LIKELY PRESENT |  | auto (2) |  |
| 226 | `2026_03_10_131208_create_enquiry_payments_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 227 | `2026_03_14_084448_add_awaiting_deposit_to_enquiry_status_enum` | root | LIKELY PRESENT |  | MANUAL | raw SQL x1 |
| 228 | `2026_03_14_110012_create_governance_audit_logs_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 229 | `2026_03_14_110233_drop_redundant_enquiry_columns` | root | LIKELY PRESENT | DESTRUCTIVE | auto (2) | dropColumn x1 |
| 230 | `2026_03_15_095252_add_client_approved_quote_to_project_enquiries` | root | LIKELY PRESENT |  | auto (1) |  |
| 231 | `2026_03_18_000000_update_notifications_table_add_custom_fields` | root | LIKELY PRESENT | IDEMPOTENT | auto (4) superseded |  |
| 232 | `2026_03_18_000001_make_notifiable_columns_nullable` | root | LIKELY PRESENT | DESTRUCTIVE | auto (2) superseded | ->change() x2 |
| 233 | `2026_03_19_124952_add_material_type_to_library_materials_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 234 | `2026_03_19_150000_create_leave_types_table` | HR | LIKELY PRESENT | DATA | auto (1) | unguarded create |
| 235 | `2026_03_19_150100_create_leave_requests_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 236 | `2026_03_19_160000_add_session_and_contact_employee_to_leave_requests_table` | HR | LIKELY PRESENT | DESTRUCTIVE | auto (3) | ->change() x1 |
| 237 | `2026_03_19_170000_sync_default_leave_types` | HR | LIKELY PRESENT | DATA IDEMPOTENT | MANUAL |  |
| 238 | `2026_03_19_180000_add_monthly_accrual_rate_to_leave_types_table` | HR | LIKELY PRESENT | DATA IDEMPOTENT | auto (1) |  |
| 239 | `2026_03_21_082904_add_usage_type_to_inventory_logs_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 240 | `2026_03_21_090000_add_allow_advance_to_leave_types_table` | HR | LIKELY PRESENT | DATA IDEMPOTENT | auto (1) |  |
| 241 | `2026_03_21_100000_add_explanation_to_leave_requests_table` | HR | LIKELY PRESENT |  | auto (1) |  |
| 242 | `2026_03_22_160000_create_hr_actions_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 243 | `2026_03_22_170000_add_payroll_fields_to_employees_table` | HR | LIKELY PRESENT |  | auto (11) |  |
| 244 | `2026_03_22_171936_create_employee_documents_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 245 | `2026_03_22_181401_create_payroll_variables_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 246 | `2026_03_22_181402_create_payroll_ledgers_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 247 | `2026_03_22_191329_create_payslips_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 248 | `2026_03_22_193731_create_payroll_tax_bands_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 249 | `2026_03_23_000000_add_carry_forward_days_to_leave_requests_table` | HR | LIKELY PRESENT |  | auto (1) |  |
| 250 | `2026_03_23_144709_add_bank_code_to_employees_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 251 | `2026_03_24_000000_create_leave_handovers_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 252 | `2026_03_24_064005_add_on_leave_to_employee_status` | root | LIKELY PRESENT |  | MANUAL | raw SQL x1 |
| 253 | `2026_03_24_121809_create_drivers_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 254 | `2026_03_24_121842_create_vehicles_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 255 | `2026_03_25_000000_create_incidents_table` | HR | LIKELY PRESENT |  | auto (3) | unguarded create |
| 256 | `2026_03_25_052754_fix_incident_comments_user_id_nullable` | root | LIKELY PRESENT | DESTRUCTIVE | auto (1) | ->change() x1 |
| 257 | `2026_03_25_063359_add_equipment_and_guest_fields_to_incidents_table` | root | LIKELY PRESENT |  | MANUAL |  |
| 258 | `2026_03_25_073050_create_trip_requests_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 259 | `2026_03_25_100000_add_equipment_and_guest_fields_to_incidents_table` | root | LIKELY PRESENT |  | auto (2) |  |
| 260 | `2026_03_26_120842_create_dispatch_and_deliveries` | root | LIKELY PRESENT |  | auto (5) | unguarded create |
| 261 | `2026_03_31_102954_create_active_trip_locations_table` | root | LIKELY PRESENT |  | auto (2) | unguarded create |
| 262 | `2026_03_31_111111_add_logged_at_to_inventory_logs_table` | ProcurementStores | LIKELY PRESENT | DATA | auto (1) |  |
| 263 | `2026_03_31_132702_create_hr_job_postings_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 264 | `2026_03_31_132703_create_hr_candidates_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 265 | `2026_03_31_132704_create_hr_candidate_experiences_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 266 | `2026_03_31_132705_create_hr_candidate_education_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 267 | `2026_03_31_132706_create_hr_candidate_documents_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 268 | `2026_03_31_132706_create_hr_candidate_references_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 269 | `2026_04_01_130752_add_recipient_name_to_inventory_logs_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 270 | `2026_04_01_150000_create_hr_action_types_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 271 | `2026_04_01_150100_create_hr_action_attachments_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 272 | `2026_04_01_150200_update_hr_actions_table` | HR | LIKELY PRESENT | DESTRUCTIVE | auto (3) | ->change() x1 |
| 273 | `2026_04_07_000001_create_hr_interviews_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 274 | `2026_04_07_000002_add_shortlisting_to_job_postings_table` | root | LIKELY PRESENT |  | auto (2) |  |
| 275 | `2026_04_07_000003_add_shortlist_score_to_candidates_table` | root | LIKELY PRESENT |  | auto (2) |  |
| 276 | `2026_04_08_084829_create_vehicle_maintenance_logs_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 277 | `2026_04_08_133018_create_vehicle_inspections_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 278 | `2026_04_09_111755_add_background_check_fields_to_candidates_table` | root | LIKELY PRESENT | DESTRUCTIVE | auto (5) | ->change() x1 |
| 279 | `2026_04_16_122753_add_cancelled_status_to_deliveries` | root | LIKELY PRESENT |  | MANUAL | raw SQL x1 |
| 280 | `2026_04_20_132000_seed_profile_update_action_type` | root | LIKELY PRESENT | DATA IDEMPOTENT | MANUAL |  |
| 281 | `2026_04_20_133000_update_hr_actions_for_approval_workflow` | root | LIKELY PRESENT | DESTRUCTIVE | auto (3) | ->change() x1 |
| 282 | `2026_04_27_084724_create_employee_salary_histories_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 283 | `2026_04_27_084844_create_payroll_runs_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 284 | `2026_04_27_091121_add_payroll_run_id_to_payslips_table` | HR | LIKELY PRESENT |  | auto (1) |  |
| 285 | `2026_04_28_000001_create_grievances_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 286 | `2026_04_28_000002_create_grievance_comments_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 287 | `2026_04_28_000003_create_grievance_activity_logs_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 288 | `2026_04_28_000004_create_disciplinary_cases_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 289 | `2026_04_28_000005_create_disciplinary_comments_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 290 | `2026_04_28_000006_create_disciplinary_activity_logs_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 291 | `2026_04_28_063516_create_salary_advance_requests_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 292 | `2026_04_28_124630_create_hr_audit_logs_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 293 | `2026_04_28_130836_add_recall_fields_to_leave_requests_table` | HR | LIKELY PRESENT |  | auto (3) |  |
| 294 | `2026_04_28_133157_add_profile_photo_to_employees_table` | HR | LIKELY PRESENT |  | auto (1) |  |
| 295 | `2026_04_30_090154_add_make_model_photos_to_vehicles_table` | root | LIKELY PRESENT |  | auto (4) |  |
| 296 | `2026_04_30_103039_fix_trip_requests_project_foreign_key` | root | LIKELY PRESENT |  | MANUAL |  |
| 297 | `2026_05_07_000001_enhance_logistics_tracking` | root | LIKELY PRESENT |  | auto (14) |  |
| 298 | `2026_05_13_000000_create_deliverables_blueprints_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 299 | `2026_05_13_000001_create_design_requirements_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 300 | `2026_05_14_000001_add_title_to_design_requirements_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 301 | `2026_05_14_132508_make_material_names_nullable` | root | LIKELY PRESENT | DESTRUCTIVE | auto (2) | ->change() x2 |
| 302 | `2026_05_15_142023_add_returnable_fields_to_transport_items_table` | root | LIKELY PRESENT |  | auto (2) |  |
| 303 | `2026_05_16_170000_create_overtime_tracking_tables` | HR | LIKELY PRESENT |  | auto (5) | unguarded create |
| 304 | `2026_05_16_200000_add_technical_labour_to_overtime` | HR | LIKELY PRESENT | DESTRUCTIVE | auto (6) | ->change() x3 |
| 305 | `2026_05_17_070040_add_supervisor_approval_to_compensations_table` | root | LIKELY PRESENT |  | auto (2) |  |
| 306 | `2026_05_17_075800_add_location_to_ot_entries_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 307 | `2026_05_17_082000_add_job_title_to_ot_entries_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 308 | `2026_05_17_084000_add_soft_deletes_to_overtime_tables` | root | LIKELY PRESENT |  | MANUAL |  |
| 309 | `2026_05_18_154028_create_profile_update_requests_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 310 | `2026_05_19_173000_add_scope_id_to_project_elements_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 311 | `2026_05_19_180000_create_project_deliverables_table` | root | LIKELY PRESENT | DATA | auto (1) | unguarded create |
| 312 | `2026_05_20_222906_create_petty_cash_ledger_entries_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 313 | `2026_05_20_222943_backfill_petty_cash_ledger_entries` | root | LIKELY PRESENT | DATA IDEMPOTENT | MANUAL |  |
| 314 | `2026_05_21_000001_add_advert_fields_to_hr_job_postings_table` | root | LIKELY PRESENT | IDEMPOTENT | auto (5) |  |
| 315 | `2026_05_21_000002_add_category_to_grievances_table` | HR | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 316 | `2026_05_27_000006_add_tracking_mode_to_stocks_table` | ProcurementStores | LIKELY PRESENT |  | auto (1) |  |
| 317 | `2026_05_28_000001_add_hikvision_id_to_employees_table` | HR | LIKELY PRESENT |  | auto (1) superseded |  |
| 318 | `2026_05_28_000002_create_attendance_device_sync_logs_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 319 | `2026_05_28_000003_create_attendance_device_raw_events_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 320 | `2026_05_28_000004_create_attendance_records_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 321 | `2026_05_29_000001_add_code_to_material_categories` | MaterialsLibrary | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 322 | `2026_05_29_000001_drop_hikvision_id_from_employees` | HR | LIKELY PRESENT | DESTRUCTIVE | auto (1) superseded | dropColumn x1 |
| 323 | `2026_05_29_000002_restore_hikvision_id_to_employees_table` | HR | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 324 | `2026_05_29_100000_add_material_category_id_to_library_materials` | MaterialsLibrary | LIKELY PRESENT | DATA IDEMPOTENT | auto (1) |  |
| 325 | `2026_05_30_000001_add_missing_clock_out_to_attendance_statuses` | HR | LIKELY PRESENT | DATA | MANUAL |  |
| 326 | `2026_05_30_000001_create_boards_table` | ProcurementStores | LIKELY PRESENT |  | auto (1) | unguarded create |
| 327 | `2026_05_30_000002_create_board_movements_table` | ProcurementStores | LIKELY PRESENT |  | auto (1) | unguarded create |
| 328 | `2026_05_30_000003_add_label_printed_to_boards_table` | ProcurementStores | LIKELY PRESENT |  | auto (3) |  |
| 329 | `2026_05_30_000004_create_board_requests_table` | ProcurementStores | LIKELY PRESENT |  | auto (1) | unguarded create |
| 330 | `2026_05_30_000005_add_material_type_to_library_materials` | MaterialsLibrary | LIKELY PRESENT | IDEMPOTENT | auto (1) | raw SQL x1 |
| 331 | `2026_05_31_000001_create_board_workflow_tasks_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 332 | `2026_05_31_000002_add_job_name_to_board_requests_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 333 | `2026_06_01_000001_add_condition_and_scrap_to_boards_system` | ProcurementStores | LIKELY PRESENT |  | auto (3) |  |
| 334 | `2026_06_02_000001_create_board_reconciliations_table` | ProcurementStores | LIKELY PRESENT |  | auto (1) | unguarded create |
| 335 | `2026_06_02_000001_create_hr_onboarding_cases_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 336 | `2026_06_02_000002_create_hr_onboarding_cards_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 337 | `2026_06_02_000003_create_hr_onboarding_tasks_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 338 | `2026_06_02_000004_create_hr_onboarding_document_requirements_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 339 | `2026_06_02_000005_create_hr_onboarding_welcome_kit_items_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 340 | `2026_06_02_000006_create_hr_onboarding_handovers_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 341 | `2026_06_02_000007_create_hr_onboarding_reviews_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 342 | `2026_06_02_000008_create_hr_onboarding_activity_logs_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 343 | `2026_06_02_000009_rename_line_manager_to_department_lead_in_onboarding_cases` | HR | LIKELY PRESENT | DESTRUCTIVE | auto (1) | renameColumn x1 |
| 344 | `2026_06_02_000010_update_onboarding_cases_status_enum` | HR | LIKELY PRESENT |  | MANUAL | raw SQL x1 |
| 345 | `2026_06_02_120000_add_performance_indexes_to_enquiry_tasks_table` | root | LIKELY PRESENT |  | MANUAL |  |
| 346 | `2026_06_03_100000_add_justification_to_task_quote_data_table` | root | LIKELY PRESENT |  | auto (2) |  |
| 347 | `2026_06_04_000001_add_is_applicable_and_is_needed_to_hr_onboarding_welcome_kit_items_table` | root | LIKELY PRESENT |  | auto (2) |  |
| 348 | `2026_06_04_000002_add_is_applicable_and_is_needed_to_hr_onboarding_tasks_and_document_requirements` | root | LIKELY PRESENT |  | auto (4) |  |
| 349 | `2026_06_05_000001_create_app_notifications_table` | Notifications | LIKELY PRESENT |  | auto (1) | unguarded create |
| 350 | `2026_06_05_000002_create_app_notification_preferences_table` | Notifications | LIKELY PRESENT |  | auto (1) | unguarded create |
| 351 | `2026_06_05_000003_create_user_device_tokens_table` | Notifications | LIKELY PRESENT |  | auto (1) | unguarded create |
| 352 | `2026_06_05_000004_restrict_app_notifications_user_delete` | Notifications | LIKELY PRESENT |  | MANUAL |  |
| 353 | `2026_06_09_000000_remove_team_fields_from_logistics_tasks_table` | root | LIKELY PRESENT | DESTRUCTIVE | auto (3) | dropColumn x1 |
| 354 | `2026_06_09_000001_add_soft_deletes_to_employees_table` | HR | LIKELY PRESENT |  | MANUAL |  |
| 355 | `2026_06_09_000002_create_employee_skills_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 356 | `2026_06_09_000003_create_employee_certifications_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 357 | `2026_06_09_000004_create_performance_reviews_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 358 | `2026_06_10_000001_add_rejection_reason_to_profile_update_requests` | HR | LIKELY PRESENT |  | auto (1) |  |
| 359 | `2026_06_11_000001_create_attendance_sync_requests_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 360 | `2026_06_13_000001_backfill_board_stock_tracking_mode` | ProcurementStores | LIKELY PRESENT | DATA | MANUAL |  |
| 361 | `2026_06_15_000001_add_metrics_to_attendance_device_sync_logs` | HR | LIKELY PRESENT |  | auto (6) |  |
| 362 | `2026_06_15_000001_change_disciplinary_cases_employee_id_to_employees` | HR | LIKELY PRESENT |  | MANUAL |  |
| 363 | `2026_06_15_000002_create_attendance_scheduling_tables` | HR | LIKELY PRESENT | DATA | auto (4) | unguarded create |
| 364 | `2026_06_15_000003_add_holiday_work_tracking` | HR | LIKELY PRESENT | DATA | auto (5) |  |
| 365 | `2026_06_15_000004_add_attendance_correction_metadata` | HR | LIKELY PRESENT |  | auto (3) |  |
| 366 | `2026_06_15_000005_link_attendance_overtime_proposals` | HR | LIKELY PRESENT |  | auto (5) |  |
| 367 | `2026_06_16_000001_make_employee_email_nullable` | HR | LIKELY PRESENT | DESTRUCTIVE | auto (1) | ->change() x1 |
| 368 | `2026_06_16_000002_add_statutory_exemptions_to_employees_table` | HR | LIKELY PRESENT |  | auto (1) |  |
| 369 | `2026_06_22_000001_add_promotion_link_to_technical_labours_table` | HR | LIKELY PRESENT |  | auto (2) |  |
| 370 | `2026_06_22_000002_add_reversal_link_to_ledger_entries_table` | HR | LIKELY PRESENT |  | auto (1) |  |
| 371 | `2026_06_22_100000_add_lead_approval_to_leave_requests_table` | HR | LIKELY PRESENT |  | auto (3) |  |
| 372 | `2026_06_23_000001_add_recurring_end_month_to_payroll_ledgers_table` | HR | LIKELY PRESENT |  | auto (1) |  |
| 373 | `2026_06_23_000001_add_skillset_to_hr_job_postings_table` | root | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 374 | `2026_06_23_000002_add_lifecycle_fields_to_hr_job_postings_table` | root | LIKELY PRESENT | IDEMPOTENT | auto (2) |  |
| 375 | `2026_06_24_000000_remove_skipped_status_from_enquiry_tasks` | root | LIKELY PRESENT | DATA | MANUAL | raw SQL x1 |
| 376 | `2026_06_29_000001_add_termination_fields_to_employees_table` | HR | LIKELY PRESENT |  | auto (3) |  |
| 377 | `2026_06_29_000001_create_assets_table` | Assets | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 378 | `2026_06_29_000002_add_ownership_image_availability_to_assets_table` | Assets | SCHEMA COMPARISON |  | auto (4) |  |
| 379 | `2026_06_29_000002_add_suspended_to_employee_status` | root | LIKELY PRESENT |  | MANUAL | raw SQL x1 |
| 380 | `2026_06_29_000003_add_soft_deletes_to_users_table` | root | LIKELY PRESENT |  | MANUAL |  |
| 381 | `2026_06_29_000003_create_asset_categories_table` | Assets | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 382 | `2026_06_29_000004_add_category_id_to_assets_table` | Assets | SCHEMA COMPARISON |  | auto (1) |  |
| 383 | `2026_06_29_000005_add_spreadsheet_fields_to_assets_table` | Assets | SCHEMA COMPARISON | DESTRUCTIVE | auto (8) | renameColumn x1 |
| 384 | `2026_06_29_000006_add_code_to_asset_categories_table` | Assets | SCHEMA COMPARISON |  | auto (1) |  |
| 385 | `2026_06_29_000007_add_import_hash_to_assets_table` | Assets | SCHEMA COMPARISON |  | auto (1) |  |
| 386 | `2026_06_29_000008_create_asset_hire_requests_table` | Assets | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 387 | `2026_06_29_000009_create_asset_assignment_history_table` | Assets | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 388 | `2026_06_29_000010_add_next_service_date_to_assets_table` | Assets | SCHEMA COMPARISON |  | auto (1) |  |
| 389 | `2026_06_29_000011_create_asset_service_logs_table` | Assets | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 390 | `2026_06_30_000001_create_petty_cash_disbursement_allocations_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 391 | `2026_06_30_000002_add_project_identity_to_petty_cash_disbursements` | root | LIKELY PRESENT | IDEMPOTENT | auto (2) superseded |  |
| 392 | `2026_06_30_000005_migrate_legacy_notifications` | Notifications | LIKELY PRESENT | DATA DESTRUCTIVE | auto (1) |  |
| 393 | `2026_07_01_000001_create_client_interactions_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 394 | `2026_07_01_000001_create_hr_offboarding_cases_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 395 | `2026_07_01_000002_create_hr_offboarding_cards_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 396 | `2026_07_01_000003_create_hr_offboarding_tasks_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 397 | `2026_07_01_000004_create_hr_offboarding_asset_returns_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 398 | `2026_07_01_000005_create_hr_offboarding_clearances_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 399 | `2026_07_01_000006_create_hr_offboarding_exit_interviews_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 400 | `2026_07_01_000007_create_hr_offboarding_final_settlements_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 401 | `2026_07_01_000008_create_hr_offboarding_activity_logs_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 402 | `2026_07_02_000001_add_project_budget_traceability_to_requisition_items` | ProcurementStores | LIKELY PRESENT | IDEMPOTENT | auto (9) |  |
| 403 | `2026_07_02_000001_add_review_fields_to_handover_surveys_table` | root | LIKELY PRESENT |  | auto (4) |  |
| 404 | `2026_07_02_000002_add_requisition_item_trace_to_purchase_order_items` | ProcurementStores | LIKELY PRESENT | IDEMPOTENT | auto (1) |  |
| 405 | `2026_07_02_000002_create_ncr_reports_table` | root | LIKELY PRESENT |  | auto (1) | unguarded create |
| 406 | `2026_07_02_000003_make_grn_items_material_optional_for_custom_po_items` | ProcurementStores | LIKELY PRESENT | DESTRUCTIVE | auto (2) | ->change() x1 |
| 407 | `2026_07_02_165313_add_pipeline_stage_to_public_leads_table` | root | LIKELY PRESENT |  | auto (3) |  |
| 408 | `2026_07_03_000001_add_excel_quote_fields_to_task_quote_data_table` | root | LIKELY PRESENT |  | auto (6) |  |
| 409 | `2026_07_06_000001_add_client_key_to_design_requirements_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 410 | `2026_07_06_000001_add_excel_quote_insights_to_task_quote_data_table` | root | LIKELY PRESENT |  | auto (1) |  |
| 411 | `2026_07_06_000002_add_closed_status_to_project_lifecycle` | root | LIKELY PRESENT |  | MANUAL | raw SQL x2 |
| 412 | `2026_07_08_000001_make_annual_leave_fixed_21_days` | HR | LIKELY PRESENT | DATA | MANUAL |  |
| 413 | `2026_07_09_000001_make_parental_leave_attachments_optional` | HR | LIKELY PRESENT | DATA | MANUAL |  |
| 414 | `2026_07_09_000002_create_hr_offboarding_attachments_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 415 | `2026_07_09_000003_add_exit_interview_category_to_hr_offboarding_attachments` | HR | LIKELY PRESENT |  | MANUAL | raw SQL x1 |
| 416 | `2026_07_21_000001_add_missing_project_enquiries_indexes` | root | LIKELY PRESENT |  | MANUAL |  |
| 417 | `2026_07_22_000001_create_leave_balance_adjustments_table` | HR | LIKELY PRESENT |  | auto (1) | unguarded create |
| 418 | `2026_07_27_000001_add_governance_fields_to_archival_reports_table` | ArchivalTask | SCHEMA COMPARISON |  | auto (4) |  |
| 419 | `2026_07_27_000001_create_support_tickets_tables` | root | SCHEMA COMPARISON |  | auto (4) | unguarded create |
| 420 | `2026_07_27_000002_add_support_management_permission` | root | SCHEMA COMPARISON | DATA | MANUAL |  |
| 421 | `2026_07_27_000003_link_support_attachments_to_messages` | root | SCHEMA COMPARISON |  | auto (1) |  |
| 422 | `2026_07_27_000004_add_client_handover_review_permission` | root | SCHEMA COMPARISON | DATA | MANUAL |  |
| 423 | `2026_07_27_000005_add_logistics_permissions_to_operational_roles` | root | SCHEMA COMPARISON | DATA | MANUAL |  |
| 424 | `2026_07_27_000006_create_logistics_manifest_submissions_tables` | root | SCHEMA COMPARISON |  | auto (2) | unguarded create |
| 425 | `2026_07_28_000001_create_logistics_loading_confirmation_links_table` | root | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 426 | `2026_07_28_000002_create_logistics_return_confirmation_links_table` | root | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 427 | `2026_07_28_120000_add_quote_waiver_to_project_enquiries_table` | root | SCHEMA COMPARISON |  | auto (5) |  |
| 428 | `2026_07_28_182200_add_public_name_fields_to_logistics_confirmations` | root | SCHEMA COMPARISON |  | auto (3) |  |
| 429 | `2026_07_29_000001_add_correction_review_fields_to_archival_reports_table` | ArchivalTask | SCHEMA COMPARISON |  | auto (5) |  |
| 430 | `2026_07_29_000001_add_sla_tracking_to_support_tickets` | root | SCHEMA COMPARISON | DATA | auto (3) |  |
| 431 | `2026_07_30_000001_migrate_setdown_issues_to_relational_records` | root | SCHEMA COMPARISON | DATA | MANUAL |  |
| 432 | `2026_07_30_160025_add_gender_to_employees_table` | root | SCHEMA COMPARISON |  | auto (1) |  |
| 433 | `2026_07_30_160100_add_restricted_gender_to_leave_types_table` | root | SCHEMA COMPARISON | DATA | auto (1) |  |
| 434 | `2026_07_30_171000_create_employee_staging_records_table` | root | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 435 | `2026_08_06_000001_add_inventory_controls_to_material_master` | MaterialsLibrary | SCHEMA COMPARISON | DATA IDEMPOTENT | auto (16) | raw SQL x1 |
| 436 | `2026_08_06_000001_create_design_jobs_table` | Design | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 437 | `2026_08_06_000002_create_design_types_table` | Design | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 438 | `2026_08_06_000002_create_item_types_and_uom_registry` | MaterialsLibrary | SCHEMA COMPARISON | DATA | auto (17) | raw SQL x1; unguarded create |
| 439 | `2026_08_06_000003_add_lot_traceability_to_inventory_logs` | ProcurementStores | SCHEMA COMPARISON |  | auto (2) |  |
| 440 | `2026_08_06_000003_create_design_items_table` | Design | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 441 | `2026_08_06_000004_create_controlled_inventory_instances` | ProcurementStores | SCHEMA COMPARISON |  | auto (5) | unguarded create |
| 442 | `2026_08_06_000004_create_design_documents_table` | Design | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 443 | `2026_08_06_000005_add_receipt_cost_to_inventory_logs` | ProcurementStores | SCHEMA COMPARISON |  | auto (1) |  |
| 444 | `2026_08_06_000005_create_design_bom_items_table` | Design | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 445 | `2026_08_06_000006_create_design_handoffs_table` | Design | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 446 | `2026_08_06_000006_reconcile_inventory_master_projections` | MaterialsLibrary | SCHEMA COMPARISON |  | MANUAL | raw SQL x3 |
| 447 | `2026_08_07_000007_add_sync_origin_to_design_jobs_table` | Design | SCHEMA COMPARISON | IDEMPOTENT | auto (2) |  |
| 448 | `2026_08_09_000001_create_finance_dimension_tables` | Finance | SCHEMA COMPARISON |  | auto (4) | unguarded create |
| 449 | `2026_08_09_000002_create_finance_tax_tables` | Finance | SCHEMA COMPARISON |  | auto (2) | unguarded create |
| 450 | `2026_08_09_000003_extend_chart_of_accounts_and_add_posting_rules` | Finance | SCHEMA COMPARISON |  | auto (6) | unguarded create |
| 451 | `2026_08_09_000004_create_accounting_periods_table` | Finance | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 452 | `2026_08_09_000005_create_finance_settings_table` | Finance | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 453 | `2026_08_09_000006_create_expense_codes_table` | Finance | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 454 | `2026_08_09_000007_create_spend_vouchers_table` | Finance | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 455 | `2026_08_09_000008_create_cost_lines_table` | Finance | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 456 | `2026_08_09_000009_add_source_ref_to_cost_lines` | Finance | SCHEMA COMPARISON |  | auto (1) |  |
| 457 | `2026_08_09_000010_add_cost_collector_permissions` | Finance | SCHEMA COMPARISON | DATA | MANUAL |  |
| 458 | `2026_08_09_000011_add_missing_petty_cash_permissions` | Finance | SCHEMA COMPARISON | DATA | MANUAL |  |
| 459 | `2026_08_09_000012_add_edit_top_up_permission` | Finance | SCHEMA COMPARISON | DATA | MANUAL |  |
| 460 | `2026_08_09_000013_create_journal_entries_tables` | Finance | SCHEMA COMPARISON |  | auto (2) | unguarded create |
| 461 | `2026_08_10_000001_add_supplier_id_to_requisition_items` | ProcurementStores | SCHEMA COMPARISON | IDEMPOTENT | auto (1) |  |
| 462 | `2026_08_10_000001_add_tax_fields_to_suppliers_table` | Finance | SCHEMA COMPARISON |  | auto (7) |  |
| 463 | `2026_08_10_000002_add_spend_voucher_permissions` | Finance | SCHEMA COMPARISON | DATA | MANUAL |  |
| 464 | `2026_08_10_000003_link_petty_cash_to_planned_cost_lines` | Finance | SCHEMA COMPARISON |  | auto (1) superseded |  |
| 465 | `2026_08_10_000004_add_disbursement_idempotency_key` | Finance | SCHEMA COMPARISON |  | auto (1) superseded |  |
| 466 | `2026_08_10_000005_streamline_petty_cash_disbursements` | Finance | SCHEMA COMPARISON |  | auto (6) superseded |  |
| 467 | `2026_08_10_000006_create_direct_disbursement_requests` | Finance | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 468 | `2026_08_10_235000_add_reversal_fields_to_enquiry_payments` | root | SCHEMA COMPARISON |  | auto (3) |  |
| 469 | `2026_08_10_235100_add_project_receivables_permissions` | root | SCHEMA COMPARISON | DATA | MANUAL |  |
| 470 | `2026_08_10_235200_add_verification_to_enquiry_payments` | root | SCHEMA COMPARISON | DATA | auto (3) |  |
| 471 | `2026_08_10_235300_add_receivables_verify_permission` | root | SCHEMA COMPARISON | DATA | MANUAL |  |
| 472 | `2026_08_10_235400_add_receipt_source_and_evidence_to_enquiry_payments` | root | SCHEMA COMPARISON |  | auto (2) |  |
| 473 | `2026_08_10_235500_create_client_receipts_and_link_allocations` | root | SCHEMA COMPARISON |  | auto (2) | unguarded create |
| 474 | `2026_08_10_235600_add_mobilization_threshold_to_project_enquiries` | root | SCHEMA COMPARISON |  | auto (1) |  |
| 475 | `2026_08_11_000008_add_link_support_to_design_documents_table` | Design | SCHEMA COMPARISON | IDEMPOTENT | auto (2) |  |
| 476 | `2026_08_11_000009_expand_design_item_statuses` | Design | SCHEMA COMPARISON | DESTRUCTIVE | auto (1) | ->change() x1 |
| 477 | `2026_08_11_000100_create_project_invoices` | root | SCHEMA COMPARISON |  | auto (2) | unguarded create |
| 478 | `2026_08_11_000200_add_expense_code_to_requisition_items` | Finance | SCHEMA COMPARISON |  | auto (1) |  |
| 479 | `2026_08_11_000300_add_gl_accounts_to_tax_tables` | Finance | SCHEMA COMPARISON |  | auto (2) |  |
| 480 | `2026_08_11_085507_add_link_support_to_design_assets_table` | root | SCHEMA COMPARISON | DESTRUCTIVE | auto (5) | ->change() x3 |
| 481 | `2026_08_11_090000_add_project_cost_account_and_budget_addition_permissions` | root | SCHEMA COMPARISON | DATA | MANUAL |  |
| 482 | `2026_08_12_000001_create_printing_tables` | Printing | SCHEMA COMPARISON |  | auto (6) | unguarded create |
| 483 | `2026_08_12_000010_add_response_fields_to_design_handoffs_table` | Design | SCHEMA COMPARISON |  | auto (3) |  |
| 484 | `2026_08_12_090000_add_expense_code_management_permission` | root | SCHEMA COMPARISON | DATA | MANUAL |  |
| 485 | `2026_08_12_100000_add_self_approval_permission` | root | SCHEMA COMPARISON |  | MANUAL |  |
| 486 | `2026_08_12_120000_link_inventory_returns_to_original_issues` | ProcurementStores | SCHEMA COMPARISON |  | auto (1) |  |
| 487 | `2026_08_12_130000_link_inventory_issues_to_project_material_lines` | ProcurementStores | SCHEMA COMPARISON |  | auto (1) |  |
| 488 | `2026_08_12_150000_link_board_requests_to_project_materials` | ProcurementStores | SCHEMA COMPARISON |  | auto (3) |  |
| 489 | `2026_08_13_000001_add_store_confirmation_to_goods_receipt_note_items_table` | ProcurementStores | SCHEMA COMPARISON |  | auto (4) |  |
| 490 | `2026_08_13_000001_add_unique_design_handoff_to_print_jobs` | Printing | SCHEMA COMPARISON |  | MANUAL |  |
| 491 | `2026_08_13_000002_add_store_status_to_goods_receipt_notes_table` | ProcurementStores | SCHEMA COMPARISON |  | auto (1) |  |
| 492 | `2026_08_13_000003_add_unit_price_to_inventory_logs_table` | ProcurementStores | SCHEMA COMPARISON |  | auto (1) |  |
| 493 | `2026_08_13_120000_add_atomic_custody_to_boards` | ProcurementStores | SCHEMA COMPARISON | DATA | auto (8) |  |
| 494 | `2026_08_13_150000_add_quarantine_review_to_boards` | ProcurementStores | SCHEMA COMPARISON |  | auto (6) |  |
| 495 | `2026_08_13_180000_create_board_return_batches` | ProcurementStores | SCHEMA COMPARISON |  | auto (2) | unguarded create |
| 496 | `2026_08_13_190000_add_finance_sync_state_to_inventory_logs` | ProcurementStores | SCHEMA COMPARISON |  | auto (4) |  |
| 497 | `2026_08_13_210000_create_stores_finance_postings` | ProcurementStores | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 498 | `2026_08_13_220000_add_valuation_resolution_to_stores_finance_postings` | ProcurementStores | SCHEMA COMPARISON |  | auto (4) |  |
| 499 | `2026_08_14_000001_add_design_print_dimensions_to_print_jobs` | Printing | SCHEMA COMPARISON | DATA | auto (5) |  |
| 500 | `2026_08_14_000002_add_tile_count_to_print_job_consumptions` | Printing | SCHEMA COMPARISON |  | auto (1) |  |
| 501 | `2026_08_14_090000_add_return_kind_to_inventory_logs` | ProcurementStores | SCHEMA COMPARISON | DATA | auto (1) |  |
| 502 | `2026_08_17_000001_allow_reprints_for_design_handoffs` | Printing | SCHEMA COMPARISON | DATA IDEMPOTENT | auto (1) |  |
| 503 | `2026_08_17_000010_add_redesign_links_to_design_items` | Design | SCHEMA COMPARISON | IDEMPOTENT | auto (5) |  |
| 504 | `2026_08_18_000001_grant_finance_reports_view_permission` | Finance | SCHEMA COMPARISON | DATA | MANUAL |  |
| 505 | `2026_08_18_000002_remove_budget_addition_permissions` | root | SCHEMA COMPARISON | DATA DESTRUCTIVE | MANUAL | deletes rows |
| 506 | `2026_08_19_000001_add_tax_document_fields_to_cost_lines` | Finance | SCHEMA COMPARISON |  | auto (4) |  |
| 507 | `2026_08_19_000001_restore_annual_leave_monthly_accrual` | HR | SCHEMA COMPARISON | DATA | MANUAL |  |
| 508 | `2026_08_19_000002_seed_tax_return_due_day_setting` | Finance | SCHEMA COMPARISON | DATA | MANUAL |  |
| 509 | `2026_08_19_120000_add_unique_material_to_stocks_table` | ProcurementStores | SCHEMA COMPARISON |  | MANUAL |  |
| 510 | `2026_08_19_140000_add_packaging_units_of_measure` | MaterialsLibrary | SCHEMA COMPARISON | DATA IDEMPOTENT | MANUAL |  |
| 511 | `2026_08_19_150000_relax_material_identity_columns` | MaterialsLibrary | SCHEMA COMPARISON | DESTRUCTIVE | auto (2) | ->change() x2 |
| 512 | `2026_08_19_160000_widen_material_category_code` | MaterialsLibrary | SCHEMA COMPARISON | DESTRUCTIVE | auto (1) | ->change() x1 |
| 513 | `2026_08_20_120000_create_material_attribute_normalization_audit` | MaterialsLibrary | SCHEMA COMPARISON |  | auto (2) | unguarded create |
| 514 | `2026_08_20_130000_add_uom_evidence_to_inventory_logs` | ProcurementStores | SCHEMA COMPARISON |  | auto (3) |  |
| 515 | `2026_08_20_140000_link_goods_receipts_to_inventory` | ProcurementStores | SCHEMA COMPARISON |  | auto (5) | raw SQL x3 |
| 516 | `2026_08_20_150000_add_buying_units_to_procurement_lines` | ProcurementStores | SCHEMA COMPARISON |  | auto (2) | raw SQL x3 |
| 517 | `2026_08_20_160000_create_stock_counts` | ProcurementStores | SCHEMA COMPARISON |  | auto (2) | unguarded create |
| 518 | `2026_08_20_170000_create_goods_receipt_inspections` | ProcurementStores | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 519 | `2026_08_21_120000_add_default_unit_cost_to_library_materials` | MaterialsLibrary | SCHEMA COMPARISON |  | auto (1) |  |
| 520 | `2026_08_23_000001_add_excel_quote_extraction_to_task_quote_data_table` | root | SCHEMA COMPARISON |  | auto (1) |  |
| 521 | `2026_08_23_000001_create_spend_voucher_allocations_table` | Finance | SCHEMA COMPARISON | DATA IDEMPOTENT | auto (1) |  |
| 522 | `2026_08_23_000002_add_quote_source_metadata_to_material_planning_tables` | root | SCHEMA COMPARISON |  | MANUAL |  |
| 523 | `2026_08_23_000002_create_petty_cash_requisition_types` | root | SCHEMA COMPARISON | DATA | auto (5) | unguarded create |
| 524 | `2026_08_23_000003_create_petty_cash_offline_batches` | root | SCHEMA COMPARISON |  | auto (2) | unguarded create |
| 525 | `2026_08_23_000010_add_requirement_quantity_to_project_elements` | root | SCHEMA COMPARISON |  | auto (2) |  |
| 526 | `2026_08_23_000011_expand_project_element_identity_columns` | root | SCHEMA COMPARISON | DESTRUCTIVE | auto (2) | ->change() x2 |
| 527 | `2026_08_23_090000_add_venue_coordinates_to_project_enquiries_table` | root | SCHEMA COMPARISON |  | auto (3) |  |
| 528 | `2026_08_23_120000_add_delivery_date_status_to_project_enquiries_table` | root | SCHEMA COMPARISON | DATA | auto (2) |  |
| 529 | `2026_08_24_000001_link_payroll_runs_to_finance` | HR | SCHEMA COMPARISON | DATA IDEMPOTENT | auto (5) |  |
| 530 | `2026_08_24_000002_add_materials_stores_permissions` | root | SCHEMA COMPARISON |  | MANUAL |  |
| 531 | `2026_08_29_120000_add_opening_inventory_to_stock_counts` | ProcurementStores | SCHEMA COMPARISON |  | auto (6) |  |
| 532 | `2026_08_30_000001_add_schema_document_to_petty_cash_requisition_types` | root | SCHEMA COMPARISON |  | auto (2) |  |
| 533 | `2026_08_30_000002_add_requisition_type_management_permission` | root | SCHEMA COMPARISON | DATA | MANUAL |  |
| 534 | `2026_08_30_000003_add_payment_defaults_to_requisition_types` | root | SCHEMA COMPARISON |  | auto (2) |  |
| 535 | `2026_08_31_000001_grant_requisition_type_management_to_finance_roles` | root | SCHEMA COMPARISON | DATA | MANUAL |  |
| 536 | `2026_08_31_000002_sync_roles_to_permission_matrix` | root | SCHEMA COMPARISON | DATA | MANUAL |  |
| 537 | `2026_09_05_000001_add_supplier_invoice_verification_to_bills` | ProcurementStores | SCHEMA COMPARISON | DATA | auto (6) |  |
| 538 | `2026_09_05_000001_align_requisition_types_with_expense_catalogue` | Finance | SCHEMA COMPARISON | DATA IDEMPOTENT | MANUAL |  |
| 539 | `2026_09_05_000002_give_requisition_categories_a_face` | Finance | SCHEMA COMPARISON | DATA | MANUAL |  |
| 540 | `2026_09_06_000001_add_is_procurable_to_expense_codes` | Finance | SCHEMA COMPARISON | DATA | auto (1) |  |
| 541 | `2026_09_06_000001_stop_asking_journey_purpose_twice` | Finance | SCHEMA COMPARISON |  | MANUAL |  |
| 542 | `2026_09_07_000001_exclude_staff_allowances_from_procurement` | Finance | SCHEMA COMPARISON | DATA | MANUAL |  |
| 543 | `2026_09_07_000002_widen_the_non_project_purchase_catalogue` | Finance | SCHEMA COMPARISON | DATA | MANUAL |  |
| 544 | `2026_09_07_000003_give_recorded_costs_their_cost_centre` | Finance | SCHEMA COMPARISON |  | MANUAL | raw SQL x4 |
| 545 | `2026_09_07_000004_give_supplier_payments_a_finance_payment_source` | Finance | SCHEMA COMPARISON | DATA IDEMPOTENT | auto (1) superseded |  |
| 546 | `2026_09_07_000005_link_requisition_payment_sources` | Finance | SCHEMA COMPARISON | DESTRUCTIVE | auto (3) | ->change() x1; raw SQL x1 |
| 547 | `2026_09_07_000006_let_the_cash_ledger_name_its_source` | Finance | SCHEMA COMPARISON | IDEMPOTENT | auto (2) | raw SQL x3 |
| 548 | `2026_09_07_120000_add_inventory_visibility_to_library_materials` | MaterialsLibrary | SCHEMA COMPARISON |  | auto (1) |  |
| 549 | `2026_09_08_000001_give_invoices_lines_and_a_ledger_link` | root | SCHEMA COMPARISON |  | auto (5) | unguarded create |
| 550 | `2026_09_08_000002_let_departments_declare_direct_or_overhead_labour` | root | SCHEMA COMPARISON |  | auto (1) |  |
| 551 | `2026_09_08_000100_add_tax_identity_to_bills` | ProcurementStores | SCHEMA COMPARISON | DATA | auto (8) |  |
| 552 | `2026_09_09_000000_create_document_sequences_table` | Finance | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 553 | `2026_09_09_000001_unify_payment_architecture` | Finance | SCHEMA COMPARISON | DATA DESTRUCTIVE | auto (11) | dropColumn x1; renameColumn x3; raw SQL x3; raw DROP/TRUNCATE/DELETE |
| 554 | `2026_09_09_000002_add_payment_source_management_permission` | root | SCHEMA COMPARISON | DATA | MANUAL |  |
| 555 | `2026_09_09_000002_allow_one_payment_to_settle_several_invoices` | Finance | SCHEMA COMPARISON | DESTRUCTIVE | MANUAL | raw SQL x2; raw DROP/TRUNCATE/DELETE |
| 556 | `2026_09_09_000010_add_expenditure_exception_permission` | root | SCHEMA COMPARISON |  | MANUAL |  |
| 557 | `2026_09_09_000011_add_budget_exception_to_petty_cash_requisitions` | root | SCHEMA COMPARISON |  | auto (1) |  |
| 558 | `2026_09_10_000100_add_read_indexes_to_inventory_logs_and_boards` | ProcurementStores | SCHEMA COMPARISON | IDEMPOTENT | MANUAL |  |
| 559 | `2026_09_12_000001_add_surrender_workflow_to_petty_cash` | root | SCHEMA COMPARISON |  | auto (10) | raw SQL x1; unguarded create |
| 560 | `2026_09_12_000002_narrow_procurement_expense_codes` | root | SCHEMA COMPARISON | DATA | MANUAL |  |
| 561 | `2026_09_13_000001_create_period_audit_logs_table` | Finance | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 562 | `2026_09_13_000002_create_finance_reconciliation_tables` | Finance | SCHEMA COMPARISON |  | auto (3) | unguarded create |
| 563 | `2026_09_13_000003_create_finance_cash_movements_table` | Finance | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 564 | `2026_09_13_000004_seed_reconciliation_date_tolerance_setting` | Finance | SCHEMA COMPARISON | DATA IDEMPOTENT | MANUAL |  |
| 565 | `2026_09_13_000005_create_finance_work_assignments_table` | Finance | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 566 | `2026_09_13_000006_create_finance_work_assignment_events_table` | Finance | SCHEMA COMPARISON |  | auto (1) | unguarded create |
| 567 | `2026_09_13_000007_link_spend_vouchers_to_payments` | Finance | SCHEMA COMPARISON | DATA IDEMPOTENT | auto (1) |  |
| 568 | `2026_09_13_000008_change_payments_idempotency_key_to_string` | Finance | SCHEMA COMPARISON | DESTRUCTIVE | auto (1) | ->change() x1 |
| 569 | `2026_09_14_000001_add_can_make_payment_to_payment_sources_table` | root | SCHEMA COMPARISON | DATA | auto (1) |  |
| 570 | `2026_09_14_000002_add_payment_inverse_relationships` | root | SCHEMA COMPARISON |  | auto (3) | raw SQL x1 |
| 571 | `2026_09_14_000003_create_payment_allocations_table` | root | SCHEMA COMPARISON |  | auto (1) | raw SQL x1; unguarded create |
| 572 | `2026_09_14_000004_add_settled_by_payment_id_to_cost_lines` | root | SCHEMA COMPARISON |  | auto (1) | raw SQL x2 |
| 573 | `2026_09_15_000001_add_settled_by_bill_id_to_cost_lines` | root | SCHEMA COMPARISON |  | auto (1) | raw SQL x1 |
| 574 | `2026_09_15_000002_add_direct_bill_support` | root | SCHEMA COMPARISON | DESTRUCTIVE | auto (6) | ->change() x1 |
| 575 | `2026_09_15_000003_add_transaction_cost_to_spend_vouchers` | root | SCHEMA COMPARISON |  | auto (1) |  |
| 576 | `2026_09_16_000001_add_trigger_reason_to_requisitions_table` | root | SCHEMA COMPARISON |  | auto (1) |  |
| 577 | `2026_09_16_120000_create_uncategorized_material_category` | MaterialsLibrary | SCHEMA COMPARISON | DATA IDEMPOTENT | MANUAL |  |
| 578 | `2026_09_17_000001_add_partial_fulfilment_to_print_material_requests` | Printing | SCHEMA COMPARISON | DATA DESTRUCTIVE | auto (1) | DROP-THEN-CREATE; raw SQL x1; unguarded create |
| 579 | `2026_09_17_000002_add_purchase_request_marker_to_print_material_requests` | Printing | SCHEMA COMPARISON | DATA | auto (1) |  |
| 580 | `2026_09_17_000003_add_manual_origin_to_print_jobs` | Printing | SCHEMA COMPARISON |  | auto (5) |  |
| 581 | `2026_09_17_000004_add_variance_reason_code_to_print_consumptions` | Printing | SCHEMA COMPARISON | DATA | auto (1) |  |
| 582 | `2026_09_21_000001_add_ignore_reason_to_finance_statement_transactions` | root | SCHEMA COMPARISON |  | auto (1) |  |
| 583 | `2026_09_21_000002_add_locked_by_and_paid_by_to_payroll_runs` | root | SCHEMA COMPARISON |  | auto (2) |  |
| 584 | `2026_09_21_000003_add_credit_note_support_to_project_invoices` | root | SCHEMA COMPARISON |  | auto (1) |  |
| 585 | `2026_09_22_000001_restrict_purchase_order_cascade_deletes` | ProcurementStores | PHASE 2B NEW |  | MANUAL |  |
| 586 | `2026_09_22_000002_add_advance_gl_posting_status_to_petty_cash_requisitions` | root | PHASE 2B NEW |  | auto (2) |  |
| 587 | `2026_09_22_000003_add_payment_id_to_payroll_runs` | root | PHASE 2B NEW |  | auto (1) |  |
| 588 | `2026_09_23_000001_add_return_for_correction_to_purchase_orders` | ProcurementStores | PHASE 2B NEW |  | auto (4) |  |
| 589 | `2026_09_23_000001_create_payment_terms_table` | root | PHASE 2B NEW |  | auto (1) | unguarded create |
| 590 | `2026_09_23_000002_add_senior_approval_to_purchase_orders` | ProcurementStores | PHASE 2B NEW |  | auto (3) |  |
| 591 | `2026_09_23_000002_create_finance_attachments_table` | root | PHASE 2B NEW |  | auto (1) | unguarded create |
| 592 | `2026_09_23_000003_add_duplicate_detection_to_bills_and_bill_payments` | ProcurementStores | PHASE 2B NEW |  | auto (8) |  |
| 593 | `2026_09_23_000003_add_review_workflow_to_project_invoices` | root | PHASE 2B NEW |  | auto (12) |  |
| 594 | `2026_09_23_000004_add_discount_to_project_invoice_lines` | root | PHASE 2B NEW | DATA | auto (2) |  |
| 595 | `2026_09_23_000004_create_purchase_order_amendments_table` | ProcurementStores | PHASE 2B NEW |  | auto (1) | unguarded create |
| 596 | `2026_09_23_000005_add_cost_gl_posting_status_to_payments` | root | PHASE 2B NEW |  | auto (2) |  |
| 597 | `2026_09_23_000005_create_purchase_order_corrections_table` | ProcurementStores | PHASE 2B NEW |  | auto (1) | unguarded create |
| 598 | `2026_09_23_000006_add_wave_3_expense_controls` | root | PHASE 2B NEW | DATA | auto (37) | raw SQL x1; unguarded create |
| 599 | `2026_09_23_000007_create_petty_cash_control_records` | root | PHASE 2B NEW |  | auto (3) | unguarded create |
| 600 | `2026_09_24_000001_create_cost_line_allocations_table` | root | PHASE 2B NEW |  | auto (1) | unguarded create |
| 601 | `2026_09_24_000002_create_cost_line_transfers_table` | root | PHASE 2B NEW |  | auto (1) | unguarded create |
| 602 | `2026_09_24_000003_add_financial_closure_to_project_enquiries` | root | PHASE 2B NEW |  | auto (6) |  |
| 603 | `2026_09_24_000004_add_w6_project_costing_permissions` | root | PHASE 2B NEW | DATA | MANUAL |  |
| 604 | `2026_09_24_000005_create_project_labour_actuals_table` | root | PHASE 2B NEW |  | auto (1) | unguarded create |
| 605 | `2026_09_24_000006_add_w7_labour_cost_permissions` | root | PHASE 2B NEW | DATA | MANUAL |  |
| 606 | `2026_09_24_000007_harden_project_labour_actuals` | root | PHASE 2B NEW |  | auto (3) |  |
| 607 | `2026_09_28_000001_w7_labour_remediation` | root | PHASE 2B NEW |  | auto (7) | unguarded create |
