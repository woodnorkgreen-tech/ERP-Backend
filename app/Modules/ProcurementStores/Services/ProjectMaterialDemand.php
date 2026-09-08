<?php

namespace App\Modules\ProcurementStores\Services;

use App\Models\ElementMaterial;
use App\Models\Project;
use App\Modules\ProcurementStores\Models\InventoryLog;
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
 */
class ProjectMaterialDemand
{
    /** Movements that take material out of the store against a job. */
    private const ISSUE_TYPES = ['check_out', 'issue', 'consumption'];

    /**
     * Approved, unissued demand per library material.
     *
     * @param  array<int, int>|null  $materialIds  Narrow to these materials; null for all.
     * @return array<int, float> keyed by library_material_id, zero-demand materials omitted
     */
    public function pendingByMaterial(?array $materialIds = null): array
    {
        return $this->pendingLines($materialIds)
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
    public function pendingLines(?array $materialIds = null): Collection
    {
        $specified = $this->specifiedLines($materialIds);

        if ($specified->isEmpty()) {
            return collect();
        }

        $projects = $this->projectsFor($specified);

        if ($projects->isEmpty()) {
            return collect();
        }

        [$issued, $returned] = $this->movementsByLine($projects->pluck('id'));

        return $specified->map(function (ElementMaterial $line) use ($projects, $issued, $returned) {
            $task = $line->element?->taskMaterialsData?->task;
            $project = $projects->get($task?->project_enquiry_id);

            // A specification whose enquiry never became a project is a quote,
            // not a commitment on the shelf.
            if (! $project) {
                return null;
            }

            $specifiedQuantity = (float) $line->quantity;

            // Returns reopen the line by the quantity that came back, so a
            // material issued and then returned is owed to the job again.
            $issuedNet = max(0.0, (float) ($issued[$line->id] ?? 0) - (float) ($returned[$line->id] ?? 0));

            // Over-issuing is a Stores discrepancy, not negative demand.
            $pending = max(0.0, $specifiedQuantity - $issuedNet);

            return $pending > 0 ? [
                'library_material_id' => (int) $line->library_material_id,
                'project_material_id' => $line->id,
                'project_id' => $project->id,
                'project_code' => $project->project_id,
                'project_title' => $project->enquiry?->title ?? 'Project',
                'element' => $line->element?->name ?? 'Project materials',
                'specified' => round($specifiedQuantity, 4),
                'issued' => round($issuedNet, 4),
                'pending' => round($pending, 4),
                'required_by' => $project->start_date?->toDateString(),
            ] : null;
        })->filter()->values();
    }

    /**
     * Included specification lines on materials-task lists Projects has signed
     * off. An unapproved list is a draft: counting it would let a list nobody
     * has reviewed consume the availability a live job is relying on.
     *
     * @param  array<int, int>|null  $materialIds
     * @return Collection<int, ElementMaterial>
     */
    private function specifiedLines(?array $materialIds): Collection
    {
        return ElementMaterial::with(['element.taskMaterialsData.task'])
            ->where('is_included', true)
            ->whereNotNull('library_material_id')
            ->when($materialIds !== null, fn ($query) => $query->whereIn('library_material_id', $materialIds))
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
    private function projectsFor(Collection $specified): Collection
    {
        $enquiryIds = $specified
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

    /**
     * Issues and reopening returns, summed per specification line.
     *
     * An offcut coming back is recovery, not an unmet requirement — the job
     * consumed the sheet it was given — so it does not reopen the line.
     *
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    private function movementsByLine(Collection $projectIds): array
    {
        $movements = InventoryLog::query()
            ->whereIn('project_id', $projectIds)
            ->whereIn('type', [...self::ISSUE_TYPES, 'return'])
            ->whereNotNull('project_material_id')
            ->get(['id', 'type', 'quantity', 'project_material_id', 'return_kind', 'notes']);

        $issued = $movements->whereIn('type', self::ISSUE_TYPES)
            ->groupBy('project_material_id')
            ->map(fn (Collection $rows) => (float) $rows->sum(fn ($row) => abs((float) $row->quantity)))
            ->all();

        $returned = $movements->where('type', 'return')
            ->filter(fn ($row) => $row->return_kind !== 'recovered_offcut'
                && ! str_starts_with((string) $row->notes, 'Offcut '))
            ->groupBy('project_material_id')
            ->map(fn (Collection $rows) => (float) $rows->sum('quantity'))
            ->all();

        return [$issued, $returned];
    }
}
