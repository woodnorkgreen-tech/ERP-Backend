<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$migrations = [
'2025_01_21_000005_create_production_completion_criteria_table',
'2025_11_09_115739_create_budget_additions_table',
'2025_11_09_141802_add_is_additional_to_element_materials_table',
'2025_11_11_015344_enhance_budget_additions_table_with_refined_schema',
'2025_11_18_074550_create_task_procurement_data_table',
'2025_11_19_120100_create_transport_items_table',
'2025_11_19_120200_create_logistics_checklists_table',
'2025_11_19_120300_create_logistics_checklist_items_table',
'2025_11_19_144328_add_images_to_site_surveys_table',
'2025_11_20_041152_create_team_categories_table',
'2025_11_20_041219_create_team_types_table',
'2025_11_20_041241_create_teams_tasks_table',
'2025_11_20_041310_create_team_category_types_table',
'2025_11_20_074809_create_locations_table',
'2025_11_20_091840_create_logistics_log_entries_table',
'2025_11_20_094619_add_status_to_logistics_log_entries_table',
'2025_11_20_121524_create_announcements_table',
'2025_11_20_123207_add_project_officer_id_to_project_enquiries_table',
'2025_11_21_035634_add_survey_photos_to_site_surveys_table',
'2025_11_22_000001_create_archival_reports_table',
'2025_11_22_000002_create_archival_setup_items_table',
'2025_11_22_000003_create_archival_item_placements_table',
'2025_11_23_081437_add_budget_version_tracking_to_task_quote_data',
'2025_11_23_095706_create_quote_versions_table',
'2025_11_23_114230_update_procurement_items_json_structure',
'2025_11_23_160000_create_enquiry_task_user_table',
'2025_11_23_160001_migrate_task_assignments_to_pivot_table',
'2025_11_24_064752_add_setdown_time_to_logistics_log_entries_table',
'2025_11_24_101054_make_project_id_nullable_in_logistics_tasks_table',
'2025_11_25_075111_create_material_versions_table',
'2025_11_25_075112_create_budget_versions_table',
'2025_11_26_032000_add_last_import_date_to_task_budget_data',
'2025_11_26_063900_add_approval_columns_to_task_quote_data',
'2025_11_26_111205_create_events_table',
'2025_11_27_002900_make_teams_tasks_project_id_nullable',
'2025_11_27_062000_recreate_teams_activity_logs_table',
'2025_11_27_062500_recreate_handover_surveys_table',
'2025_11_27_063000_ensure_teams_members_table',
'2025_11_27_070000_add_team_id_to_logistics_tasks_table',
'2025_11_27_071500_create_setup_tasks_tables',
'2025_11_27_083157_create_element_types_table',
'2025_11_27_093333_add_access_token_to_handover_surveys_table',
'2025_11_28_094630_recreate_handover_surveys_with_json_structure',
'2025_11_28_105214_add_alternative_feedback_fields_to_handover_surveys',
'2025_11_28_143056_ensure_setdown_tables_exist',
'2025_11_28_152114_add_project_id_to_setdown_tasks_table',
'2025_11_28_152417_add_user_tracking_columns_to_setdown_tasks_table'
];

foreach ($migrations as $migration) {
    DB::table('migrations')->insert(['migration' => $migration, 'batch' => 14]);
    echo "Marked $migration\n";
}

echo "All migrations marked.\n";