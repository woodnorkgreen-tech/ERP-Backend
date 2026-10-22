<?php

namespace App\Modules\ProcurementStores\Services;

use App\Models\MaterialRequirementRetirement;
use App\Models\Project;
use App\Modules\ProcurementStores\Services\ProjectMaterialWorkflowEligibility as Eligibility;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Report 78C — the operational material numbers, and the lists behind them.
 *
 * Every count here is the length of a list this class also returns
 * ({@see queue()}), built by the same call. A dashboard tile cannot say 412
 * while the list it opens shows 140: there is no second computation to drift.
 *
 * Each metric names one unit. Lines are lines, jobs are jobs, and quantities
 * are never summed across materials (that is the forecast's job, per material).
 */
class ProjectMaterialWorkflowSummary
{
    public const METRICS = [
        'pending_to_issue', 'pending_resolution', 'awaiting_registration',
        'unit_exceptions', 'historical_outstanding', 'retired',
    ];

    public function __construct(
        private readonly ProjectMaterialDemand $demand,
        private readonly Eligibility $eligibility,
        private readonly MaterialShortageForecast $forecast,
    ) {}

    public function summarise(): array
    {
        $pending = $this->queue('pending_to_issue');
        $resolution = $this->queue('pending_resolution');
        $registration = $this->queue('awaiting_registration');
        $exceptions = $this->queue('unit_exceptions');
        $historical = $this->queue('historical_outstanding');
        $retired = $this->queue('retired');
        $shortage = $this->forecast->summary($this->forecast->rows(
            $this->demand->pendingLines(null, Eligibility::CAP_SHORTAGE),
        ));

        $byProject = [];
        foreach (['pending_to_issue' => $pending, 'pending_resolution' => $resolution, 'awaiting_registration' => $registration, 'unit_exceptions' => $exceptions] as $metric => $rows) {
            foreach ($rows->countBy('project_id') as $projectId => $count) {
                $byProject[$projectId][$metric] = $count;
            }
        }

        return [
            'pending_to_issue' => [
                'lines' => $pending->count(),
                'jobs' => $pending->pluck('project_id')->unique()->count(),
                'overdue_lines' => $pending->where('overdue', true)->count(),
            ],
            'pending_resolution' => [
                'lines' => $resolution->count(),
                'jobs' => $resolution->pluck('project_id')->unique()->count(),
            ],
            'awaiting_registration' => [
                'lines' => $registration->count(),
                'jobs' => $registration->pluck('project_id')->unique()->count(),
            ],
            'unit_exceptions' => [
                'lines' => $exceptions->count(),
                'jobs' => $exceptions->pluck('project_id')->unique()->count(),
            ],
            'procurement_shortage' => [
                'materials' => (int) $shortage['at_risk'],
                'materials_with_demand' => (int) $shortage['materials'],
            ],
            'historical' => [
                'outstanding_lines' => $historical->count(),
                'retired_lines' => $retired->count(),
            ],
            'queues' => $this->jobQueues(),
            'by_project' => $byProject,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The rows behind one metric. Optionally narrowed to one job.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function queue(string $metric, ?int $projectId = null): Collection
    {
        $rows = match ($metric) {
            'pending_to_issue' => $this->demand->pendingLines(null, Eligibility::CAP_ISSUE),
            'pending_resolution' => $this->demand->unresolvedLines(Eligibility::CAP_RESOLUTION, null),
            'awaiting_registration' => $this->demand->unresolvedLines(Eligibility::CAP_RESOLUTION, \App\Models\ElementMaterial::DISPOSITION_AWAITING_REGISTRATION),
            'unit_exceptions' => $this->demand->unitExceptionLines(Eligibility::CAP_ISSUE),
            'historical_outstanding' => $this->demand->pendingLines(null, Eligibility::CAP_HISTORICAL)
                ->concat($this->demand->unresolvedLines(Eligibility::CAP_HISTORICAL, null))
                ->values(),
            'retired' => $this->retiredRows(),
            default => throw ValidationException::withMessages(['metric' => "Unknown material metric '{$metric}'."]),
        };

        return $projectId === null ? $rows->values() : $rows->where('project_id', $projectId)->values();
    }

    /** Jobs per Desk queue, over the same project rows the Desk lists. */
    private function jobQueues(): array
    {
        $described = $this->eligibility->describeMany(Project::query()->pluck('enquiry_id'));
        $counts = collect($described)->countBy('queue');

        return [
            Eligibility::QUEUE_ACTIVE => (int) ($counts[Eligibility::QUEUE_ACTIVE] ?? 0),
            'overdue_active' => collect($described)->where('queue', Eligibility::QUEUE_ACTIVE)->where('overdue', true)->count(),
            Eligibility::QUEUE_PRE_START => (int) ($counts[Eligibility::QUEUE_PRE_START] ?? 0),
            Eligibility::QUEUE_ON_HOLD => (int) ($counts[Eligibility::QUEUE_ON_HOLD] ?? 0),
            Eligibility::QUEUE_HISTORICAL => (int) ($counts[Eligibility::QUEUE_HISTORICAL] ?? 0),
            Eligibility::QUEUE_PIPELINE => (int) ($counts[Eligibility::QUEUE_PIPELINE] ?? 0),
            'all' => count($described),
        ];
    }

    private function retiredRows(): Collection
    {
        $projects = Project::query()->get(['id', 'enquiry_id', 'project_id'])->keyBy('enquiry_id');

        return MaterialRequirementRetirement::query()->active()
            ->with(['line:id,description,unit_of_measurement,quantity', 'retiredBy'])
            ->orderBy('id')->get()
            ->map(fn (MaterialRequirementRetirement $row) => [
                'retirement_id' => $row->id,
                'project_material_id' => $row->element_material_id,
                'project_id' => $projects->get($row->project_enquiry_id)?->id,
                'project_code' => $projects->get($row->project_enquiry_id)?->project_id,
                'description' => $row->line?->description,
                'unit' => $row->line?->unit_of_measurement,
                'retired_bom_quantity' => (float) $row->retired_bom_quantity,
                'reason' => $row->reason,
                'retired_by' => $row->retiredBy?->name,
                'retired_at' => $row->retired_at?->toIso8601String(),
            ]);
    }
}
