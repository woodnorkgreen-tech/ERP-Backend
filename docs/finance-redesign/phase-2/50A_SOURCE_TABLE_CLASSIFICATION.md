# 50A — Source Table Classification (appendix to Report 50)

**Generated:** 2026-09-28.

**Source schema:** built locally from source commit `5bf4ab3` (`master`, 2026-08-19) by running its own 445 migrations from empty. Two mis-dated asset migrations were reordered, as the target itself later did.

**Target schema:** built from branch HEAD `3093a33` by running all 607 migrations from empty.

**Caveat:** this is the migration-declared schema. The live `woodnork_erpsystem` must be compared against it, using the operator export described in Report 50 §6.

**Column meanings:**
- **Class:** what happens to the table's rows.
- **Structure:** the target table compared with the source table.
- **ID:** the primary-key policy.

**Totals:** {'IMPORT (DATA-1)': 70, 'IMPORT (operational, default)': 139, 'WNG DECISION': 7, 'SKIP (transient)': 10, 'EXCLUDE': 14, 'REGENERATE': 3}

| Source table | Module | Class | Structure (target vs source) | ID | Basis / notes |
|---|---|---|---|---|---|
| `action_logs` | Audit | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `active_trip_locations` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `announcement_reads` | Comms | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `announcements` | Comms | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `app_notification_preferences` | Comms | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `app_notifications` | Comms | IMPORT (operational, default) | ADDITIVE/CHANGED (FK rules) | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `archival_item_placements` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `archival_reports` | Projects | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `archival_setup_items` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `asset_assignment_history` | Assets | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `asset_categories` | Assets | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `asset_hire_requests` | Assets | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `asset_service_logs` | Assets | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `assets` | Assets | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `attendance_device_raw_events` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `attendance_device_sync_logs` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `attendance_holidays` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `attendance_records` | HR | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `attendance_schedule_assignments` | HR | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `attendance_sync_requests` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `attendance_work_schedules` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) — target migrations seed rows: replace with staging rows |
| `bill_payments` | Other | WNG DECISION | ADDITIVE/CHANGED (+7 col; -payment_method_id; FK rules) | PRESERVE | CONFIRM_ON_PRODUCTION (Report 47): import only if real; else excluded |
| `bills` | Other | WNG DECISION | ADDITIVE/CHANGED (+23 col; widened/relaxed: purchase_order_id; FK rules) | PRESERVE | CONFIRM_ON_PRODUCTION (Report 47): import only if real; else excluded |
| `board_movements` | Stores | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `board_reconciliations` | Stores | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `board_requests` | Stores | IMPORT (operational, default) | ADDITIVE/CHANGED (+3 col; FK rules) | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `board_workflow_tasks` | Stores | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `boards` | Stores | IMPORT (DATA-1) | ADDITIVE/CHANGED (+14 col; FK rules) | PRESERVE | Report 47 PROTECTED |
| `budget_additions` | Projects | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `budget_approvals` | Projects | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `budget_versions` | Projects | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `cache` | Other | SKIP (transient) | IDENTICAL | n/a | target creates its own; users re-authenticate |
| `cache_locks` | Other | SKIP (transient) | IDENTICAL | n/a | target creates its own; users re-authenticate |
| `chart_of_accounts` | Finance master | WNG DECISION | ADDITIVE/CHANGED (+4 col; FK rules) | PRESERVE | source chart vs target reference chart (§26) |
| `client_interactions` | CRM | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `clients` | CRM | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `compensations` | HR/overtime | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `daily_issues` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `daily_tasks` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `deliverables_blueprints` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `deliveries` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `delivery_stops` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `departments` | Org | IMPORT (DATA-1) | ADDITIVE/CHANGED (+1 col) | PRESERVE | Report 47 PROTECTED |
| `design_assets` | Assets | IMPORT (DATA-1) | ADDITIVE/CHANGED (+2 col; widened/relaxed: mime_type,file_size,file_path) | PRESERVE | Report 47 PROTECTED |
| `design_requirements` | Projects | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `disciplinary_activity_logs` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `disciplinary_cases` | HR | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `disciplinary_comments` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `dispatch_batches` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `drivers` | Logistics | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `element_materials` | Materials | IMPORT (DATA-1) | ADDITIVE/CHANGED (+1 col) | PRESERVE | Report 47 PROTECTED |
| `element_template_materials` | Materials | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `element_templates` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `element_types` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `employee_certifications` | HR | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `employee_documents` | HR | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `employee_salary_histories` | HR | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `employee_skills` | HR | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `employee_staging_records` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `employees` | HR | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `enquiry_payments` | Projects | EXCLUDE | ADDITIVE/CHANGED (+10 col; FK rules) | n/a | Q2 client receipts |
| `enquiry_task_user` | Projects | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `enquiry_tasks` | UniversalTask | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `events` | Comms | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `failed_jobs` | Other | SKIP (transient) | IDENTICAL | n/a | target creates its own; users re-authenticate |
| `goods_receipt_note_items` | Other | WNG DECISION | ADDITIVE/CHANGED (+5 col; widened/relaxed: received_quantity,ordered_quantity; FK rules) | PRESERVE | CONFIRM_ON_PRODUCTION (Report 47): import only if real; else excluded |
| `goods_receipt_notes` | Other | WNG DECISION | ADDITIVE/CHANGED (FK rules) | PRESERVE | CONFIRM_ON_PRODUCTION (Report 47): import only if real; else excluded |
| `governance_audit_logs` | Audit | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `grievance_activity_logs` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `grievance_comments` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `grievances` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `handover_surveys` | Projects | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `hr_action_attachments` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_action_types` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) — target migrations seed rows: replace with staging rows |
| `hr_actions` | HR | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `hr_audit_logs` | HR | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `hr_candidate_documents` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_candidate_education` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_candidate_experiences` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_candidate_references` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_candidates` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_interviews` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_job_postings` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_offboarding_activity_logs` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_offboarding_asset_returns` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_offboarding_attachments` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_offboarding_cards` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_offboarding_cases` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_offboarding_clearances` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_offboarding_exit_interviews` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_offboarding_final_settlements` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_offboarding_tasks` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_onboarding_activity_logs` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_onboarding_cards` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_onboarding_cases` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_onboarding_document_requirements` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_onboarding_handovers` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_onboarding_reviews` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_onboarding_tasks` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `hr_onboarding_welcome_kit_items` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `incident_activity_logs` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `incident_comments` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `incidents` | HR | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `inventory_logs` | Stores | IMPORT (DATA-1) | ADDITIVE/CHANGED (+10 col; FK rules) | PRESERVE | Report 47 PROTECTED |
| `inventory_lots` | Stores | IMPORT (operational, default) | ADDITIVE/CHANGED (FK rules) | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `inventory_movement_allocations` | Stores | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `inventory_serial_items` | Stores | IMPORT (operational, default) | ADDITIVE/CHANGED (FK rules) | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `job_batches` | Other | SKIP (transient) | IDENTICAL | n/a | target creates its own; users re-authenticate |
| `job_cards` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `jobs` | Other | SKIP (transient) | IDENTICAL | n/a | target creates its own; users re-authenticate |
| `leave_balance_adjustments` | HR | IMPORT (DATA-1) | ADDITIVE/CHANGED (FK rules) | PRESERVE | Report 47 PROTECTED |
| `leave_handovers` | HR | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `leave_requests` | HR | IMPORT (DATA-1) | ADDITIVE/CHANGED (FK rules) | PRESERVE | Report 47 PROTECTED |
| `leave_types` | HR | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED — target migrations seed rows: replace with staging rows |
| `ledger_entries` | HR/overtime | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `library_materials` | Materials | IMPORT (DATA-1) | ADDITIVE/CHANGED (+2 col; widened/relaxed: workstation_id,unit_of_measure) | PRESERVE | Report 47 PROTECTED |
| `locations` | Comms | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `logistics_checklist_items` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `logistics_checklists` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `logistics_loading_confirmation_links` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `logistics_log_entries` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `logistics_manifest_links` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `logistics_manifest_submissions` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `logistics_return_confirmation_links` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `logistics_tasks` | Logistics | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `material_categories` | Materials | IMPORT (DATA-1) | ADDITIVE/CHANGED (widened/relaxed: code) | PRESERVE | Report 47 PROTECTED — target migrations seed rows: replace with staging rows |
| `material_item_types` | Materials | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) — target migrations seed rows: replace with staging rows |
| `material_uom_conversions` | Materials | IMPORT (operational, default) | ADDITIVE/CHANGED (FK rules) | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `material_versions` | Materials | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `material_workstations` | Materials | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `migrations` | Other | SKIP (transient) | IDENTICAL | n/a | target creates its own; users re-authenticate |
| `model_has_permissions` | Security | REGENERATE | IDENTICAL | PRESERVE | map by permission name; drop names absent from target |
| `model_has_roles` | Security | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `ncr_reports` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `ot_entries` | HR/overtime | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `ot_flags` | HR/overtime | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `password_reset_tokens` | Other | SKIP (transient) | IDENTICAL | n/a | target creates its own; users re-authenticate |
| `payment_methods` | Other | EXCLUDE | only-source (dropped in target) | n/a | dropped in target (unified into payment_sources) |
| `payroll_ledgers` | HR ref | EXCLUDE | ADDITIVE/CHANGED (+1 col; FK rules) | n/a | Q3 |
| `payroll_runs` | HR ref | EXCLUDE | ADDITIVE/CHANGED (+8 col; FK rules) | n/a | Q3 |
| `payroll_tax_bands` | HR ref | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `payroll_variables` | HR ref | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `payslips` | Other | EXCLUDE | IDENTICAL | n/a | Q3 (payroll transaction) |
| `performance_reviews` | HR | IMPORT (DATA-1) | ADDITIVE/CHANGED (FK rules) | PRESERVE | Report 47 PROTECTED |
| `permissions` | Security | REGENERATE | IDENTICAL | PRESERVE | seeded + permissions:sync |
| `personal_access_tokens` | Other | SKIP (transient) | IDENTICAL | n/a | target creates its own; users re-authenticate |
| `petty_cash_activity_logs` | Other | EXCLUDE | IDENTICAL | n/a | Q1 |
| `petty_cash_balances` | Other | EXCLUDE | ADDITIVE/CHANGED (+1 col) | n/a | Q1; float recreated at 0.00 |
| `petty_cash_disbursement_allocations` | Comms | EXCLUDE | ADDITIVE/CHANGED (FK rules) | n/a | Q1 |
| `petty_cash_disbursements` | Other | EXCLUDE | only-source (dropped in target) | n/a | renamed to payments in target; Q1 reset |
| `petty_cash_ledger_entries` | HR/overtime | EXCLUDE | ADDITIVE/CHANGED (+2 col) | n/a | Q1 |
| `petty_cash_requisition_items` | Procurement | EXCLUDE | ADDITIVE/CHANGED (+1 col) | n/a | Q1 |
| `petty_cash_requisitions` | Procurement | EXCLUDE | ADDITIVE/CHANGED (+26 col; widened/relaxed: status; FK rules) | n/a | Q1 |
| `petty_cash_top_ups` | Other | EXCLUDE | ADDITIVE/CHANGED (+1 col; -transaction_code; widened/relaxed: payment_method) | n/a | Q1 |
| `production_completion_criteria` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `production_defect_codes` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) — target migrations seed rows: replace with staging rows |
| `production_elements` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `production_issues` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `production_ncr_assignments` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `production_ncr_closures` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `production_ncr_events` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `production_ncrs` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `production_quality_checkpoints` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `production_root_cause_codes` | HR/overtime | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) — target migrations seed rows: replace with staging rows |
| `profile_update_requests` | HR | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `project_deliverables` | Logistics | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `project_elements` | Projects | IMPORT (DATA-1) | ADDITIVE/CHANGED (+3 col; widened/relaxed: element_type,name) | PRESERVE | Report 47 PROTECTED |
| `project_enquiries` | Projects | IMPORT (DATA-1) | ADDITIVE/CHANGED (+12 col) | PRESERVE | Report 47 PROTECTED |
| `projects` | Projects | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `public_leads` | CRM | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `purchase_order_items` | Other | WNG DECISION | ADDITIVE/CHANGED (+1 col; widened/relaxed: quantity; FK rules) | PRESERVE | CONFIRM_ON_PRODUCTION (Report 47): import only if real; else excluded |
| `purchase_orders` | Other | WNG DECISION | ADDITIVE/CHANGED (+7 col; FK rules) | PRESERVE | CONFIRM_ON_PRODUCTION (Report 47): import only if real; else excluded |
| `quote_approvals` | Projects | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `quote_versions` | Projects | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `requisition_items` | Procurement | IMPORT (DATA-1) | ADDITIVE/CHANGED (+2 col; widened/relaxed: quantity; FK rules) | PRESERVE | Report 47 PROTECTED |
| `requisitions` | Procurement | IMPORT (DATA-1) | ADDITIVE/CHANGED (+1 col) | PRESERVE | Report 47 PROTECTED |
| `role_has_permissions` | Security | REGENERATE | IDENTICAL | PRESERVE | permissions:sync from RolePermissions matrix |
| `roles` | Security | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `salary_advance_requests` | Other | EXCLUDE | ADDITIVE/CHANGED (+4 col; FK rules) | n/a | Q3 |
| `sessions` | Other | SKIP (transient) | IDENTICAL | n/a | target creates its own; users re-authenticate |
| `setdown_checklists` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `setdown_task_issues` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `setdown_task_photos` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `setdown_tasks` | UniversalTask | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `setup_task_issues` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `setup_task_photos` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `setup_tasks` | UniversalTask | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `site_surveys` | Projects | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `stocks` | Stores | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `suppliers` | Procurement | IMPORT (DATA-1) | ADDITIVE/CHANGED (+7 col; FK rules) | PRESERVE | Report 47 PROTECTED |
| `support_ticket_activities` | Support | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `support_ticket_attachments` | Support | IMPORT (operational, default) | ADDITIVE/CHANGED (FK rules) | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `support_ticket_messages` | Support | IMPORT (operational, default) | ADDITIVE/CHANGED (FK rules) | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `support_tickets` | Support | IMPORT (operational, default) | ADDITIVE/CHANGED (FK rules) | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `system_events` | Comms | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `task_assignment_history` | Projects | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `task_assignments` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `task_attachments` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `task_budget_data` | Projects | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `task_comments` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `task_dependencies` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `task_experience_logs` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `task_history` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `task_issues` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `task_materials_data` | Materials | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `task_procurement_data` | Projects | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `task_production_data` | Production | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `task_quote_data` | Projects | IMPORT (DATA-1) | ADDITIVE/CHANGED (+1 col) | PRESERVE | Report 47 PROTECTED |
| `task_saved_views` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `task_templates` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `task_time_entries` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `tasks` | UniversalTask | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `team_categories` | Projects ref | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `team_category_types` | Projects ref | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `team_types` | Projects ref | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `teams_activity_logs` | Projects | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `teams_members` | Projects | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `teams_tasks` | UniversalTask | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `technical_labours` | Hist. labour | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `transport_items` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `trip_requests` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `units_of_measure` | Materials | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED — target migrations seed rows: replace with staging rows |
| `user_device_tokens` | Other | SKIP (transient) | IDENTICAL | n/a | target creates its own; users re-authenticate |
| `users` | Security | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `vehicle_inspections` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `vehicle_maintenance_logs` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `vehicles` | Logistics | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `work_centers` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `work_order_final_qc_checks` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `work_order_handovers` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `work_order_mid_qc_checks` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `work_order_rework_evidence` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `work_order_reworks` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `work_order_scrap_logs` | Production | IMPORT (operational, default) | ADDITIVE/CHANGED (FK rules) | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `work_order_task_assignees` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `work_order_task_evidence` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `work_order_tasks` | Production | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `work_orders` | Production | IMPORT (DATA-1) | IDENTICAL | PRESERVE | Report 47 PROTECTED |
| `workflow_instances` | UniversalTask | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `workflow_tasks` | UniversalTask | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `workflow_template_tasks` | UniversalTask | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `workflow_templates` | UniversalTask | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
| `workstations` | Materials | IMPORT (operational, default) | IDENTICAL | PRESERVE | non-Finance operational history; default carry — WNG may opt out (§26) |
