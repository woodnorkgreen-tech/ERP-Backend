<?php

/**
 * Report 78C Phase 8 — project lifecycle vs material demand baseline. READ-ONLY.
 *
 * Buckets every approved BOM requirement by the lifecycle of the job it belongs
 * to, using the live services (ProjectMaterialDemand, ReplenishmentPlanner) so the
 * "before" numbers are exactly what the screens show today. Runs inside a
 * transaction that is always rolled back; nothing is written.
 *
 *   ddev exec php docs/finance-redesign/phase-2/78c-evidence/lifecycle_baseline.php
 *
 * Writes summary.json next to this file.
 */

use App\Models\ElementMaterial;
use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Modules\ProcurementStores\Services\ProjectMaterialDemand;
use App\Modules\ProcurementStores\Services\ReplenishmentPlanner;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$database = config('database.connections.'.config('database.default').'.database');
$today = now()->toDateString();

DB::beginTransaction();
try {
    $enquiries = ProjectEnquiry::query()->get(['id', 'status', 'expected_delivery_date', 'end_date'])->keyBy('id');
    $projects = Project::query()->get(['id', 'enquiry_id', 'status', 'end_date'])->keyBy('id');
    $projectByEnquiry = $projects->keyBy('enquiry_id');

    // Bucket = the policy the brief proposes, read from project_enquiries.status
    // (the authority; projects.status is stale — see status_mismatch below).
    $bucket = function (?int $enquiryId) use ($enquiries, $projectByEnquiry, $today): string {
        $enquiry = $enquiryId ? $enquiries->get($enquiryId) : null;
        if (! $enquiry) {
            return 'no_enquiry';
        }
        $status = $enquiry->status;
        if (in_array($status, ['completed', 'closed', 'cancelled'], true)) {
            return $status;
        }
        if (in_array($status, ['planning', 'in_progress'], true)) {
            $project = $projectByEnquiry->get($enquiryId);
            $due = $enquiry->expected_delivery_date?->toDateString() ?? $project?->end_date?->toDateString();

            return $due !== null && $due < $today ? 'active_overdue' : 'active';
        }

        return $projectByEnquiry->has($enquiryId) ? 'pre_project_with_project_row' : 'pre_project';
    };

    // ---- 1. Approved, included requirement lines by lifecycle ----
    $lines = ElementMaterial::query()
        ->join('project_deliverables as el', 'el.id', '=', 'element_materials.project_element_id')
        ->join('task_materials_data as tmd', 'tmd.id', '=', 'el.task_materials_data_id')
        ->join('enquiry_tasks as t', 't.id', '=', 'tmd.enquiry_task_id')
        ->where('element_materials.is_included', true)
        ->whereRaw("JSON_EXTRACT(tmd.project_info, '$.approval_status.all_approved') = true")
        ->get([
            'element_materials.id', 'element_materials.library_material_id',
            'element_materials.requirement_disposition', 't.project_enquiry_id',
            'el.archived_at as element_archived_at',
        ]);

    $approved = [];
    foreach ($lines as $line) {
        $b = $bucket((int) $line->project_enquiry_id);
        $approved[$b] ??= ['lines' => 0, 'linked' => 0, 'unresolved' => 0, 'awaiting_registration' => 0, 'non_stock' => 0, 'element_archived' => 0, 'jobs' => []];
        $approved[$b]['lines']++;
        $approved[$b]['jobs'][$line->project_enquiry_id] = true;
        if ($line->element_archived_at) {
            $approved[$b]['element_archived']++;
        }
        if ($line->library_material_id) {
            $approved[$b]['linked']++;
        } elseif ($line->requirement_disposition === 'non_stock') {
            $approved[$b]['non_stock']++;
        } elseif ($line->requirement_disposition === 'awaiting_registration') {
            $approved[$b]['awaiting_registration']++;
        } else {
            $approved[$b]['unresolved']++;
        }
    }
    foreach ($approved as &$row) {
        $row['jobs'] = count($row['jobs']);
    }
    unset($row);
    ksort($approved);

    // ---- 2. Pending-to-issue as the live demand service computes it ----
    $pending = app(ProjectMaterialDemand::class)->pendingLines();
    $pendingBuckets = [];
    foreach ($pending as $row) {
        $enquiryId = $projects->get($row['project_id'])?->enquiry_id;
        $b = $bucket($enquiryId ? (int) $enquiryId : null);
        $pendingBuckets[$b] ??= ['lines' => 0, 'materials' => [], 'jobs' => [], 'pending_qty_sum' => 0.0];
        $pendingBuckets[$b]['lines']++;
        $pendingBuckets[$b]['materials'][$row['library_material_id']] = true;
        $pendingBuckets[$b]['jobs'][$row['project_id']] = true;
        $pendingBuckets[$b]['pending_qty_sum'] += $row['pending'];
    }
    foreach ($pendingBuckets as &$row) {
        $row['materials'] = count($row['materials']);
        $row['jobs'] = count($row['jobs']);
        $row['pending_qty_sum'] = round($row['pending_qty_sum'], 2);
    }
    unset($row);
    ksort($pendingBuckets);

    // Materials whose ENTIRE demand comes from non-active jobs: the picker's
    // `uncommitted` and the forecast are wrong for these today.
    $isActive = fn (string $b) => in_array($b, ['active', 'active_overdue'], true);
    $byMaterial = $pending->groupBy('library_material_id')->map(function ($rows) use ($projects, $bucket, $isActive) {
        $active = 0.0;
        $inactive = 0.0;
        foreach ($rows as $row) {
            $enquiryId = $projects->get($row['project_id'])?->enquiry_id;
            $isActive($bucket($enquiryId ? (int) $enquiryId : null)) ? $active += $row['pending'] : $inactive += $row['pending'];
        }

        return compact('active', 'inactive');
    });

    // ---- 3. Forecast + replenishment, before ----
    $suggestions = app(ReplenishmentPlanner::class)->suggestions();
    $jobShortfall = $suggestions->where('reason', 'job_shortfall');

    // ---- 4. Lifecycle data quality ----
    $mismatch = DB::table('projects as p')->join('project_enquiries as e', 'e.id', '=', 'p.enquiry_id')
        ->whereColumn('p.status', '!=', 'e.status')
        ->selectRaw('p.status as project_status, e.status as enquiry_status, COUNT(*) as n')
        ->groupBy('p.status', 'e.status')->get();

    // ---- 5. Open obligations on non-active jobs (must stay visible) ----
    $inactiveEnquiryIds = $enquiries->filter(fn ($e) => in_array($e->status, ['completed', 'closed', 'cancelled'], true))->keys();
    $inactiveProjectIds = $projects->whereIn('enquiry_id', $inactiveEnquiryIds)->keys();
    $obligations = [
        'requisitions_open' => DB::table('requisitions')->whereIn('project_id', $inactiveProjectIds)
            ->whereNotIn('status', ['rejected', 'cancelled', 'completed', 'closed'])->count(),
        'requisition_items_on_inactive_enquiries' => DB::table('requisition_items')->whereIn('project_enquiry_id', $inactiveEnquiryIds)->count(),
        'bills_on_inactive' => DB::table('bills')->where(fn ($q) => $q->whereIn('project_id', $inactiveProjectIds)->orWhereIn('project_enquiry_id', $inactiveEnquiryIds))
            ->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
        'board_requests_open_on_inactive' => DB::table('board_requests')->whereIn('project_id', $inactiveProjectIds)
            ->whereNotIn('status', ['issued', 'rejected', 'cancelled', 'returned', 'completed'])->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
        'pos_from_requisitions_on_inactive' => DB::table('purchase_orders as po')->join('requisitions as r', 'r.id', '=', 'po.requisition_id')
            ->whereIn('r.project_id', $inactiveProjectIds)->selectRaw('po.status, COUNT(*) n')->groupBy('po.status')->pluck('n', 'po.status'),
    ];
    $totals = [
        'purchase_orders' => DB::table('purchase_orders')->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
        'requisitions' => DB::table('requisitions')->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
        'bills' => DB::table('bills')->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
        'board_requests' => DB::table('board_requests')->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
    ];

    // ---- 6. Desk "In Progress" tab today: project.status || enquiry.status ----
    $deskInProgress = $projects->filter(fn ($p) => in_array($p->status, ['planning', 'in_progress'], true));
    $deskInProgressByEnquiry = $deskInProgress->groupBy(fn ($p) => $enquiries->get($p->enquiry_id)?->status ?? 'none')->map->count();

    // ---- 7. AFTER: same services with demand restricted to active jobs ----
    $activeOnly = new class extends ProjectMaterialDemand
    {
        public $keep;

        public function pendingLines(?array $materialIds = null): \Illuminate\Support\Collection
        {
            return parent::pendingLines($materialIds)->filter(fn ($row) => ($this->keep)($row))->values();
        }
    };
    $activeOnly->keep = function ($row) use ($projects, $bucket, $isActive) {
        $enquiryId = $projects->get($row['project_id'])?->enquiry_id;

        return $isActive($bucket($enquiryId ? (int) $enquiryId : null));
    };
    app()->instance(ProjectMaterialDemand::class, $activeOnly);
    app()->forgetInstance(ReplenishmentPlanner::class);
    $after = app(ReplenishmentPlanner::class)->suggestions();
    $suggestionsAfter = [
        'suggestions' => $after->count(),
        'job_shortfall' => $after->where('reason', 'job_shortfall')->count(),
        'below_minimum' => $after->where('reason', 'below_minimum')->count(),
    ];

    // ---- 8. How overdue are the "active overdue" jobs? (legacy signal) ----
    $overdueAges = ['<=30d' => 0, '31-90d' => 0, '91-180d' => 0, '181-365d' => 0, '>365d' => 0];
    $overdueWithIssueActivity90d = 0;
    foreach ($enquiries as $enquiry) {
        if ($bucket($enquiry->id) !== 'active_overdue') {
            continue;
        }
        $due = $enquiry->expected_delivery_date ?? $projectByEnquiry->get($enquiry->id)?->end_date;
        $days = $due->diffInDays(now());
        $key = $days <= 30 ? '<=30d' : ($days <= 90 ? '31-90d' : ($days <= 180 ? '91-180d' : ($days <= 365 ? '181-365d' : '>365d')));
        $overdueAges[$key]++;
        $projectId = $projectByEnquiry->get($enquiry->id)?->id;
        if ($projectId && DB::table('inventory_logs')->where('project_id', $projectId)->where('created_at', '>=', now()->subDays(90))->exists()) {
            $overdueWithIssueActivity90d++;
        }
    }
    $activeOverdueJobs = array_sum($overdueAges);

    $summary = [
        'database' => $database,
        'generated_at' => now()->toIso8601String(),
        'today' => $today,
        'approved_lines_by_lifecycle' => $approved,
        'pending_issue_by_lifecycle' => $pendingBuckets,
        'materials_with_pending_demand' => $byMaterial->count(),
        'materials_demand_only_from_inactive_jobs' => $byMaterial->filter(fn ($m) => $m['active'] <= 0 && $m['inactive'] > 0)->count(),
        'materials_demand_mixed' => $byMaterial->filter(fn ($m) => $m['active'] > 0 && $m['inactive'] > 0)->count(),
        'replenishment_after_active_only' => $suggestionsAfter,
        'active_overdue_jobs_by_days_past_due' => $overdueAges,
        'active_overdue_jobs_total' => $activeOverdueJobs,
        'active_overdue_jobs_with_stores_movement_last_90d' => $overdueWithIssueActivity90d,
        'replenishment_before' => [
            'suggestions' => $suggestions->count(),
            'job_shortfall' => $jobShortfall->count(),
            'below_minimum' => $suggestions->where('reason', 'below_minimum')->count(),
        ],
        'project_vs_enquiry_status_mismatch' => $mismatch,
        'desk_in_progress_tab_by_true_enquiry_status' => $deskInProgressByEnquiry,
        'obligations_on_inactive_jobs' => $obligations,
        'procurement_totals' => $totals,
    ];

    file_put_contents(__DIR__.'/summary.json', json_encode($summary, JSON_PRETTY_PRINT));
    echo json_encode($summary, JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    DB::rollBack();
}
