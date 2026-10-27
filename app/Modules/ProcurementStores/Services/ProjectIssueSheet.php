<?php

namespace App\Modules\ProcurementStores\Services;

use App\Models\ElementMaterial;
use App\Models\Project;
use App\Models\TaskMaterialsData;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Services\MaterialSearch;
use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\ProcurementStores\Services\ProjectMaterialWorkflowEligibility as Eligibility;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Report 79E — what Stores can issue on one project, and why not the rest.
 *
 * A read. It writes nothing and stores nothing: every figure comes from the
 * service that owns it (demand from ProjectMaterialDemand, units from
 * RequirementUnits, lifecycle from the 78C eligibility rule, availability from
 * InventoryValuationService, candidates from MaterialSearch). The screen shows
 * these answers instead of working them out again.
 *
 * Identity and readiness are separate answers. A line can be correctly
 * identified and still have no stock; readiness never comes from a catalogue
 * match alone. Suggestions are only suggestions: a person confirms them.
 */
class ProjectIssueSheet
{
    public const READY = 'ready';
    public const PARTIAL = 'partial';
    public const NEEDS_MATCH = 'needs_match';
    public const AWAITING_CATALOGUE = 'awaiting_catalogue';
    public const AWAITING_STOCK = 'awaiting_stock';
    public const UNIT_REVIEW = 'unit_review';
    public const APPROVAL_REQUIRED = 'approval_required';
    public const RESTRICTED = 'restricted';
    public const FULFILLED = 'fulfilled';
    public const NON_STOCK = 'non_stock';

    public const STATES = [
        self::READY, self::PARTIAL, self::NEEDS_MATCH, self::AWAITING_CATALOGUE, self::AWAITING_STOCK,
        self::UNIT_REVIEW, self::APPROVAL_REQUIRED, self::RESTRICTED, self::FULFILLED, self::NON_STOCK,
    ];

    /** Candidate materials for this build, loaded once. */
    private Collection $candidateMaterials;

    public function __construct(
        private readonly ProjectMaterialDemand $demand,
        private readonly Eligibility $eligibility,
        private readonly RequirementUnits $units,
        private readonly MaterialSearch $search,
        private readonly InventoryValuationService $valuation,
    ) {}

    public function build(Project $project): array
    {
        $enquiryId = (int) $project->enquiry_id;
        $lifecycle = $this->eligibility->describeMany([$enquiryId])[$enquiryId] ?? null;
        $canIssue = (bool) ($lifecycle['can'][Eligibility::CAP_ISSUE] ?? false);
        $overridable = (bool) ($lifecycle['overridable'][Eligibility::CAP_ISSUE] ?? false);

        $data = $this->materialsData($enquiryId);
        $approved = (bool) data_get($data?->project_info, 'approval_status.all_approved', false);
        $lines = $data
            ? $data->elements->flatMap(fn ($element) => $element->materials
                ->filter(fn (ElementMaterial $line) => $line->is_included && ! $line->archived_at)
                ->each(fn (ElementMaterial $line) => $line->setRelation('element', $element)))
                ->sortBy('id')->values()
            : collect();

        $fulfilment = $lines->isEmpty() ? [] : $this->demand->fulfilmentForProject($project, $lines);
        $stock = $this->availability($lines);
        $awaitingPurchase = $this->awaitingPurchase($project, $lines);
        $candidates = $this->candidates($lines);
        $uomCodes = DB::table('units_of_measure')->pluck('code', 'id')->all();

        $rows = $lines->map(fn (ElementMaterial $line) => $this->row(
            $line, $fulfilment[$line->id] ?? null, $stock, $awaitingPurchase, $candidates, $uomCodes,
            // A job a manager may still issue to (78C override) is read in
            // full; the override reason is asked for at confirmation.
            $approved, $canIssue || $overridable, $lifecycle,
        ))->values();

        $counts = array_fill_keys(self::STATES, 0);
        foreach ($rows as $row) {
            $counts[$row['readiness']]++;
        }

        return [
            'project' => [
                'id' => $project->id,
                'enquiry_id' => $enquiryId,
                'code' => $project->project_id,
                'title' => $project->enquiry?->title,
                'lifecycle' => $lifecycle,
                'can_issue' => $canIssue,
                'override_allowed' => $overridable,
                // Issuing needs a recorded override reason (and the right to give one).
                'needs_override' => ! $canIssue && $overridable,
                'approved' => $approved,
            ],
            'counts' => $counts,
            'finance_exceptions' => $this->financeExceptions($project),
            'lines' => $rows->all(),
        ];
    }

    private function materialsData(int $enquiryId): ?TaskMaterialsData
    {
        $taskId = EnquiryTask::where('project_enquiry_id', $enquiryId)->where('type', 'materials')->value('id');

        return $taskId ? TaskMaterialsData::with([
            'elements.materials.libraryMaterial.uomConversions',
            'elements.materials.libraryMaterial.baseUom',
            'elements.materials.libraryMaterial.stock',
            'elements.materials.libraryMaterial.materialCategory.parent',
            'elements.materials.requestedMaterial',
        ])->where('enquiry_task_id', $taskId)->first() : null;
    }

    /** @return array<int, array{on_hand: float, reserved: float, available: float}> keyed by material id */
    private function availability(Collection $lines): array
    {
        $materials = $lines->map->libraryMaterial->filter()->unique('id');
        $boards = $this->valuation->boardCounts($materials->filter->isBoardTrackable()->pluck('id'));

        return $materials->mapWithKeys(fn (LibraryMaterial $material) => [
            $material->id => $this->valuation->issuable($material, $boards->get($material->id)),
        ])->all();
    }

    /**
     * Open requisitions for this project naming the material. Requisition
     * lines carry no BOM line id, so a linked line is matched by material;
     * an unlinked line is never matched by its wording.
     *
     * @return array<int, true> keyed by material id
     */
    private function awaitingPurchase(Project $project, Collection $lines): array
    {
        $ids = $lines->pluck('library_material_id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('requisition_items')
            ->join('requisitions', 'requisitions.id', '=', 'requisition_items.requisition_id')
            ->where('requisitions.project_id', $project->id)
            ->whereNotIn('requisitions.status', ['rejected', 'cancelled', 'canceled', 'completed', 'closed'])
            ->whereIn('requisition_items.material_id', $ids)
            ->distinct()->pluck('requisition_items.material_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /**
     * Catalogue candidates for every line that has no identity yet. The Active
     * catalogue is read once and each distinct description scored against it
     * (rank() would re-read it per description: ~30 ms each on dev).
     *
     * @return array<string, array> keyed by lower-cased description
     */
    private function candidates(Collection $lines): array
    {
        $this->candidateMaterials = collect();
        $open = $lines->filter(fn (ElementMaterial $line) => ! $line->library_material_id
            && $line->requirement_disposition === null);
        if ($open->isEmpty()) {
            return [];
        }

        $pool = LibraryMaterial::query()->where('item_status', 'Active')
            ->get(['id', 'material_name', 'material_code', 'alternative_item_name'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => (string) $row->material_name,
                'code' => (string) $row->material_code, 'aliases' => (string) ($row->alternative_item_name ?? '')])
            ->all();

        $descriptions = $open->map(fn ($line) => mb_strtolower(trim((string) $line->description)))->unique()->values();
        $history = DB::table('element_materials')->whereNotNull('library_material_id')
            ->whereIn(DB::raw('LOWER(TRIM(description))'), $descriptions->all())
            ->select(DB::raw('LOWER(TRIM(description)) AS d'), 'library_material_id', DB::raw('COUNT(*) AS n'))
            ->groupBy('d', 'library_material_id')->get()
            ->groupBy('d')->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [(int) $r->library_material_id => (int) $r->n])->all());

        $result = [];
        foreach ($this->search->scoreMany($descriptions->all(), $pool, $history->all(), 6) as $description => $ranked) {
            $result[$description] = ['ranked' => $ranked, 'verdict' => $this->search->verdict($ranked)];
        }

        $ids = collect($result)->flatMap(fn ($found) => array_keys($found['ranked']))->unique()->values();
        $this->candidateMaterials = LibraryMaterial::with(['uomConversions', 'baseUom', 'materialCategory.parent'])
            ->whereIn('id', $ids)->get()->keyBy('id');

        return $result;
    }

    private function row(
        ElementMaterial $line, ?array $state, array $stock, array $awaitingPurchase, array $candidates,
        array $uomCodes, bool $approved, bool $canIssue, ?array $lifecycle,
    ): array {
        $material = $line->libraryMaterial;
        $identity = $this->identity($line, $candidates);
        $unit = $material ? $this->units->assess($line, $material) : null;
        $available = $material ? (float) ($stock[$material->id]['available'] ?? 0) : 0.0;
        $remaining = (float) ($state['remaining'] ?? 0);
        $route = $material ? $this->route($material) : null;

        [$readiness, $reason] = $this->readiness($line, $identity, $unit, $state, $available, $approved, $canIssue, $lifecycle, $material);
        $suggested = in_array($readiness, [self::READY, self::PARTIAL], true) ? round(min($remaining, $available), 4) : 0.0;
        if ($route === 'board') {
            $suggested = floor($suggested);
        }

        return [
            'line_id' => $line->id,
            'element' => $line->element?->name ?? 'Project materials',
            'description' => $line->description,
            'requested' => ['quantity' => (float) $line->quantity, 'unit' => $line->unit_of_measurement],
            'identity' => $identity,
            'quantities' => [
                'required' => (float) ($state['required'] ?? $line->quantity),
                'issued' => (float) ($state['issued'] ?? 0),
                'returned' => (float) ($state['returned'] ?? 0),
                'retired' => (float) ($state['retired'] ?? 0),
                'remaining' => $remaining,
                // Stock units once the requirement can be read in them.
                'unit' => ($state['unit'] ?? 'bom') === 'stock' && $material
                    ? ($material->baseUom?->code ?? $material->unit_of_measure)
                    : $line->unit_of_measurement,
            ],
            'unit' => $unit ? ['status' => $unit['status'], 'factor' => $unit['factor'], 'reason' => $unit['reason'], 'declarable' => $unit['declarable'] ?? false] : null,
            'issue_units' => $material ? $this->units->issueUnits($line, $uomCodes) : [],
            'stock' => $material ? [
                'available' => $available,
                'on_hand' => (float) ($stock[$material->id]['on_hand'] ?? 0),
                'route' => $route,
                'awaiting_purchase' => isset($awaitingPurchase[$material->id]),
            ] : null,
            'readiness' => $readiness,
            'suggested_issue' => $suggested,
            'blocking_reason' => $reason,
            'actions' => $this->actions($readiness, $identity, $unit, $route),
        ];
    }

    private function identity(ElementMaterial $line, array $candidates): array
    {
        $material = $line->libraryMaterial;
        $describe = fn (?LibraryMaterial $m) => $m ? [
            'id' => $m->id, 'code' => $m->material_code, 'name' => $m->material_name,
            'status' => $m->item_status ?? 'Active',
            'stock_unit' => $m->baseUom?->code ?? $m->unit_of_measure,
            'category' => $m->materialCategory?->parent?->name ?? $m->materialCategory?->name ?? $m->category,
        ] : null;

        if ($material) {
            return [
                'status' => ($material->item_status ?? 'Active') === 'Active' ? 'verified' : 'awaiting_catalogue',
                'material' => $describe($material), 'candidates' => [], 'distinguish' => null,
            ];
        }
        if ($line->requirement_disposition === ElementMaterial::DISPOSITION_NON_STOCK) {
            return ['status' => 'non_stock', 'material' => null, 'candidates' => [], 'distinguish' => null];
        }
        if ($line->requirement_disposition === ElementMaterial::DISPOSITION_AWAITING_REGISTRATION) {
            return ['status' => 'awaiting_catalogue', 'material' => $describe($line->requestedMaterial), 'candidates' => [], 'distinguish' => null];
        }

        $found = $candidates[mb_strtolower(trim((string) $line->description))] ?? ['ranked' => [], 'verdict' => 'none'];
        if ($found['ranked'] === []) {
            // releaseRejected() records why a requested material never became Active.
            $rejected = filled(data_get($line->source_metadata, 'material_request.outcome.status'));

            return ['status' => $rejected ? 'rejected' : 'no_match', 'material' => null, 'candidates' => [], 'distinguish' => null];
        }

        $materials = $this->candidateMaterials;
        $list = [];
        foreach ($found['ranked'] as $id => $hit) {
            $candidate = $materials->get($id);
            if (! $candidate) {
                continue;
            }
            $reading = $this->units->assess($line, $candidate);
            $list[] = array_merge($describe($candidate), [
                'tier' => $hit['tier'], 'reason' => $hit['reason'], 'spec_differs' => $hit['spec_differs'],
                'unit_reads' => $reading['status'] === 'compatible',
                'unit_reason' => $reading['reason'],
            ]);
        }

        $top = $list[0] ?? null;
        $clear = $top && in_array($found['verdict'], [MaterialSearch::TIER_EXACT, MaterialSearch::TIER_LIKELY], true) && ! $top['spec_differs'];

        return [
            'status' => $clear ? 'suggested' : 'ambiguous',
            'material' => null,
            // One click is offered only for a clear match whose unit reads.
            'one_click' => $clear && $top['unit_reads'],
            'candidates' => array_slice($list, 0, 3),
            'distinguish' => $clear ? null : $this->distinguish($list),
        ];
    }

    /**
     * When the candidates say the same thing in different sizes ("MDF 6mm",
     * "MDF 9mm", "MDF 18mm"), the person only needs to answer "which size?".
     * Read from the names: the stored attributes are not trustworthy (79D).
     */
    private function distinguish(array $candidates): ?array
    {
        $sizes = [];
        foreach ($candidates as $candidate) {
            if (! preg_match_all('/\d+(?:[.\/]\d+)?\s*(?:mm|cm|m|v|w|kg|g|l|ml|"|inch|in|ft)?\b/i', (string) $candidate['name'], $matches)) {
                continue;
            }
            $size = trim(implode(' ', array_map('trim', $matches[0])));
            $sizes[$size] ??= ['value' => $size, 'material_id' => $candidate['id'], 'name' => $candidate['name'], 'unit_reads' => $candidate['unit_reads']];
        }

        return count($sizes) >= 2 ? ['label' => 'size', 'values' => array_values($sizes)] : null;
    }

    private function route(LibraryMaterial $material): string
    {
        return match (true) {
            $material->isBoardTrackable() => 'board',
            $material->isConsumableUnit() => 'roll',
            (bool) ($material->is_batch_controlled || $material->is_serialized) => 'controlled',
            default => 'plain',
        };
    }

    /** @return array{0: string, 1: ?string} first rule that applies wins */
    private function readiness(
        ElementMaterial $line, array $identity, ?array $unit, ?array $state, float $available,
        bool $approved, bool $canIssue, ?array $lifecycle, ?LibraryMaterial $material,
    ): array {
        $state ??= [];
        if ($identity['status'] === 'non_stock') {
            return [self::NON_STOCK, 'Bought directly for the job; not issued from Stores.'];
        }
        if (! $canIssue) {
            $label = str_replace('_', ' ', (string) ($lifecycle['state'] ?? 'not active'));

            return [self::RESTRICTED, "This job is {$label}, so Stores cannot issue to it."];
        }
        if (! $approved) {
            return [self::APPROVAL_REQUIRED, 'Project Officer and Production must sign off the material list first.'];
        }
        if ($material && ($state['remaining'] ?? 1) <= 0.00001 && ($state['unit'] ?? 'bom') === 'stock') {
            return [self::FULFILLED, null];
        }
        if ($identity['status'] === 'awaiting_catalogue') {
            return [self::AWAITING_CATALOGUE, 'The material is waiting for the Material Library custodian to approve it.'];
        }
        if (! $material) {
            return [self::NEEDS_MATCH, match ($identity['status']) {
                'suggested' => 'Confirm the suggested catalogue item.',
                'ambiguous' => 'Choose which catalogue item this is.',
                'rejected' => 'The requested material was not approved. Choose an existing item or ask again.',
                default => 'No catalogue item matches. Request a new material, or mark it as bought directly.',
            }];
        }
        if (($unit['status'] ?? null) !== 'compatible') {
            return [self::UNIT_REVIEW, $unit['reason'] ?? 'The requested unit cannot be read in the stock unit.'];
        }
        if ($available <= 0.00001) {
            return [self::AWAITING_STOCK, 'None in stock.'];
        }
        if ($available + 0.00001 < (float) ($state['remaining'] ?? 0)) {
            return [self::PARTIAL, 'Only '.round($available, 4).' available of '.round((float) $state['remaining'], 4).' still needed.'];
        }

        return [self::READY, null];
    }

    private function actions(string $readiness, array $identity, ?array $unit, ?string $route): array
    {
        return match ($readiness) {
            self::READY, self::PARTIAL => array_values(array_filter([
                match ($route) { 'board' => 'pick_boards', 'roll' => 'pick_roll', 'controlled' => 'pick_lot', default => 'issue' },
                $readiness === self::PARTIAL ? 'request_stock' : null,
            ])),
            self::NEEDS_MATCH => array_values(array_filter([
                ! empty($identity['one_click']) ? 'confirm_match' : null,
                ! empty($identity['distinguish']) ? 'choose_variant' : null,
                'resolve',
            ])),
            self::UNIT_REVIEW => ! empty($unit['declarable']) ? ['set_unit_reading'] : ['resolve'],
            self::AWAITING_STOCK => ['request_stock'],
            default => [],
        };
    }

    private function financeExceptions(Project $project): int
    {
        return (int) DB::table('stores_finance_postings')
            ->join('inventory_logs', 'inventory_logs.id', '=', 'stores_finance_postings.inventory_log_id')
            ->where('inventory_logs.project_id', $project->id)
            ->where('stores_finance_postings.status', 'failed')
            ->count();
    }
}
