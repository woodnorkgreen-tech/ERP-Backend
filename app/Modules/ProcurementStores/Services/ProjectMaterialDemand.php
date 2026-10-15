<?php

namespace App\Modules\ProcurementStores\Services;

use App\Models\ElementMaterial;
use App\Models\Project;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Services\ProjectMaterialWorkflowEligibility as Eligibility;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * What live jobs have already spoken for.
 *
 * Stores could answer this and Procurement could not. The demand forecast on
 * the Project Materials Desk worked out, per material, how much approved and
 * unissued specification was riding on it — while the material picker a buyer
 * uses to raise a requisition reported `on_hand − reserved`, and `reserved`
 * counts only board requests. So the picker would say 120 sheets were available
 * with 85 of them already committed to three approved jobs, and the buyer had
 * no way to know.
 *
 * The rules live here rather than in either caller because the two answers must
 * agree by construction. A second copy would let the desk and the picker drift,
 * and the drift would show up as stock that exists on one screen and not the
 * other — the class of bug this codebase has already paid for elsewhere.
 *
 * **This is a measure, not a lock.** Nothing here reserves stock or decides
 * which job gets a constrained item; `stocks.quantity_reserved` remains the
 * board-request lifecycle's own counter and is untouched. Demand is derived on
 * read from the specification and the issue log, so it cannot drift out of step
 * with them and there is no counter to release.
 *
 * **Report 78C: only live jobs.** Which jobs count is decided by
 * {@see ProjectMaterialWorkflowEligibility} — by default jobs that may create
 * procurement demand (active and pre-start, not on hold, never completed,
 * closed or cancelled). Quantity recorded as fulfilled outside the ERP
 * ({@see MaterialRequirementRetirements}) is not demand. And the Desk's
 * per-project view and every cross-project total now run through the same
 * fulfilment arithmetic, so a total is always the sum of the rows it labels.
 */
class ProjectMaterialDemand
{
    /** Movements that take material out of the store against a job. */
    private const ISSUE_TYPES = ['check_out', 'issue', 'consumption'];

    public function __construct(
        private readonly Eligibility $eligibility,
        private readonly MaterialRequirementRetirements $retirements,
    ) {}

    /**
     * Fulfilment state for every approved-material line on one project.
     *
     * This is also the mobile/API projection. Keeping it here prevents clients
     * from treating the immutable specification quantity as quantity still
     * available to issue. Older movements without a project_material_id are
     * allocated FIFO, exactly as the posting guard does.
     *
     * @param  Collection<int, ElementMaterial>  $lines
     * @return array<int, array{required: float, issued: float, returned: float, net_issued: float, retired: float, remaining: float, fully_issued: bool}>
     */
    public function fulfilmentForProject(Project $project, Collection $lines): array
    {
        $lines = $lines->filter(fn (ElementMaterial $line) => $line->is_included)
            ->sortBy('id')->values();

        if ($lines->isEmpty()) {
            return [];
        }

        $movements = InventoryLog::query()
            ->where('project_id', $project->id)
            ->whereIn('type', [...self::ISSUE_TYPES, 'return'])
            ->get(['type', 'quantity', 'material_id', 'project_material_id', 'return_kind', 'notes']);

        return $this->fulfilment($lines, $movements, $this->retirements->retiredBomByLine($lines->pluck('id')));
    }

    /**
     * Approved, unissued demand per library material.
     *
     * @param  array<int, int>|null  $materialIds  Narrow to these materials; null for all.
     * @return array<int, float> keyed by library_material_id, zero-demand materials omitted
     */
    public function pendingByMaterial(?array $materialIds = null, string $capability = Eligibility::CAP_DEMAND): array
    {
        return $this->pendingLines($materialIds, $capability)
            ->groupBy('library_material_id')
            ->map(fn (Collection $lines) => (float) $lines->sum('pending'))
            ->filter(fn (float $pending) => $pending > 0)
            ->all();
    }

    /**
     * The same demand, one row per specification line, carrying the job it
     * belongs to. The desk needs this detail to show who is waiting on what;
     * the picker only needs the total above.
     *
     * @param  array<int, int>|null  $materialIds
     * @return Collection<int, array<string, mixed>>
     */
    public function pendingLines(?array $materialIds = null, string $capability = Eligibility::CAP_DEMAND): Collection
    {
        return $this->evaluate($materialIds, $capability)
            ->where('kind', 'pending')
            ->map(fn (array $row) => collect($row)->except('kind')->all())
            ->values();
    }

    /**
     * Linked lines whose BOM unit cannot be read in the material's stock unit
     * (Report 78B). They are not demand — 12 sheets are never bought for 12
     * square metres — but they are counted, so the gap is visible.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function unitExceptionLines(string $capability = Eligibility::CAP_ISSUE): Collection
    {
        return $this->evaluate(null, $capability)
            ->where('kind', 'unit_exception')
            ->map(fn (array $row) => collect($row)->except('kind')->all())
            ->values();
    }

    /**
     * Approved lines with no Material Library identity yet, on jobs where the
     * capability applies, less any quantity retired as fulfilled outside the
     * ERP. `$disposition` null = still awaiting resolution; a disposition name
     * narrows to it; 'any' = both (never non-stock lines, which are resolved).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function unresolvedLines(string $capability = Eligibility::CAP_RESOLUTION, ?string $disposition = null): Collection
    {
        $enquiryIds = $this->eligibility->eligibleEnquiryIds($capability);
        if ($enquiryIds->isEmpty()) {
            return collect();
        }

        $lines = $this->approvedLines(null, $enquiryIds, linked: false)
            ->filter(function (ElementMaterial $line) use ($disposition) {
                if ($line->library_material_id || $line->requirement_disposition === ElementMaterial::DISPOSITION_NON_STOCK) {
                    return false;
                }

                return match ($disposition) {
                    'any' => true,
                    null => $line->requirement_disposition === null,
                    default => $line->requirement_disposition === $disposition,
                };
            });
        if ($lines->isEmpty()) {
            return collect();
        }

        $projects = $this->projectsFor($lines);
        $described = $this->eligibility->describeMany($projects->keys());
        $retired = $this->retirements->retiredBomByLine($lines->pluck('id'));

        return $lines->map(function (ElementMaterial $line) use ($projects, $described, $retired) {
            $enquiryId = $line->element?->taskMaterialsData?->task?->project_enquiry_id;
            $project = $projects->get($enquiryId);
            if (! $project) {
                return null;
            }
            $retiredBom = (float) ($retired[$line->id] ?? 0);
            $outstanding = max(0.0, (float) $line->quantity - $retiredBom);
            if ($outstanding <= 0.00001) {
                return null;
            }

            return [
                'project_material_id' => $line->id,
                'project_id' => $project->id,
                'enquiry_id' => (int) $enquiryId,
                'project_code' => $project->project_id,
                'project_title' => $project->enquiry?->title ?? 'Project',
                'element' => $line->element?->name ?? 'Project materials',
                'description' => $line->description,
                'unit' => $line->unit_of_measurement,
                'quantity' => round((float) $line->quantity, 4),
                'retired_bom' => round($retiredBom, 4),
                'outstanding_bom' => round($outstanding, 4),
                'disposition' => $line->requirement_disposition,
                'lifecycle_state' => $described[$enquiryId]['state'] ?? null,
                'overdue' => (bool) ($described[$enquiryId]['overdue'] ?? false),
            ];
        })->filter()->values();
    }

    /**
     * Every linked, approved line on jobs where the capability applies, run
     * through the one fulfilment calculation and labelled as pending demand or
     * as a unit exception. Fully satisfied lines are dropped.
     *
     * @param  array<int, int>|null  $materialIds
     * @return Collection<int, array<string, mixed>>
     */
    private function evaluate(?array $materialIds, string $capability): Collection
    {
        $enquiryIds = $this->eligibility->eligibleEnquiryIds($capability);
        if ($enquiryIds->isEmpty()) {
            return collect();
        }

        $specified = $this->approvedLines($materialIds, $enquiryIds, linked: true);
        if ($specified->isEmpty()) {
            return collect();
        }

        $projects = $this->projectsFor($specified);
        if ($projects->isEmpty()) {
            return collect();
        }

        $described = $this->eligibility->describeMany($projects->keys());
        $movements = InventoryLog::query()
            ->whereIn('project_id', $projects->pluck('id'))
            ->whereIn('type', [...self::ISSUE_TYPES, 'return'])
            ->get(['project_id', 'type', 'quantity', 'material_id', 'project_material_id', 'return_kind', 'notes'])
            ->groupBy('project_id');
        $retired = $this->retirements->retiredBomByLine($specified->pluck('id'));

        return $specified
            ->groupBy(fn (ElementMaterial $line) => (int) $line->element?->taskMaterialsData?->task?->project_enquiry_id)
            ->flatMap(function (Collection $lines, int $enquiryId) use ($projects, $described, $movements, $retired) {
                // A specification whose enquiry never became a project is a
                // quote, not a commitment on the shelf.
                $project = $projects->get($enquiryId);
                if (! $project) {
                    return [];
                }

                $state = $this->fulfilment($lines->sortBy('id')->values(), $movements->get($project->id, collect()), $retired);

                return $lines->map(function (ElementMaterial $line) use ($project, $state, $described, $enquiryId) {
                    $row = $state[$line->id] ?? null;
                    if (! $row || $row['remaining'] <= 0.00001) {
                        return null;
                    }

                    return [
                        // Report 78B: demand is stock-unit demand. A requirement
                        // that cannot be read in the material's stock unit is an
                        // exception for Stores, never guessed at.
                        'kind' => $row['issuable'] ? 'pending' : 'unit_exception',
                        'library_material_id' => (int) $line->library_material_id,
                        'project_material_id' => $line->id,
                        'project_id' => $project->id,
                        'enquiry_id' => $enquiryId,
                        'project_code' => $project->project_id,
                        'project_title' => $project->enquiry?->title ?? 'Project',
                        'element' => $line->element?->name ?? 'Project materials',
                        'description' => $line->description,
                        'unit' => $row['issuable'] ? null : $line->unit_of_measurement,
                        'specified' => $row['required'],
                        'issued' => $row['net_issued'],
                        'retired' => $row['retired'],
                        'pending' => $row['remaining'],
                        'required_by' => $project->start_date?->toDateString(),
                        'lifecycle_state' => $described[$enquiryId]['state'] ?? null,
                        'overdue' => (bool) ($described[$enquiryId]['overdue'] ?? false),
                    ];
                })->filter()->values();
            })
            ->values();
    }

    /**
     * The fulfilment arithmetic, for one project's lines and movements.
     *
     * Report 78B: movements are counted in the material's stock unit, so the
     * requirement must be too. A line whose unit cannot be read in stock units
     * is reported in its BOM unit and is not issuable.
     *
     * @param  Collection<int, ElementMaterial>  $lines  sorted by id
     * @param  array<int, float>  $retiredBom  active retired quantity per line, BOM unit
     */
    private function fulfilment(Collection $lines, Collection $movements, array $retiredBom): array
    {
        EloquentCollection::make($lines->all())->loadMissing('libraryMaterial.uomConversions');
        $units = app(RequirementUnits::class);

        $reopensRequirement = fn ($row): bool => $row->type === 'return'
            && $row->return_kind !== 'recovered_offcut'
            && ! str_starts_with((string) $row->notes, 'Offcut ');

        $linkedIssues = $movements->whereIn('type', self::ISSUE_TYPES)
            ->whereNotNull('project_material_id')->groupBy('project_material_id');
        $linkedReturns = $movements->filter($reopensRequirement)
            ->whereNotNull('project_material_id')->groupBy('project_material_id');
        $legacyIssues = $movements->whereIn('type', self::ISSUE_TYPES)
            ->whereNull('project_material_id')->groupBy('material_id')
            ->map(fn (Collection $rows) => (float) $rows->sum(fn ($row) => abs((float) $row->quantity)));
        $legacyReturns = $movements->filter($reopensRequirement)
            ->whereNull('project_material_id')->groupBy('material_id')
            ->map(fn (Collection $rows) => (float) $rows->sum(fn ($row) => abs((float) $row->quantity)));

        $legacyNetRemaining = $legacyIssues->map(
            fn (float $issued, $materialId) => max(0.0, $issued - (float) ($legacyReturns[$materialId] ?? 0)),
        )->all();

        return $lines->mapWithKeys(function (ElementMaterial $line) use ($linkedIssues, $linkedReturns, &$legacyNetRemaining, $units, $retiredBom) {
            $inBase = $line->library_material_id ? $units->requiredInBase($line) : null;
            $required = $inBase !== null ? (float) $inBase : (float) $line->quantity;
            $issued = (float) ($linkedIssues->get($line->id)?->sum(fn ($row) => abs((float) $row->quantity)) ?? 0);
            $returned = (float) ($linkedReturns->get($line->id)?->sum(fn ($row) => abs((float) $row->quantity)) ?? 0);
            $materialId = (int) $line->library_material_id;
            $legacyForLine = $materialId ? min($required, (float) ($legacyNetRemaining[$materialId] ?? 0)) : 0.0;
            if ($materialId) {
                $legacyNetRemaining[$materialId] = max(0.0, (float) ($legacyNetRemaining[$materialId] ?? 0) - $legacyForLine);
            }
            $netIssued = max(0.0, $issued - $returned + $legacyForLine);
            // Report 78C: quantity recorded as fulfilled outside the ERP, read
            // in the same unit as the requirement.
            $retired = $this->retirements->retiredIn($line, $required, (float) ($retiredBom[$line->id] ?? 0));
            $remaining = max(0.0, $required - $netIssued - $retired);

            return [$line->id => [
                'required' => round($required, 4),
                'issued' => round($issued + $legacyForLine, 4),
                'returned' => round($returned, 4),
                'net_issued' => round($netIssued, 4),
                'retired' => round($retired, 4),
                'remaining' => round($remaining, 4),
                'fully_issued' => $remaining <= 0.00001,
                'unit' => $inBase !== null ? 'stock' : 'bom',
                'issuable' => $inBase !== null,
            ]];
        })->all();
    }

    /**
     * Included lines on materials-task lists Projects has signed off, on the
     * given jobs. An unapproved list is a draft: counting it would let a list
     * nobody has reviewed consume the availability a live job is relying on.
     *
     * @param  array<int, int>|null  $materialIds
     * @return Collection<int, ElementMaterial>
     */
    private function approvedLines(?array $materialIds, Collection $enquiryIds, bool $linked): Collection
    {
        return ElementMaterial::with(['element.taskMaterialsData.task', 'libraryMaterial.uomConversions'])
            ->where('is_included', true)
            ->when($linked, fn ($query) => $query->whereNotNull('library_material_id'))
            ->when($materialIds !== null, fn ($query) => $query->whereIn('library_material_id', $materialIds ?: [0]))
            ->whereHas('element.taskMaterialsData.task', fn ($query) => $query->whereIn('project_enquiry_id', $enquiryIds))
            ->orderBy('id')
            ->get()
            ->filter(fn (ElementMaterial $line) => (bool) data_get(
                $line->element?->taskMaterialsData?->project_info,
                'approval_status.all_approved',
                false,
            ))
            ->values();
    }

    /** @return Collection<int, Project> keyed by enquiry id */
    private function projectsFor(Collection $lines): Collection
    {
        $enquiryIds = $lines
            ->map(fn (ElementMaterial $line) => $line->element?->taskMaterialsData?->task?->project_enquiry_id)
            ->filter()->unique()->values();

        if ($enquiryIds->isEmpty()) {
            return collect();
        }

        return Project::with('enquiry:id,title,client_id')
            ->whereIn('enquiry_id', $enquiryIds)
            ->get()
            ->keyBy('enquiry_id');
    }
}
