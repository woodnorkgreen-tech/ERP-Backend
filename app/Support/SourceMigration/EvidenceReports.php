<?php

namespace App\Support\SourceMigration;

use App\Constants\RolePermissions;
use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Services\ProjectBudgetAuthority;
use App\Modules\Finance\CostCollector\Services\ProjectLabourActualService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Read-only evidence for the rehearsal, the D2/D4/D5 decisions and the preservation
 * sign-off. Every report is structural: counts, distributions, integrity and IDs.
 * No report selects a password, a salary or a bank detail.
 */
class EvidenceReports
{
    public function __construct(private readonly SchemaInspector $db) {}

    // ── Projects (Report 51 §12) ──────────────────────────────────────────

    public function projects(): array
    {
        $checks = [
            'projects.enquiry_id → project_enquiries' => $this->broken('projects', 'enquiry_id', 'project_enquiries'),
            'project_enquiries.client_id → clients' => $this->broken('project_enquiries', 'client_id', 'clients'),
            'project_enquiries.project_officer_id → users' => $this->broken('project_enquiries', 'project_officer_id', 'users'),
            'project_enquiries.created_by → users' => $this->broken('project_enquiries', 'created_by', 'users'),
            'project_enquiries.department_id → departments' => $this->broken('project_enquiries', 'department_id', 'departments'),
            'enquiry_tasks.project_enquiry_id → project_enquiries' => $this->broken('enquiry_tasks', 'project_enquiry_id', 'project_enquiries'),
            'task_budget_data.enquiry_task_id → enquiry_tasks' => $this->broken('task_budget_data', 'enquiry_task_id', 'enquiry_tasks'),
            'task_quote_data.enquiry_task_id → enquiry_tasks' => $this->broken('task_quote_data', 'enquiry_task_id', 'enquiry_tasks'),
            'quote_approvals.task_id → enquiry_tasks (unenforced)' => $this->broken('quote_approvals', 'task_id', 'enquiry_tasks'),
            'quote_approvals.enquiry_id → project_enquiries (unenforced)' => $this->broken('quote_approvals', 'enquiry_id', 'project_enquiries'),
            'teams_tasks.task_id → enquiry_tasks' => $this->broken('teams_tasks', 'task_id', 'enquiry_tasks'),
            'teams_tasks.project_id → projects' => $this->broken('teams_tasks', 'project_id', 'projects'),
            'teams_members.teams_task_id → teams_tasks' => $this->broken('teams_members', 'teams_task_id', 'teams_tasks'),
            'teams_members.technical_labour_id → technical_labours' => $this->broken('teams_members', 'technical_labour_id', 'technical_labours'),
            'project_deliverables.enquiry_id → project_enquiries' => $this->broken('project_deliverables', 'enquiry_id', 'project_enquiries'),
            'task_materials_data.enquiry_task_id → enquiry_tasks' => $this->broken('task_materials_data', 'enquiry_task_id', 'enquiry_tasks'),
            'project_deliverables.task_materials_data_id → task_materials_data' => $this->broken('project_deliverables', 'task_materials_data_id', 'task_materials_data'),
            'element_materials.project_element_id → project_deliverables' => $this->broken('element_materials', 'project_element_id', 'project_deliverables'),
            'element_materials.library_material_id → library_materials' => $this->broken('element_materials', 'library_material_id', 'library_materials'),
        ];

        return [
            'totals' => $this->counts(['projects', 'project_enquiries', 'clients', 'enquiry_tasks', 'task_budget_data', 'task_quote_data',
                'quote_versions', 'quote_approvals', 'teams_tasks', 'teams_members', 'project_deliverables', 'task_materials_data',
                'project_elements_legacy', 'element_materials', 'budget_versions', 'budget_additions']),
            'project_status' => $this->distribution('projects', 'status'),
            'enquiry_status' => $this->distribution('project_enquiries', 'status'),
            'coverage' => [
                'enquiries_with_a_client' => $this->scalar('SELECT COUNT(*) c FROM project_enquiries WHERE client_id IS NOT NULL'),
                'enquiries_with_a_project_officer' => $this->scalar('SELECT COUNT(*) c FROM project_enquiries WHERE project_officer_id IS NOT NULL'),
                'enquiries_with_tasks' => $this->scalar('SELECT COUNT(DISTINCT project_enquiry_id) c FROM enquiry_tasks'),
                'enquiries_with_budget_data' => $this->scalar("SELECT COUNT(DISTINCT et.project_enquiry_id) c FROM enquiry_tasks et JOIN task_budget_data b ON b.enquiry_task_id = et.id WHERE et.type = 'budget'"),
                'enquiries_with_quote_data' => $this->scalar('SELECT COUNT(DISTINCT et.project_enquiry_id) c FROM enquiry_tasks et JOIN task_quote_data q ON q.enquiry_task_id = et.id'),
                'projects_with_team_tasks' => $this->scalar('SELECT COUNT(DISTINCT project_id) c FROM teams_tasks WHERE project_id IS NOT NULL'),
                'enquiries_with_deliverables' => $this->scalar('SELECT COUNT(DISTINCT enquiry_id) c FROM project_deliverables'),
                'enquiries_with_elements' => $this->scalar('SELECT COUNT(DISTINCT et.project_enquiry_id) c FROM enquiry_tasks et JOIN task_materials_data m ON m.enquiry_task_id = et.id JOIN project_deliverables pe ON pe.task_materials_data_id = m.id'),
            ],
            'relationships' => $checks,
            'broken_relationships' => $this->onlyBroken($checks),
        ];
    }

    // ── Employees (Report 51 §13) — no salary values ─────────────────────

    public function employees(): array
    {
        $checks = [
            'employees.department_id → departments' => $this->broken('employees', 'department_id', 'departments'),
            'employees.manager_id → employees' => $this->broken('employees', 'manager_id', 'employees'),
            'departments.manager_id → employees' => $this->broken('departments', 'manager_id', 'employees'),
            'users.employee_id → employees' => $this->broken('users', 'employee_id', 'employees'),
            'users.department_id → departments' => $this->broken('users', 'department_id', 'departments'),
            'employee_salary_histories.employee_id → employees' => $this->broken('employee_salary_histories', 'employee_id', 'employees'),
            'employee_documents.employee_id → employees' => $this->broken('employee_documents', 'employee_id', 'employees'),
            'employee_skills.employee_id → employees' => $this->broken('employee_skills', 'employee_id', 'employees'),
            'employee_certifications.employee_id → employees' => $this->broken('employee_certifications', 'employee_id', 'employees'),
            'attendance_records.employee_id → employees' => $this->broken('attendance_records', 'employee_id', 'employees'),
            'leave_requests.employee_id → employees' => $this->broken('leave_requests', 'employee_id', 'employees'),
            'hr_actions.employee_id → employees' => $this->broken('hr_actions', 'employee_id', 'employees'),
            'technical_labours.employee_id → employees' => $this->broken('technical_labours', 'employee_id', 'employees'),
            'hr_onboarding_cases.employee_id → employees (unenforced)' => $this->broken('hr_onboarding_cases', 'employee_id', 'employees'),
            'hr_offboarding_cases.employee_id → employees (unenforced)' => $this->broken('hr_offboarding_cases', 'employee_id', 'employees'),
        ];

        return [
            'totals' => $this->counts(['employees', 'users', 'departments', 'employee_salary_histories', 'employee_documents',
                'employee_skills', 'employee_certifications', 'attendance_records', 'leave_requests', 'hr_actions', 'ot_entries',
                'ledger_entries', 'technical_labours']),
            'employee_status' => $this->distribution('employees', 'status'),
            'coverage' => [
                'employees_with_a_department' => $this->scalar('SELECT COUNT(*) c FROM employees WHERE department_id IS NOT NULL'),
                'employees_with_a_manager' => $this->scalar('SELECT COUNT(*) c FROM employees WHERE manager_id IS NOT NULL'),
                'employees_linked_to_a_user' => $this->scalar('SELECT COUNT(DISTINCT employee_id) c FROM users WHERE employee_id IS NOT NULL'),
                'employees_linked_to_more_than_one_user' => $this->scalar('SELECT COUNT(*) c FROM (SELECT employee_id FROM users WHERE employee_id IS NOT NULL GROUP BY employee_id HAVING COUNT(*) > 1) x'),
                // Structural only: whether a salary master exists, never its amount.
                'employees_with_salary_history' => $this->scalar('SELECT COUNT(DISTINCT employee_id) c FROM employee_salary_histories'),
                'employees_with_documents' => $this->scalar('SELECT COUNT(DISTINCT employee_id) c FROM employee_documents'),
            ],
            'relationships' => $checks,
            'broken_relationships' => $this->onlyBroken($checks),
        ];
    }

    // ── Authentication (Report 51 §14) — hashes compared, never printed ──

    public static function authentication(SchemaInspector $source, SchemaInspector $target): array
    {
        $digest = fn (SchemaInspector $db) => $db->connection()->table('users')->orderBy('id')->get(['id', 'password'])
            ->mapWithKeys(fn ($u) => [(string) $u->id => hash('sha256', (string) $u->password)])->all();
        $s = $digest($source);
        $t = $digest($target);

        $missing = array_keys(array_diff_key($s, $t));
        $changed = array_keys(array_filter($s, fn ($hash, $id) => isset($t[$id]) && $t[$id] !== $hash, ARRAY_FILTER_USE_BOTH));

        $roleCounts = fn (SchemaInspector $db) => $db->connection()->table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.model_type', 'App\\Models\\User')
            ->groupBy('r.name')->orderBy('r.name')
            ->selectRaw('r.name, COUNT(*) as users')->pluck('users', 'name')->map(fn ($v) => (int) $v)->all();

        $sourceRoles = $roleCounts($source);
        $targetRoles = $roleCounts($target);

        $linkage = fn (SchemaInspector $db) => $db->connection()->table('users')->whereNotNull('employee_id')->orderBy('id')->pluck('employee_id', 'id')->map(fn ($v) => (string) $v)->all();

        return [
            'users' => ['source' => count($s), 'target' => count($t)],
            'user_ids_missing_on_target' => $missing,
            'password_hashes_unchanged' => $missing === [] && $changed === [],
            'user_ids_with_changed_hash' => $changed,
            'employee_user_links_identical' => $linkage($source) === $linkage($target),
            'role_assignments' => ['source' => $sourceRoles, 'target' => $targetRoles, 'identical' => $sourceRoles === $targetRoles],
            'permissions_regenerated' => self::matrixCoverage($target),
            'grant_gap' => self::grantGap($source, $target),
        ];
    }

    /**
     * Role grants the (Stage 1–upgraded) source holds that the regenerated target does not.
     *
     * A lost grant on a CURRENT permission is a defect: the regeneration dropped an
     * authority somebody relies on (found on real data: the W6/W7 grants, which their
     * migrations make only where the role already exists). A lost grant on a permission
     * no longer in the registry is obsolete and correctly left behind.
     */
    public static function grantGap(SchemaInspector $source, SchemaInspector $target): array
    {
        $grants = fn (SchemaInspector $db) => $db->connection()->table('role_has_permissions as rp')
            ->join('roles as r', 'r.id', '=', 'rp.role_id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->get(['r.name as role', 'p.name as permission'])->map(fn ($g) => "{$g->role}|{$g->permission}")->all();

        $current = array_flip(\App\Constants\Permissions::all());
        $lost = array_values(array_diff($grants($source), $grants($target)));
        sort($lost);
        $defects = array_values(array_filter($lost, fn ($g) => isset($current[explode('|', $g, 2)[1]])));

        return [
            'lost_current_permission_grants' => $defects,
            'lost_obsolete_permission_grants' => array_values(array_diff($lost, $defects)),
            'passes' => $defects === [],
        ];
    }

    /** Every matrix role exists on $db and holds every permission the matrix declares. */
    public static function matrixCoverage(SchemaInspector $db): array
    {
        $gaps = [];
        foreach (RolePermissions::matrix() as $role => $declared) {
            $roleId = $db->connection()->table('roles')->where('name', $role)->value('id');
            if ($roleId === null) {
                $gaps[$role] = 'role missing';

                continue;
            }
            $held = $db->connection()->table('role_has_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('rp.role_id', $roleId)->pluck('p.name')->all();
            $missing = array_values(array_diff($declared, $held));
            if ($missing !== []) {
                $gaps[$role] = count($missing).' declared permission(s) not granted';
            }
        }

        return ['complete' => $gaps === [], 'gaps' => $gaps];
    }

    // ── D2 evidence (Report 51 §15) ───────────────────────────────────────

    public function d2(): array
    {
        $out = [];
        $spec = [
            'purchase_orders' => ['date' => 'date', 'status' => 'status', 'number' => 'po_number', 'amount' => 'total_amount', 'supplier' => 'supplier_id', 'text' => ['po_number', 'description']],
            'purchase_order_items' => ['date' => 'created_at', 'amount' => 'total', 'header' => ['purchase_order_id', 'purchase_orders'], 'text' => ['custom_description']],
            'goods_receipt_notes' => ['date' => 'date', 'status' => 'store_status', 'number' => 'grn_number', 'header' => ['purchase_order_id', 'purchase_orders'], 'text' => ['grn_number', 'notes']],
            'goods_receipt_note_items' => ['date' => 'created_at', 'status' => 'stock_status', 'header' => ['goods_receipt_note_id', 'goods_receipt_notes']],
            'bills' => ['date' => 'bill_date', 'status' => 'status', 'number' => 'bill_number', 'amount' => 'amount', 'supplier' => 'supplier_id', 'project' => ['project_id', 'projects'], 'enquiry' => ['project_enquiry_id', 'project_enquiries'], 'header' => ['purchase_order_id', 'purchase_orders'], 'text' => ['bill_number', 'notes', 'supplier_invoice_number']],
            'bill_payments' => ['date' => 'payment_date', 'number' => 'payment_code', 'amount' => 'amount_paid', 'header' => ['bill_id', 'bills'], 'text' => ['payment_code', 'reference_number']],
        ];

        foreach ($spec as $table => $s) {
            if (! $this->db->hasTable($table)) {
                $out[$table] = ['present' => false];

                continue;
            }
            $q = fn () => $this->db->connection()->table($table);
            $entry = ['present' => true, 'rows' => $q()->count()];
            if ($entry['rows'] === 0) {
                $out[$table] = $entry;

                continue;
            }
            $entry['date_range'] = ['column' => $s['date'], 'min' => $q()->min($s['date']), 'max' => $q()->max($s['date'])];
            $entry['created_at_range'] = ['min' => $q()->min('created_at'), 'max' => $q()->max('created_at')];
            if (isset($s['status'])) {
                $entry['status'] = $this->distribution($table, $s['status']);
            }
            if (isset($s['supplier'])) {
                $entry['supplier_linkage'] = ['with_supplier' => $q()->whereNotNull($s['supplier'])->count(), 'broken' => $this->broken($table, $s['supplier'], 'suppliers')];
            }
            foreach (['project', 'enquiry', 'header'] as $link) {
                if (isset($s[$link])) {
                    [$column, $parent] = $s[$link];
                    $entry["{$link}_linkage"] = ['column' => $column, 'linked' => $q()->whereNotNull($column)->count(), 'broken' => $this->broken($table, $column, $parent)];
                }
            }
            if ($table === 'purchase_orders' && $this->db->hasTable('requisitions')) {
                $entry['project_linkage_via_requisition'] = $this->scalar('SELECT COUNT(*) c FROM purchase_orders po JOIN requisitions r ON r.id = po.requisition_id WHERE r.project_id IS NOT NULL');
            }
            if (isset($s['amount'])) {
                $entry['zero_or_negative_amounts'] = $q()->where($s['amount'], '<=', 0)->count();
            }
            if (isset($s['number'])) {
                $entry['duplicate_numbers'] = $this->scalar("SELECT COUNT(*) c FROM (SELECT `{$s['number']}` FROM `{$table}` WHERE `{$s['number']}` IS NOT NULL GROUP BY `{$s['number']}` HAVING COUNT(*) > 1) x");
            }
            // Objective indicators only: keyword hits and bulk-insert bursts. Not a verdict.
            $pattern = '(^|[^a-z])(test|testing|dummy|sample|demo|asdf|qwerty|lorem|xxx)([^a-z]|$)';
            $hits = $q()->where(function ($w) use ($s, $pattern) {
                foreach ($s['text'] ?? [] as $column) {
                    $w->orWhereRaw("LOWER(`{$column}`) REGEXP ?", [$pattern]);
                }
            });
            $entry['test_keyword_hits'] = empty($s['text']) ? null : [
                'rows' => (clone $hits)->count(),
                'sample_ids' => (clone $hits)->orderBy('id')->limit(10)->pluck('id')->all(),
                'columns_checked' => $s['text'],
            ];
            $entry['rows_in_same_second_bursts_of_5_plus'] = $this->scalar("SELECT COALESCE(SUM(n), 0) c FROM (SELECT COUNT(*) n FROM `{$table}` GROUP BY created_at HAVING COUNT(*) >= 5) x");
            $entry['distinct_creating_users'] = $this->db->hasColumn($table, 'user_id') ? $q()->distinct()->count('user_id') : null;
            $out[$table] = $entry;
        }

        return ['tables' => $out, 'decision' => 'D2 PENDING — this is evidence, not a classification. WNG decides.'];
    }

    // ── Role mapping (D4) — source_role_mapping_report ───────────────────

    public function roles(): array
    {
        $matrix = array_keys(RolePermissions::matrix());
        $exact = [...$matrix, 'Super Admin']; // Super Admin: Gate::before bypass (AppServiceProvider)
        $normalise = fn (string $s) => preg_replace('/[^a-z0-9]/', '', Str::lower($s));
        $byNormal = collect($exact)->mapWithKeys(fn ($r) => [$normalise($r) => $r])->all();
        $code = $this->codeReferences();

        $rows = [];
        foreach ($this->db->connection()->table('roles')->orderBy('id')->get(['id', 'name', 'guard_name']) as $role) {
            $users = $this->db->connection()->table('model_has_roles')->where('role_id', $role->id)->count();
            $grants = $this->db->hasTable('role_has_permissions') ? $this->db->connection()->table('role_has_permissions')->where('role_id', $role->id)->count() : null;
            $refs = $code[$role->name] ?? 0;

            [$classification, $proposed] = match (true) {
                in_array($role->name, $exact, true) => ['exact-target-equivalent', $role->name],
                isset($byNormal[$normalise($role->name)]) => ['renamed-target-equivalent', $byNormal[$normalise($role->name)]],
                $users === 0 && $refs === 0 => ['obsolete-retire-candidate', null],
                default => ['requires-wng-mapping', null],
            };

            $rows[] = [
                'role_id' => $role->id,
                'name' => $role->name,
                'guard' => $role->guard_name,
                'users_assigned' => $users,
                'source_direct_permission_grants' => $grants,
                'code_references' => $refs,
                'target_exact_match' => $classification === 'exact-target-equivalent',
                'classification' => $classification,
                'proposed_mapping' => $proposed,
                'wng_decision_required' => $classification !== 'exact-target-equivalent',
                'effect_until_decided' => $classification === 'exact-target-equivalent'
                    ? 'Kept; permissions regenerated from the matrix.'
                    : 'Kept with its users (never deleted); receives NO matrix permissions until WNG maps or retires it.',
            ];
        }

        return [
            'report' => 'source_role_mapping_report',
            'decision' => 'D4 — PRESERVE AND MAP. No role is retired without explicit WNG approval.',
            'target_matrix_roles' => $matrix,
            'roles' => $rows,
        ];
    }

    // ── Exclusions (Report 51 §21) ───────────────────────────────────────

    public static function exclusions(SchemaInspector $source, SchemaInspector $target, MigrationPlan $plan): array
    {
        $rows = [];
        $failed = [];
        foreach ($plan->tables as $table => $entry) {
            if (in_array($entry['mode'], MigrationPlan::LOAD_MODES, true) || ! $source->hasTable($table)) {
                continue;
            }
            $copied = $target->hasTable($table) ? self::rowsCopiedVerbatim($source, $target, $table) : 0;
            $rows[$table] = [
                'mode' => $entry['mode'],
                'reason' => $entry['reason'],
                'staging_rows' => $source->count($table),
                'target_rows' => $target->hasTable($table) ? $target->count($table) : null,
                'rows_identical_to_staging' => $copied,
            ];
            if ($copied > 0 && in_array($entry['mode'], [MigrationPlan::EXCLUDE, MigrationPlan::DECISION_PENDING], true)) {
                $failed[] = $table;
            }
        }

        $data1 = [
            'old petty-cash transaction history' => ['payments', 'petty_cash_requisitions', 'petty_cash_requisition_items', 'petty_cash_top_ups', 'petty_cash_ledger_entries', 'petty_cash_disbursement_allocations', 'petty_cash_activity_logs', 'petty_cash_balances'],
            'enquiry_payments (client receipts)' => ['enquiry_payments'],
            'old payroll run / ledger' => ['payroll_runs', 'payroll_ledgers', 'payslips'],
            'salary-advance transactions' => ['salary_advance_requests'],
            'old development/test CostLines' => ['cost_lines'],
            'old journals' => ['journal_entries', 'journal_lines'],
        ];
        $checklist = [];
        foreach ($data1 as $label => $tables) {
            $modes = array_map(fn ($t) => $plan->mode($t) ?? 'absent', $tables);
            $checklist[$label] = [
                'tables' => array_combine($tables, $modes),
                'excluded' => ! array_intersect($modes, MigrationPlan::LOAD_MODES) && ! array_intersect($tables, $failed),
            ];
        }

        return ['data1_exclusions' => $checklist, 'tables' => $rows, 'violations' => $failed, 'passes' => $failed === [] && collect($checklist)->every(fn ($c) => $c['excluded'])];
    }

    /** Target rows whose full content equals a staging row with the same key. */
    private static function rowsCopiedVerbatim(SchemaInspector $source, SchemaInspector $target, string $table): int
    {
        $columns = array_keys(array_intersect_key($source->columns($table), $target->columns($table)));
        $digest = fn (SchemaInspector $db) => $db->connection()->table($table)->get($columns)
            ->map(fn ($row) => hash('sha256', json_encode(array_map(fn ($v) => $v === null ? null : (string) $v, (array) $row))))->flip()->all();

        return count(array_intersect_key($digest($target), $digest($source)));
    }

    // ── Budget authority (Report 51 §19) ─────────────────────────────────

    public function budgetAuthority(): array
    {
        // ProjectBudgetAuthority semantics in SQL: the latest budget task per enquiry;
        // finalized when that task is completed and has budget data. Never 'approved'.
        $latest = "SELECT et.* FROM enquiry_tasks et JOIN (SELECT project_enquiry_id, MAX(id) id FROM enquiry_tasks WHERE type = 'budget' GROUP BY project_enquiry_id) l ON l.id = et.id";

        $result = [
            'budget_tasks' => $this->scalar("SELECT COUNT(*) c FROM enquiry_tasks WHERE type = 'budget'"),
            'budget_task_status' => $this->db->connection()->table('enquiry_tasks')->where('type', 'budget')->groupBy('status')->orderBy('status')
                ->selectRaw('status, COUNT(*) c')->pluck('c', 'status')->map(fn ($v) => (int) $v)->all(),
            'task_budget_data_rows' => $this->scalar('SELECT COUNT(*) c FROM task_budget_data'),
            'enquiries_with_current_budget' => $this->scalar("SELECT COUNT(*) c FROM ({$latest}) t JOIN task_budget_data b ON b.enquiry_task_id = t.id"),
            'enquiries_with_finalized_budget' => $this->scalar("SELECT COUNT(*) c FROM ({$latest}) t JOIN task_budget_data b ON b.enquiry_task_id = t.id WHERE t.status = 'completed'"),
            'budget_data_on_non_budget_tasks' => $this->scalar("SELECT COUNT(*) c FROM task_budget_data b JOIN enquiry_tasks et ON et.id = b.enquiry_task_id WHERE et.type <> 'budget'"),
            'budget_data_orphaned' => $this->broken('task_budget_data', 'enquiry_task_id', 'enquiry_tasks'),
            'authority_rule' => "enquiry_tasks.type='budget' (latest per enquiry) + status='completed' + task_budget_data. task_budget_data.status is not used; 'approved' is not a criterion.",
        ];

        // On the application connection, prove it with the real class as well.
        if ($this->db->connectionName === config('database.default')) {
            $authority = app(ProjectBudgetAuthority::class);
            $states = [];
            foreach (DB::table('enquiry_tasks')->where('type', 'budget')->distinct()->orderBy('project_enquiry_id')->pluck('project_enquiry_id') as $enquiryId) {
                $state = $authority->state((int) $enquiryId);
                $states[$state] = ($states[$state] ?? 0) + 1;
            }
            ksort($states);
            $result['project_budget_authority_states'] = $states;
            $result['class_agrees_with_sql'] = ($states[ProjectBudgetAuthority::STATE_FINALIZED] ?? 0) === $result['enquiries_with_finalized_budget'];
        }

        return $result;
    }

    // ── W7 labour (Report 51 §20) ────────────────────────────────────────

    public function w7(): array
    {
        $finalized = $this->db->connection()->select("SELECT b.id, b.labour_data, t.project_enquiry_id FROM task_budget_data b
            JOIN enquiry_tasks t ON t.id = b.enquiry_task_id
            JOIN (SELECT project_enquiry_id, MAX(id) id FROM enquiry_tasks WHERE type = 'budget' GROUP BY project_enquiry_id) l ON l.id = t.id
            WHERE t.status = 'completed'");

        $withLabour = 0;
        $lines = 0;
        $linesWithRate = 0;
        foreach ($finalized as $budget) {
            $data = json_decode((string) $budget->labour_data, true);
            if (is_array($data) && $data !== []) {
                $withLabour++;
                foreach ($data as $row) {
                    if (is_array($row) && ! empty($row['id'])) {
                        $lines++;
                        $linesWithRate += isset($row['unitRate']) ? 1 : 0;
                    }
                }
            }
        }

        $labourColumns = $this->db->hasTable('project_labour_actuals') ? array_keys($this->db->columns('project_labour_actuals')) : [];

        $result = [
            'employees' => $this->db->count('employees'),
            'employees_by_status' => $this->distribution('employees', 'status'),
            'finalized_budgets' => count($finalized),
            'finalized_budgets_with_labour_allocation' => $withLabour,
            'labour_allocation_lines' => $lines,
            'labour_lines_with_unit_rate' => $linesWithRate,
            'labour_record_links_to_employee' => in_array('employee_id', $labourColumns, true),
            'labour_record_has_technical_labour_link' => (bool) array_filter($labourColumns, fn ($c) => str_contains($c, 'technical')),
            'technical_labours_historical_rows' => $this->db->hasTable('technical_labours') ? $this->db->count('technical_labours') : null,
            'departments_labour_classification' => $this->db->hasColumn('departments', 'labour_classification')
                ? ['unclassified (NULL → indirect)' => $this->scalar('SELECT COUNT(*) c FROM departments WHERE labour_classification IS NULL'),
                    'direct' => $this->scalar("SELECT COUNT(*) c FROM departments WHERE labour_classification = 'direct'"),
                    'indirect' => $this->scalar("SELECT COUNT(*) c FROM departments WHERE labour_classification = 'indirect'"),
                    'note' => 'D6: not guessed by the migration. WNG classifies departments in the UI at cutover.']
                : 'column absent on this connection',
        ];

        // On the application connection, read the lines through the W7 service itself.
        // A line is recordable only once its budget is finalized AND an active planned
        // CostLine exists for it — i.e. after `migration:regenerate planned-cost-lines`,
        // which under D5 covers active/open projects only.
        if ($this->db->connectionName === config('database.default') && $labourColumns !== []) {
            $service = app(ProjectLabourActualService::class);
            $visible = 0;
            $recordable = [];
            foreach ($finalized as $budget) {
                $enquiry = ProjectEnquiry::find($budget->project_enquiry_id);
                if ($enquiry) {
                    $read = collect($service->getBudgetLabourLines($enquiry));
                    $visible += $read->count();
                    $recordable[$budget->project_enquiry_id] = $read->where('recordable', true)->count();
                }
            }
            ksort($recordable);
            $result['w7_service_visible_lines'] = $visible;
            $result['w7_service_agrees'] = $visible === $lines;
            $result['w7_recordable_lines_by_enquiry'] = $recordable;
            $result['w7_recordable_note'] = 'Recordable requires an active planned CostLine: run migration:regenerate planned-cost-lines (D5: active/open projects only).';
        }

        return $result;
    }

    // ── D5: project status distribution and planned-CostLine eligibility ─

    public function projectStatus(): array
    {
        return [
            'project_status' => $this->distribution('projects', 'status'),
            'enquiry_status' => $this->distribution('project_enquiries', 'status'),
            'd5_rule' => config('source_migration.d5'),
            'budgets_eligible_for_planned_cost_lines' => count(self::eligibleBudgetIds($this->db)),
            'budgets_total' => $this->db->count('task_budget_data'),
        ];
    }

    /**
     * D5: budgets of ACTIVE/OPEN operational projects only.
     *
     * @return list<int>
     */
    public static function eligibleBudgetIds(SchemaInspector $db): array
    {
        $closedEnquiry = (array) config('source_migration.d5.closed_enquiry_statuses', []);
        $closedProject = (array) config('source_migration.d5.closed_project_statuses', []);

        return $db->connection()->table('task_budget_data as b')
            ->join('enquiry_tasks as t', 't.id', '=', 'b.enquiry_task_id')
            ->join('project_enquiries as e', 'e.id', '=', 't.project_enquiry_id')
            ->where('t.type', 'budget')
            ->whereNotIn('e.status', $closedEnquiry)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('projects as p')
                ->whereColumn('p.enquiry_id', 'e.id')->whereIn('p.status', $closedProject))
            ->orderBy('b.id')->pluck('b.id')->map(fn ($id) => (int) $id)->all();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Non-null references with no parent row; null when the table or column is absent. */
    public function broken(string $table, string $column, string $parent, string $parentColumn = 'id'): ?int
    {
        if (! $this->db->hasColumn($table, $column) || ! $this->db->hasTable($parent)) {
            return null;
        }

        return (int) $this->db->connection()->table("{$table} as c")
            ->leftJoin("{$parent} as p", "p.{$parentColumn}", '=', "c.{$column}")
            ->whereNotNull("c.{$column}")->whereNull("p.{$parentColumn}")->count();
    }

    private function onlyBroken(array $checks): array
    {
        return array_filter($checks, fn ($v) => $v !== null && $v > 0);
    }

    private function counts(array $tables): array
    {
        return collect($tables)->mapWithKeys(fn ($t) => [$t => $this->db->hasTable($t) ? $this->db->count($t) : null])->all();
    }

    public function distribution(string $table, string $column): ?array
    {
        if (! $this->db->hasColumn($table, $column)) {
            return null;
        }

        return $this->db->connection()->table($table)->groupBy($column)->orderBy($column)
            ->selectRaw("`{$column}` AS v, COUNT(*) AS c")->get()
            ->mapWithKeys(fn ($r) => [$r->v ?? '<null>' => (int) $r->c])->all();
    }

    private function scalar(string $sql): ?int
    {
        try {
            return (int) $this->db->connection()->selectOne($sql)->c;
        } catch (\Illuminate\Database\QueryException) {
            return null;
        }
    }

    /** Occurrences of each quoted role-like string in app/ (for D4 evidence). */
    private function codeReferences(): array
    {
        $names = $this->db->connection()->table('roles')->pluck('name')->all();
        $counts = array_fill_keys($names, 0);
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $contents = $file->getContents();
            foreach ($names as $name) {
                $counts[$name] += substr_count($contents, "'{$name}'") + substr_count($contents, "\"{$name}\"");
            }
        }

        return $counts;
    }
}
