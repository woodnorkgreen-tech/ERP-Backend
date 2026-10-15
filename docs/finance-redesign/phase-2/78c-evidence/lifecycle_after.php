<?php

/**
 * Report 78C Stage B — the same dev data read through the new policy. READ-ONLY.
 *
 * Uses the shipped services (ProjectMaterialWorkflowSummary, ReplenishmentPlanner,
 * ProjectMaterialDemand), so these are exactly the numbers the screens now show.
 * Runs inside a transaction that is always rolled back; nothing is retired,
 * completed, held or corrected.
 *
 *   ddev exec php docs/finance-redesign/phase-2/78c-evidence/lifecycle_after.php
 *
 * Writes after.json and overdue_triage.csv (D-5: the planning jobs past their
 * delivery date, for Projects to complete or retire one by one — never in bulk).
 */

use App\Models\Project;
use App\Modules\ProcurementStores\Services\ProjectMaterialDemand;
use App\Modules\ProcurementStores\Services\ProjectMaterialWorkflowEligibility as Eligibility;
use App\Modules\ProcurementStores\Services\ProjectMaterialWorkflowSummary;
use App\Modules\ProcurementStores\Services\ReplenishmentPlanner;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DB::beginTransaction();
try {
    $summary = app(ProjectMaterialWorkflowSummary::class);
    $demand = app(ProjectMaterialDemand::class);
    $eligibility = app(Eligibility::class);

    $totals = $summary->summarise();
    unset($totals['by_project']);

    // Reconciliation: every tile against the list it opens.
    $reconciliation = [];
    foreach (ProjectMaterialWorkflowSummary::METRICS as $metric) {
        $reconciliation[$metric] = $summary->queue($metric)->count();
    }

    $suggestions = app(ReplenishmentPlanner::class)->suggestions();
    $demandMaterials = count($demand->pendingByMaterial());

    // D-5 triage list.
    $projects = Project::with('enquiry:id,title,job_number,expected_delivery_date,status')->get()->keyBy('enquiry_id');
    $described = $eligibility->describeMany($projects->keys());
    $pending = $summary->queue('pending_to_issue')->countBy('project_id');
    $unresolved = $summary->queue('pending_resolution')->countBy('project_id');
    $lastMovement = DB::table('inventory_logs')->whereNotNull('project_id')
        ->selectRaw('project_id, MAX(logged_at) AS last_at')->groupBy('project_id')->pluck('last_at', 'project_id');

    $triage = collect($described)
        ->filter(fn ($row) => $row['queue'] === Eligibility::QUEUE_ACTIVE && $row['overdue'])
        ->map(function ($row, $enquiryId) use ($projects, $pending, $unresolved, $lastMovement) {
            $project = $projects->get($enquiryId);

            return [
                'project_id' => $project->id,
                'job' => $project->project_id,
                'title' => $project->enquiry?->title,
                'enquiry_status' => $row['enquiry_status'],
                'due_date' => $row['due_date'],
                'days_overdue' => $row['days_overdue'],
                'pending_issue_lines' => (int) ($pending[$project->id] ?? 0),
                'unresolved_lines' => (int) ($unresolved[$project->id] ?? 0),
                'last_stores_movement' => $lastMovement[$project->id] ?? '',
            ];
        })
        ->sortByDesc('days_overdue')->values();

    $handle = fopen(__DIR__.'/overdue_triage.csv', 'w');
    fputcsv($handle, array_keys($triage->first() ?? ['job' => null]));
    $triage->each(fn ($row) => fputcsv($handle, $row));
    fclose($handle);

    // Obligations on historical jobs that must stay visible (never filtered).
    $historicalProjects = collect($described)->filter(fn ($row) => in_array($row['state'], Eligibility::HISTORICAL_STATES, true))
        ->keys()->map(fn ($enquiryId) => $projects->get($enquiryId)?->id)->filter()->values();
    $obligations = [
        'open_requisitions' => DB::table('requisitions')->whereIn('project_id', $historicalProjects)
            ->whereIn('status', ['draft', 'pending_approval', 'approved'])->count(),
        'open_purchase_orders' => DB::table('purchase_orders as po')->join('requisitions as r', 'r.id', '=', 'po.requisition_id')
            ->whereIn('r.project_id', $historicalProjects)->whereNotIn('po.status', ['cancelled', 'rejected', 'completed'])->count(),
        'unpaid_bills' => DB::table('bills')->whereIn('project_id', $historicalProjects)->whereIn('status', ['pending', 'partial', 'overdue'])->count(),
        'pending_board_requests' => DB::table('board_requests')->whereIn('project_id', $historicalProjects)->where('status', 'pending')->count(),
    ];

    $after = [
        'database' => config('database.connections.'.config('database.default').'.database'),
        'generated_at' => now()->toIso8601String(),
        'summary' => $totals,
        'queue_list_lengths' => $reconciliation,
        'replenishment' => [
            'suggestions' => $suggestions->count(),
            'job_shortfall' => $suggestions->where('reason', 'job_shortfall')->count(),
            'below_minimum' => $suggestions->where('reason', 'below_minimum')->count(),
            'with_open_requests_subtracted' => $suggestions->where('requested_open', '>', 0)->count(),
        ],
        'materials_with_procurement_demand' => $demandMaterials,
        'overdue_triage_jobs' => $triage->count(),
        'overdue_triage_without_movement_90d' => $triage->filter(fn ($row) => $row['last_stores_movement'] === '' || $row['last_stores_movement'] < now()->subDays(90)->toDateTimeString())->count(),
        'obligations_on_historical_jobs' => $obligations,
        'mirror_mismatches' => DB::table('projects as p')->join('project_enquiries as e', 'e.id', '=', 'p.enquiry_id')
            ->whereColumn('p.status', '!=', 'e.status')->count(),
    ];

    file_put_contents(__DIR__.'/after.json', json_encode($after, JSON_PRETTY_PRINT));
} finally {
    DB::rollBack();
}
