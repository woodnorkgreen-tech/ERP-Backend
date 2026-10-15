<?php

namespace App\Modules\ProcurementStores\Services;

use App\Models\ElementMaterial;
use App\Models\GovernanceAuditLog;
use App\Models\MaterialRequirementRetirement;
use App\Models\Project;
use App\Modules\ProcurementStores\Models\BoardRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Report 78C — "Legacy — Fulfilled Outside ERP", one BOM line at a time.
 *
 * Much of WNG's history was issued by hand before the ERP's Stores workflow
 * existed, so the BOM still shows it as owed. Deleting those lines would lose
 * the record; inventing issues would invent stock movements and cost. A
 * retirement does neither: it records, with a reason and a name, that this
 * outstanding quantity was met outside the ERP, and demand stops counting it.
 *
 * Rules (D-1, D-5):
 *  - only someone holding PROJECT_MATERIALS_RETIRE_LEGACY, never by date;
 *  - only on a job still active or on hold — a completed, closed or cancelled
 *    job is already out of every queue and needs nothing retired;
 *  - never under an open board request, which would strand a reservation;
 *  - per line, so later or increased work on the same job stays live;
 *  - revoked by stamping the row with a reconciliation note, never deleted.
 *
 * Quantities are held in the BOM line's own unit and read in stock units
 * through the requirement's own factor, the same way the requirement is.
 */
class MaterialRequirementRetirements
{
    public const REASON_MIN = 10;

    public function __construct(
        private readonly ProjectMaterialWorkflowEligibility $eligibility,
    ) {}

    /**
     * Active retired quantity per line, in the BOM line's unit.
     *
     * @param  iterable<int>  $lineIds
     * @return array<int, float>
     */
    public function retiredBomByLine(iterable $lineIds): array
    {
        $ids = collect($lineIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return MaterialRequirementRetirement::query()->active()
            ->whereIn('element_material_id', $ids)
            ->selectRaw('element_material_id, SUM(retired_bom_quantity) AS retired')
            ->groupBy('element_material_id')
            ->pluck('retired', 'element_material_id')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    /**
     * Retired quantity read in the unit the requirement is being measured in.
     * $required is the requirement in that unit; the ratio to the BOM quantity
     * is the requirement's own conversion factor.
     */
    public function retiredIn(ElementMaterial $line, float $required, float $retiredBom): float
    {
        $bomQuantity = (float) $line->quantity;
        if ($retiredBom <= 0 || $bomQuantity <= 0) {
            return 0.0;
        }

        return $retiredBom * ($required / $bomQuantity);
    }

    /**
     * Retire the outstanding quantity of a job's lines (all included lines when
     * $lineIds is null). Lines with nothing outstanding are still recorded at
     * zero when the whole job is classified, so the job can be recognised as
     * fully legacy.
     *
     * @param  array<int, int>|null  $lineIds
     * @return Collection<int, MaterialRequirementRetirement>
     */
    public function retire(Project $project, ?array $lineIds, string $reason, int $userId): Collection
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::REASON_MIN) {
            throw ValidationException::withMessages(['reason' => 'Say how these materials were fulfilled outside the ERP (at least '.self::REASON_MIN.' characters).']);
        }

        $state = $this->eligibility->stateFor((int) $project->enquiry_id);
        if (! in_array($state, [ProjectMaterialWorkflowEligibility::STATE_ACTIVE, ProjectMaterialWorkflowEligibility::STATE_ON_HOLD], true)) {
            throw ValidationException::withMessages(['project_id' => match (true) {
                in_array($state, ProjectMaterialWorkflowEligibility::HISTORICAL_STATES, true) => "This job is {$state}; its requirements are already out of every active queue and do not need retiring.",
                default => 'Only an active or on-hold job can have requirements retired as fulfilled outside the ERP.',
            }]);
        }

        return DB::transaction(function () use ($project, $lineIds, $reason, $userId) {
            $lines = $this->jobLines($project)->when($lineIds !== null, fn ($c) => $c->whereIn('id', $lineIds));
            if ($lineIds !== null && $lines->count() !== count(array_unique($lineIds))) {
                throw ValidationException::withMessages(['line_ids' => 'Some selected lines do not belong to this job or are excluded from its material list.']);
            }
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['line_ids' => 'This job has no included material lines to retire.']);
            }

            ElementMaterial::query()->whereIn('id', $lines->pluck('id'))->lockForUpdate()->get(['id']);

            $openBoardRequests = BoardRequest::query()
                ->whereIn('project_material_id', $lines->pluck('id'))
                ->where('status', 'pending')
                ->pluck('project_material_id')->unique();
            if ($openBoardRequests->isNotEmpty()) {
                $names = $lines->whereIn('id', $openBoardRequests)->pluck('description')->take(3)->implode(', ');
                throw ValidationException::withMessages(['line_ids' => "Board requests are still open for: {$names}. Issue or cancel them before retiring these lines."]);
            }

            $fulfilment = app(ProjectMaterialDemand::class)->fulfilmentForProject($project, $lines);
            $created = collect();
            foreach ($lines as $line) {
                $row = $fulfilment[$line->id] ?? null;
                $outstandingBom = $this->outstandingInBom($line, $row);
                $alreadyCovered = MaterialRequirementRetirement::query()->active()
                    ->where('element_material_id', $line->id)
                    ->where('bom_quantity_snapshot', '>=', $line->quantity)
                    ->exists();

                // Nothing new to say about a line already covered at this quantity.
                if ($alreadyCovered && $outstandingBom <= 0.000001) {
                    continue;
                }
                if ($outstandingBom <= 0.000001 && $lineIds !== null) {
                    continue;
                }

                $created->push(MaterialRequirementRetirement::create([
                    'element_material_id' => $line->id,
                    'project_enquiry_id' => $project->enquiry_id,
                    'retired_bom_quantity' => round(max(0.0, $outstandingBom), 6),
                    'bom_quantity_snapshot' => $line->quantity,
                    'basis' => MaterialRequirementRetirement::BASIS_LEGACY,
                    'reason' => $reason,
                    'retired_by' => $userId,
                    'retired_at' => now(),
                ]));
            }

            if ($created->isEmpty()) {
                throw ValidationException::withMessages(['line_ids' => 'Nothing is outstanding on the selected lines, so there is nothing to retire.']);
            }

            GovernanceAuditLog::create([
                'project_enquiry_id' => $project->enquiry_id,
                'user_id' => $userId,
                'gate_type' => 'material_requirements_retired',
                'action_status' => 'authorized',
                'message' => 'Requirements recorded as fulfilled outside the ERP: '.$reason,
                'context' => [
                    'retirement_ids' => $created->pluck('id')->all(),
                    'line_ids' => $created->pluck('element_material_id')->all(),
                    'whole_job' => $lineIds === null,
                ],
                'ip_address' => request()?->ip(),
            ]);

            return $created;
        });
    }

    /**
     * Revoke active retirements (all of a job's when $retirementIds is null).
     * The quantity comes straight back as live demand — nothing was stored.
     *
     * @param  array<int, int>|null  $retirementIds
     */
    public function revoke(Project $project, ?array $retirementIds, string $reason, string $reconciliationNote, int $userId): int
    {
        $reason = trim($reason);
        $note = trim($reconciliationNote);
        if (mb_strlen($reason) < self::REASON_MIN) {
            throw ValidationException::withMessages(['reason' => 'Say why these requirements are live again (at least '.self::REASON_MIN.' characters).']);
        }
        if (mb_strlen($note) < self::REASON_MIN) {
            throw ValidationException::withMessages(['reconciliation_note' => 'Record what was actually issued outside the ERP, so Stores does not issue it twice (at least '.self::REASON_MIN.' characters).']);
        }

        return DB::transaction(function () use ($project, $retirementIds, $reason, $note, $userId) {
            $rows = MaterialRequirementRetirement::query()->active()
                ->where('project_enquiry_id', $project->enquiry_id)
                ->when($retirementIds !== null, fn ($q) => $q->whereIn('id', $retirementIds ?: [0]))
                ->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['retirement_ids' => 'There are no active retirements to revoke on this job.']);
            }

            MaterialRequirementRetirement::query()->whereIn('id', $rows->pluck('id'))->update([
                'revoked_by' => $userId,
                'revoked_at' => now(),
                'revoke_reason' => $reason,
                'reconciliation_note' => $note,
            ]);

            GovernanceAuditLog::create([
                'project_enquiry_id' => $project->enquiry_id,
                'user_id' => $userId,
                'gate_type' => 'material_requirements_retirement_revoked',
                'action_status' => 'authorized',
                'message' => 'Retired requirements made live again: '.$reason,
                'context' => ['retirement_ids' => $rows->pluck('id')->all(), 'reconciliation_note' => $note],
                'ip_address' => request()?->ip(),
            ]);

            return $rows->count();
        });
    }

    /** @return Collection<int, MaterialRequirementRetirement> */
    public function history(Project $project): Collection
    {
        return MaterialRequirementRetirement::query()
            ->where('project_enquiry_id', $project->enquiry_id)
            ->with(['line:id,description,unit_of_measurement,quantity', 'retiredBy', 'revokedBy'])
            ->orderByDesc('id')->get();
    }

    /** Included lines of the job's materials list(s). */
    private function jobLines(Project $project): Collection
    {
        return ElementMaterial::query()
            ->where('is_included', true)
            ->whereHas('element.taskMaterialsData.task', fn ($q) => $q->where('project_enquiry_id', $project->enquiry_id))
            ->with('libraryMaterial.uomConversions')
            ->orderBy('id')->get();
    }

    /** The live outstanding quantity of a line, in the BOM line's own unit. */
    private function outstandingInBom(ElementMaterial $line, ?array $row): float
    {
        if (! $row) {
            return max(0.0, (float) $line->quantity);
        }
        $required = (float) $row['required'];
        $remaining = (float) $row['remaining'];
        if ($required <= 0) {
            return 0.0;
        }

        // remaining is in whatever unit required is in; scale back to the BOM unit.
        return $remaining * ((float) $line->quantity / $required);
    }
}
