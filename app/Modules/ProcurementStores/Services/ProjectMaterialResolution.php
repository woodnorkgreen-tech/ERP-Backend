<?php

namespace App\Modules\ProcurementStores\Services;

use App\Events\MaterialsListChanged;
use App\Models\ElementMaterial;
use App\Models\ProjectEnquiry;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialUomConversion;
use App\Modules\MaterialsLibrary\Services\MaterialDuplicateFinder;
use App\Modules\MaterialsLibrary\Services\MaterialRegistrationService;
use App\Modules\ProcurementStores\Models\RequisitionItem;
use App\Services\MaterialLineIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Report 78B — Resolve Material: give a BOM requirement a Material Library
 * identity, and nothing else.
 *
 * Resolution is a controlled mapping between an approved requirement and an
 * Active library item. It records who mapped it, when, and how the BOM unit
 * reads in the item's stock unit. It never posts stock, a GRN, a journal, a
 * bill, a purchase order or a project cost, and it never rewrites the BOM's
 * description, quantity or unit. Stock that is on hand without a purchase is
 * taken in through Receive Stock, where the `stores.review` controls live.
 *
 * When no item exists, it is registered through the Material Library's own
 * form and endpoint (MaterialRegistrationService) and then linked here like
 * any other item. An item that lands Under Review — or one requested by
 * someone without catalogue rights — leaves the line "awaiting registration";
 * activation links it automatically (linkAwaiting()), through this same path,
 * with the same checks.
 *
 * Units: a requirement the catalogue cannot read can be given a reading at
 * resolution, either for that line only or kept on the material
 * (RequirementUnits rule 4). Lines with the same description elsewhere can be
 * linked in the same step; they never inherit a per-line reading.
 *
 * A genuine one-off purchase is classified "non-stock / direct purchase" and
 * never becomes a fictitious inventory item.
 */
class ProjectMaterialResolution
{
    private const OPEN_REQUISITION_STATUSES = ['draft', 'pending_approval', 'approved'];

    public function __construct(
        private readonly RequirementUnits $units,
        private readonly MaterialLineIdentity $identity,
        private readonly MaterialDuplicateFinder $duplicates,
    ) {}

    /**
     * Link an unlinked requirement to a library item.
     *
     * Options:
     *  - factor: stock units in one requirement unit, for a requirement the
     *    catalogue cannot read (RequirementUnits rule 4).
     *  - save_to_material: keep that reading on the material, so every job
     *    asking in this unit reads the same way from now on.
     *  - apply_to_matching: link every other open line with the same
     *    description too, where the unit reads without a per-line reading.
     *
     * An item still Under Review cannot be issued, so the line waits on it and
     * links itself the moment the item is activated (linkAwaiting()).
     *
     * @param  array{factor?: ?string, save_to_material?: bool, apply_to_matching?: bool}  $options
     * @return array{outcome: string, line: ElementMaterial, material: LibraryMaterial, unit: ?array, requisitions: array, matching: ?array}
     */
    public function resolve(ElementMaterial $line, int $materialId, int $userId, ?string $note = null, array $options = []): array
    {
        $result = $this->link($line, $materialId, $userId, $note, $options['factor'] ?? null, (bool) ($options['save_to_material'] ?? false));
        $taskIds = [$result['task_id']];

        $result['matching'] = null;
        if (($options['apply_to_matching'] ?? false) && $result['outcome'] === 'resolved') {
            $result['matching'] = $this->resolveMatching($result['line'], $result['material'], $userId);
            $taskIds = array_merge($taskIds, $result['matching']['task_ids']);
        }

        $this->announce($taskIds);

        return $result;
    }

    /**
     * One locked write of a line's identity; every path that links a line
     * comes through here. Announcing the change is left to the caller, so a
     * batch announces each materials list once.
     *
     * @return array{outcome: string, line: ElementMaterial, material: LibraryMaterial, unit: ?array, requisitions: array, task_id: ?int}
     */
    private function link(ElementMaterial $line, int $materialId, int $userId, ?string $note, ?string $factor, bool $saveToMaterial): array
    {
        return DB::transaction(function () use ($line, $materialId, $userId, $note, $factor, $saveToMaterial) {
            $locked = ElementMaterial::with('element.taskMaterialsData.task')->lockForUpdate()->findOrFail($line->id);
            $this->assertOpenForStores($locked);

            if ($locked->library_material_id) {
                throw ValidationException::withMessages(['material_id' => 'This line is already linked to a Material Library item.']);
            }
            if ($locked->requirement_disposition === ElementMaterial::DISPOSITION_NON_STOCK) {
                throw ValidationException::withMessages(['material_id' => 'This line is classified as a non-stock direct purchase. Return it to "awaiting resolution" before linking it to an inventory material.']);
            }

            $material = LibraryMaterial::with(['uomConversions', 'baseUom'])->findOrFail($materialId);
            if (($material->item_status ?? 'Active') === 'Under Review') {
                return $this->park($locked, $material, $userId, $note, $factor, $saveToMaterial);
            }
            if (($material->item_status ?? 'Active') !== 'Active') {
                throw ValidationException::withMessages([
                    'material_id' => "Only Active Material Library items can be linked. '{$material->material_name}' is {$material->item_status}.",
                ]);
            }

            // A line whose link was lost may still carry issue history; it can
            // only be relinked to the material that was actually issued.
            $this->identity->assertCanBecome($locked, (int) $material->id, 'material_id');

            if ($saveToMaterial && $factor !== null) {
                $this->learnConversion($locked, $material, $factor);
                $factor = null;
            }

            // Refuses an incompatible unit with the reason, before anything is written.
            $snapshot = $this->units->snapshot($locked, $material, $userId, $factor);

            $entry = [
                'library_material_id' => (int) $material->id,
                'material_name' => $material->material_name,
                'material_code' => $material->material_code,
                'resolved_by' => $userId,
                'resolved_at' => now()->toIso8601String(),
                'original' => [
                    'description' => $locked->description,
                    'quantity' => (string) $locked->getRawOriginal('quantity'),
                    'unit' => $locked->unit_of_measurement,
                ],
                'unit_basis' => $snapshot['requirement_unit_basis'],
                'requested_material_id' => $locked->requested_material_id,
                'note' => $note,
            ];
            $metadata = $locked->source_metadata ?? [];
            $metadata['catalogue_resolution'] = [
                'resolved_by' => $userId, 'resolved_at' => $entry['resolved_at'], 'library_material_id' => (int) $material->id,
            ];
            $metadata['catalogue_resolutions'] = array_merge($metadata['catalogue_resolutions'] ?? [], [$entry]);

            $locked->forceFill(array_merge($snapshot, [
                'library_material_id' => $material->id,
                'requirement_disposition' => null,
                'source_metadata' => $metadata,
            ]))->save();

            // Open Materials screens see a newer list and must reload before
            // saving (the optimistic lock), on top of the identity baseline.
            $materialsData = $locked->element?->taskMaterialsData;
            $materialsData?->touch();

            $requisitions = $this->reconcileRequisitions($locked, $material, $snapshot['requirement_base_factor']);

            return [
                'outcome' => 'resolved',
                'line' => $locked->fresh(['libraryMaterial']),
                'material' => $material,
                'unit' => $this->units->assess($locked->fresh(), $material),
                'requisitions' => $requisitions,
                'task_id' => $materialsData?->enquiry_task_id ? (int) $materialsData->enquiry_task_id : null,
            ];
        });
    }

    /**
     * Point the line at an item that is still Under Review. Nothing is linked
     * or issuable; activation links it (linkAwaiting()) with the reading the
     * resolver gave here.
     */
    private function park(ElementMaterial $locked, LibraryMaterial $material, int $userId, ?string $note, ?string $factor, bool $saveToMaterial): array
    {
        if ($locked->requirement_disposition === ElementMaterial::DISPOSITION_AWAITING_REGISTRATION
            && (int) $locked->requested_material_id === (int) $material->id) {
            throw ValidationException::withMessages([
                'material_id' => "'{$material->material_name}' is still Under Review. This line links to it automatically once it is activated.",
            ]);
        }

        if ($saveToMaterial && $factor !== null) {
            $this->learnConversion($locked, $material, $factor);
            $factor = null;
        }
        // Prove the reading now, so activation cannot fail on the unit later.
        $this->units->snapshot($locked, $material, $userId, $factor);

        $metadata = $locked->source_metadata ?? [];
        $metadata['material_request'] = [
            'requested_material_id' => (int) $material->id,
            'requested_by' => $userId,
            'requested_at' => now()->toIso8601String(),
            'reason' => $note ?: 'Linked to a material that is still Under Review.',
            'factor' => $factor,
            'original' => ['description' => $locked->description, 'quantity' => (string) $locked->getRawOriginal('quantity'), 'unit' => $locked->unit_of_measurement],
            'project' => $this->projectReference($locked),
        ];
        $locked->forceFill([
            'requirement_disposition' => ElementMaterial::DISPOSITION_AWAITING_REGISTRATION,
            'requested_material_id' => $material->id,
            'source_metadata' => $metadata,
        ])->save();

        return [
            'outcome' => 'awaiting_registration',
            'line' => $locked->fresh(['requestedMaterial']),
            'material' => $material,
            'unit' => null,
            'requisitions' => ['relinked' => [], 'needs_review' => []],
            'task_id' => null,
        ];
    }

    /**
     * Keep a requirement's reading on the material itself (material_uom_conversions),
     * so every requirement in that unit reads the same way. Only for a reading
     * that truly belongs to the material — a sheet's area, a packet's weight.
     */
    private function learnConversion(ElementMaterial $line, LibraryMaterial $material, string $factor): void
    {
        $required = $this->units->normalise($line->unit_of_measurement);
        $base = $this->units->baseUnitOf($material);
        if (! $base) {
            throw ValidationException::withMessages(['factor' => "'{$material->material_name}' has no stock unit in the Material Catalogue. Set its base unit first."]);
        }
        if (! $required) {
            throw ValidationException::withMessages(['save_to_material' => "'{$line->unit_of_measurement}' is not a registered unit, so it cannot be kept on the material. Use the reading for this requirement only, or register the unit first."]);
        }
        if ((float) $factor <= 0) {
            throw ValidationException::withMessages(['factor' => 'Enter how many stock units one requirement unit holds — a number above zero.']);
        }
        if ((int) $required->id === (int) $base->id) {
            return;
        }

        MaterialUomConversion::query()->firstOrCreate(
            ['material_id' => $material->id, 'from_uom_id' => $required->id, 'to_uom_id' => $base->id],
            ['factor' => $factor],
        );
        $material->load('uomConversions');
    }

    /**
     * Give an already-linked line the reading of its unit that it lacks. This
     * is the only way out for a line whose BOM unit the catalogue cannot read:
     * it stays unissuable until it has one.
     *
     * @return array{line: ElementMaterial, unit: array}
     */
    public function setUnitReading(ElementMaterial $line, string $factor, bool $saveToMaterial, int $userId): array
    {
        $result = DB::transaction(function () use ($line, $factor, $saveToMaterial, $userId) {
            $locked = ElementMaterial::with('element.taskMaterialsData.task')->lockForUpdate()->findOrFail($line->id);
            $this->assertOpenForStores($locked);
            if (! $locked->library_material_id) {
                throw ValidationException::withMessages(['factor' => 'Resolve this line to a Material Library item first.']);
            }

            $material = LibraryMaterial::with(['uomConversions', 'baseUom'])->findOrFail($locked->library_material_id);
            $current = $this->units->assess($locked, $material);
            if ($current['status'] === 'compatible') {
                throw ValidationException::withMessages(['factor' => "This requirement already reads in {$current['base_unit']} (1 {$locked->unit_of_measurement} = ".(float) $current['factor']." {$current['base_unit']})."]);
            }

            $declared = $factor;
            if ($saveToMaterial) {
                $this->learnConversion($locked, $material, $factor);
                $declared = null;
            }
            $snapshot = $this->units->snapshot($locked, $material, $userId, $declared);

            $metadata = $locked->source_metadata ?? [];
            $metadata['unit_readings'] = array_merge($metadata['unit_readings'] ?? [], [$snapshot['requirement_unit_basis']]);
            $locked->forceFill(array_merge($snapshot, ['source_metadata' => $metadata]))->save();

            $materialsData = $locked->element?->taskMaterialsData;
            $materialsData?->touch();

            return [
                'line' => $locked->fresh(['libraryMaterial']),
                'unit' => $this->units->assess($locked->fresh(), $material),
                'task_id' => $materialsData?->enquiry_task_id ? (int) $materialsData->enquiry_task_id : null,
            ];
        });

        $this->announce([$result['task_id']]);

        return $result;
    }

    /**
     * Link the other open lines asking for the same thing. Each is linked on
     * its own lock through link(), so one refusal does not undo the rest, and
     * a per-line reading is never copied: a line whose unit the catalogue
     * cannot read is reported, not guessed.
     *
     * @return array{linked: array<int, int>, skipped: array<int, array>, task_ids: array<int, int>}
     */
    private function resolveMatching(ElementMaterial $source, LibraryMaterial $material, int $userId): array
    {
        $linked = [];
        $skipped = [];
        $taskIds = [];
        foreach ($this->matchingLines($source)->get() as $sibling) {
            try {
                $result = $this->link($sibling, (int) $material->id, $userId, "Linked together with line #{$source->id}.", null, false);
                $linked[] = (int) $sibling->id;
                $taskIds[] = $result['task_id'];
            } catch (ValidationException $exception) {
                $skipped[] = [
                    'id' => (int) $sibling->id,
                    'unit' => $sibling->unit_of_measurement,
                    'reason' => collect($exception->errors())->flatten()->first(),
                ];
            }
        }

        return ['linked' => $linked, 'skipped' => $skipped, 'task_ids' => array_values(array_filter($taskIds))];
    }

    /**
     * Open lines elsewhere with the same description: unlinked, unclassified,
     * included, on a signed-off list of a job that may still be resolved.
     */
    public function matchingLines(ElementMaterial $source, int $limit = 200): \Illuminate\Database\Eloquent\Builder
    {
        $key = mb_strtolower(trim((string) $source->description));
        $eligible = app(ProjectMaterialWorkflowEligibility::class)
            ->eligibleEnquiryIds(ProjectMaterialWorkflowEligibility::CAP_RESOLUTION)->all();

        return ElementMaterial::query()
            ->whereKeyNot($source->id)
            ->whereNull('library_material_id')
            ->whereNull('requirement_disposition')
            ->where('is_included', true)
            ->whereRaw('LOWER(TRIM(description)) = ?', [$key])
            ->whereHas('element', fn ($element) => $element->withoutTrashed()
                ->whereHas('taskMaterialsData', fn ($data) => $data
                    ->where('project_info->approval_status->all_approved', true)
                    ->whereHas('task', fn ($task) => $task->whereIn('project_enquiry_id', $eligible ?: [0]))))
            ->orderBy('id')
            ->limit($limit);
    }

    /**
     * What the resolver should see first: items this description was linked
     * to before, and how many other open lines ask for the same thing.
     */
    public function context(ElementMaterial $line): array
    {
        $key = mb_strtolower(trim((string) $line->description));
        $history = ElementMaterial::query()
            ->whereNotNull('library_material_id')
            ->whereRaw('LOWER(TRIM(description)) = ?', [$key])
            ->selectRaw('library_material_id, COUNT(*) AS times')
            ->groupBy('library_material_id')
            ->orderByDesc('times')
            ->limit(5)
            ->pluck('times', 'library_material_id');

        $compatibility = $history->isEmpty() ? [] : $this->compatibility($line, $history->keys()->all());
        $suggestions = LibraryMaterial::query()->whereIn('id', $history->keys())->get()
            ->map(fn (LibraryMaterial $material) => [
                'id' => (int) $material->id,
                'material_name' => $material->material_name,
                'material_code' => $material->material_code,
                'item_status' => $material->item_status,
                'times' => (int) $history[$material->id],
                'compatibility' => $compatibility[$material->id] ?? null,
            ])
            ->sortByDesc('times')->values()->all();

        $matching = $this->matchingLines($line)->with('element.taskMaterialsData.task')->get();
        $enquiryIds = $matching->map(fn (ElementMaterial $row) => $row->element?->taskMaterialsData?->task?->project_enquiry_id)->filter()->unique();
        $required = $this->units->normalise($line->unit_of_measurement);

        return [
            'requirement' => [
                'unit_text' => $line->unit_of_measurement,
                'unit' => $required?->code,
                'unit_id' => $required?->id,
                'recognised' => (bool) $required,
            ],
            'suggestions' => $suggestions,
            'matching' => [
                'count' => $matching->count(),
                'same_unit' => $matching->filter(fn (ElementMaterial $row) => $required
                    ? $this->units->normalise($row->unit_of_measurement)?->id === $required->id
                    : mb_strtolower(trim((string) $row->unit_of_measurement)) === mb_strtolower(trim((string) $line->unit_of_measurement)))->count(),
                'jobs' => ProjectEnquiry::query()->whereIn('id', $enquiryIds)->limit(8)->pluck('job_number')->filter()->values()->all(),
                'job_count' => $enquiryIds->count(),
            ],
        ];
    }

    /**
     * Link every line that was waiting on this item, now that it is Active,
     * with the reading each resolver gave when they chose it.
     *
     * @return array{linked: array<int, int>, skipped: array<int, array>}
     */
    public function linkAwaiting(LibraryMaterial $material, int $userId): array
    {
        $linked = [];
        $skipped = [];
        $taskIds = [];
        $waiting = ElementMaterial::query()
            ->where('requested_material_id', $material->id)
            ->where('requirement_disposition', ElementMaterial::DISPOSITION_AWAITING_REGISTRATION)
            ->whereNull('library_material_id')
            ->get();

        foreach ($waiting as $line) {
            $factor = data_get($line->source_metadata, 'material_request.factor');
            try {
                // Report 79C: a line on a list not yet signed off was parked by
                // its author in the BOM; it links under the Projects identity
                // rules. A signed-off list links through Resolve's checks.
                $line->loadMissing('element.taskMaterialsData');
                if (! (bool) data_get($line->element?->taskMaterialsData?->project_info, 'approval_status.all_approved', false)) {
                    $taskIds[] = $this->linkDraft($line, $material, $userId);
                    $linked[] = (int) $line->id;
                    continue;
                }
                $result = $this->link($line, (int) $material->id, $userId, 'Linked automatically when the requested material was activated.', $factor !== null ? (string) $factor : null, false);
                $linked[] = (int) $line->id;
                $taskIds[] = $result['task_id'];
            } catch (ValidationException $exception) {
                // Left waiting; the desk still offers Resolve with the reason.
                $skipped[] = ['id' => (int) $line->id, 'reason' => collect($exception->errors())->flatten()->first()];
            }
        }

        $this->announce($taskIds);

        return ['linked' => $linked, 'skipped' => $skipped];
    }

    /**
     * Report 79C: link a line parked on a draft list, now that its requested
     * material is Active. Identity rules only (MaterialLineIdentity); the unit
     * reading is taken later, when Stores resolves or issues it, as for any
     * line the BOM author links directly.
     */
    private function linkDraft(ElementMaterial $line, LibraryMaterial $material, int $userId): ?int
    {
        return DB::transaction(function () use ($line, $material, $userId) {
            $locked = ElementMaterial::with('element.taskMaterialsData')->lockForUpdate()->findOrFail($line->id);
            if ($locked->library_material_id || (int) $locked->requested_material_id !== (int) $material->id
                || $locked->requirement_disposition !== ElementMaterial::DISPOSITION_AWAITING_REGISTRATION) {
                throw ValidationException::withMessages(['material_id' => 'This line is no longer waiting on this material.']);
            }
            $this->identity->assertCanBecome($locked, (int) $material->id, 'material_id');

            $metadata = $locked->source_metadata ?? [];
            $metadata['catalogue_resolutions'] = array_merge($metadata['catalogue_resolutions'] ?? [], [[
                'library_material_id' => (int) $material->id,
                'material_name' => $material->material_name,
                'material_code' => $material->material_code,
                'resolved_by' => $userId,
                'resolved_at' => now()->toIso8601String(),
                'note' => 'Linked automatically when the requested material was activated.',
            ]]);
            $metadata['material_request'] = array_merge($metadata['material_request'] ?? [], [
                'outcome' => ['status' => 'Active', 'at' => now()->toIso8601String()],
            ]);
            $locked->forceFill([
                'library_material_id' => $material->id,
                'requirement_disposition' => null,
                'source_metadata' => $metadata,
            ])->save();
            $data = $locked->element?->taskMaterialsData;
            $data?->touch();

            return $data?->enquiry_task_id ? (int) $data->enquiry_task_id : null;
        });
    }

    /**
     * Report 79C: the custodian did not approve a requested material (set it
     * Inactive, Discontinued or Blocked, or deleted it). Lines waiting on it
     * keep their requirement and return to "choose a material", with the
     * outcome recorded so every screen can say why.
     *
     * @return array<int, int> ids of the lines released
     */
    public function releaseRejected(LibraryMaterial $material, string $status): array
    {
        $released = [];
        $taskIds = [];
        DB::transaction(function () use ($material, $status, &$released, &$taskIds) {
            ElementMaterial::query()
                ->with('element.taskMaterialsData')
                ->where('requested_material_id', $material->id)
                ->where('requirement_disposition', ElementMaterial::DISPOSITION_AWAITING_REGISTRATION)
                ->whereNull('library_material_id')
                ->lockForUpdate()
                ->get()
                ->each(function (ElementMaterial $line) use ($status, &$released, &$taskIds) {
                    $metadata = $line->source_metadata ?? [];
                    $metadata['material_request'] = array_merge($metadata['material_request'] ?? [], [
                        'outcome' => ['status' => $status, 'at' => now()->toIso8601String()],
                    ]);
                    $line->forceFill(['requirement_disposition' => null, 'source_metadata' => $metadata])->save();
                    $line->element?->taskMaterialsData?->touch();
                    $released[] = (int) $line->id;
                    $taskIds[] = $line->element?->taskMaterialsData?->enquiry_task_id;
                });
        });
        $this->announce(array_filter($taskIds));

        return $released;
    }

    /** Budget, procurement and planned cost carry line identity; refresh each list once. */
    private function announce(array $taskIds): void
    {
        foreach (array_unique(array_filter($taskIds)) as $taskId) {
            MaterialsListChanged::dispatch((int) $taskId);
        }
    }

    /**
     * Ask the Material Library custodian for a new item. Creates an Under Review
     * library item and parks the line on it; nothing is linked or issuable yet.
     *
     * @param  array<string, mixed>  $data  registration fields (QuickCreateMaterialFields)
     * @param  array<int, int>  $acknowledged  candidate ids the requester has reviewed
     * @return array{line: ElementMaterial, material: LibraryMaterial}
     */
    public function requestNewMaterial(ElementMaterial $line, array $data, array $acknowledged, string $reason, int $userId): array
    {
        // Report 79C: the duplicate check is the registration service's own
        // gate now (the same one every creation path passes), run after the
        // category's defaults so the stock unit counts, and under a lock so two
        // people asking for the same new material get one.
        return DB::transaction(function () use ($line, $data, $acknowledged, $reason, $userId) {
            $locked = ElementMaterial::with('element.taskMaterialsData.task')->lockForUpdate()->findOrFail($line->id);
            $this->assertOpenForStores($locked);
            if ($locked->library_material_id) {
                throw ValidationException::withMessages(['new_material' => 'This line is already linked to a Material Library item.']);
            }
            if ($locked->requirement_disposition === ElementMaterial::DISPOSITION_NON_STOCK) {
                throw ValidationException::withMessages(['new_material' => 'This line is classified as a non-stock direct purchase; it does not need an inventory material.']);
            }
            if ($locked->requirement_disposition === ElementMaterial::DISPOSITION_AWAITING_REGISTRATION && $locked->requested_material_id) {
                throw ValidationException::withMessages(['new_material' => 'A new material has already been requested for this line and is waiting for the Material Library custodian.']);
            }

            // Born Under Review whatever its completeness: only the custodian's
            // activation (materials_library.manage) makes it usable.
            $material = app(MaterialRegistrationService::class)->create(
                array_merge($data, ['item_status' => 'Under Review']),
                $userId,
                ['acknowledged' => $acknowledged, 'reason' => $reason, 'field' => 'new_material.material_name'],
            );
            $material->load(['uomConversions', 'baseUom']);

            // The proposed item must be able to carry this requirement's unit.
            $unit = $this->units->assess($locked, $material);
            if ($unit['status'] !== 'compatible') {
                throw ValidationException::withMessages(['new_material.base_uom_id' => $unit['reason']]);
            }

            $metadata = $locked->source_metadata ?? [];
            $metadata['material_request'] = [
                'requested_material_id' => (int) $material->id,
                'requested_by' => $userId,
                'requested_at' => now()->toIso8601String(),
                'reason' => $reason,
                'original' => ['description' => $locked->description, 'quantity' => (string) $locked->getRawOriginal('quantity'), 'unit' => $locked->unit_of_measurement],
                'project' => $this->projectReference($locked),
                'candidates_reviewed' => $material->registration_review['candidates_reviewed'] ?? [],
            ];
            $locked->forceFill([
                'requirement_disposition' => ElementMaterial::DISPOSITION_AWAITING_REGISTRATION,
                'requested_material_id' => $material->id,
                'source_metadata' => $metadata,
            ])->save();

            return ['line' => $locked->fresh(['requestedMaterial']), 'material' => $material];
        });
    }

    /** Classify an unlinked line as a non-stock direct purchase, or return it to awaiting resolution. */
    public function classify(ElementMaterial $line, string $disposition, string $reason, int $userId): ElementMaterial
    {
        return DB::transaction(function () use ($line, $disposition, $reason, $userId) {
            $locked = ElementMaterial::with('element.taskMaterialsData.task')->lockForUpdate()->findOrFail($line->id);
            $this->assertOpenForStores($locked);
            if ($locked->library_material_id) {
                throw ValidationException::withMessages(['disposition' => 'This line is linked to a Material Library item; it is an inventory requirement.']);
            }
            if ($disposition === ElementMaterial::DISPOSITION_NON_STOCK) {
                $this->identity->assertCanBecome($locked, null, 'disposition');
            }

            $previous = $locked->effectiveDisposition();
            $metadata = $locked->source_metadata ?? [];
            $metadata['disposition_history'] = array_merge($metadata['disposition_history'] ?? [], [[
                'from' => $previous, 'to' => $disposition, 'reason' => $reason,
                'by' => $userId, 'at' => now()->toIso8601String(),
            ]]);

            $locked->forceFill([
                'requirement_disposition' => $disposition === ElementMaterial::DISPOSITION_NON_STOCK ? ElementMaterial::DISPOSITION_NON_STOCK : null,
                // Returning a line to resolution withdraws any parked request;
                // the Under Review item itself stays for the custodian to decide.
                'requested_material_id' => $disposition === ElementMaterial::DISPOSITION_NON_STOCK ? $locked->requested_material_id : null,
                'source_metadata' => $metadata,
            ])->save();

            return $locked->fresh();
        });
    }

    /** @return array<int, array> compatibility per candidate material id */
    public function compatibility(ElementMaterial $line, array $materialIds): array
    {
        return LibraryMaterial::with(['uomConversions', 'baseUom'])
            ->whereIn('id', array_slice(array_map('intval', $materialIds), 0, 60))
            ->get()
            ->mapWithKeys(fn (LibraryMaterial $material) => [$material->id => array_merge(
                $this->units->assess($line->replicate()->forceFill(RequirementUnits::clearedSnapshot()), $material),
                ['item_status' => $material->item_status],
            )])
            ->all();
    }

    /** Refuse anything Stores-side on a line that is excluded, unapproved or on a closed job. */
    public function assertOpenForStores(ElementMaterial $line): void
    {
        if (! $line->is_included) {
            throw ValidationException::withMessages(['project_material_id' => 'This line is excluded from the material list, so there is nothing to resolve.']);
        }
        $materialsData = $line->element?->taskMaterialsData;
        if (! (bool) data_get($materialsData?->project_info, 'approval_status.all_approved', false)) {
            throw ValidationException::withMessages([
                'project_material_id' => 'Project Officer and Production must sign off the material list before Stores can issue it.',
            ]);
        }
        $enquiryId = $materialsData?->task?->project_enquiry_id;
        // Report 78C: the shared lifecycle rule (enquiry status is the authority;
        // the stale projects.status mirror is no longer read). Resolution is
        // planning work, so active, pre-start and on-hold jobs may resolve.
        app(ProjectMaterialWorkflowEligibility::class)->assertAllows(
            $enquiryId ? (int) $enquiryId : null,
            ProjectMaterialWorkflowEligibility::CAP_RESOLUTION,
            null,
            'project_material_id',
        );
        $retirements = app(MaterialRequirementRetirements::class);
        if ((float) ($retirements->retiredBomByLine([$line->id])[$line->id] ?? 0) >= (float) $line->quantity - 0.00001) {
            throw ValidationException::withMessages(['project_material_id' => 'This requirement was recorded as fulfilled outside the ERP. Revoke that retirement before changing it from Stores.']);
        }
    }

    /**
     * Point open, not-yet-ordered requisition lines for this requirement at the
     * resolved material. Nothing is created; ordered, received or billed lines
     * are left exactly as posted. A line is only relinked when the requirement
     * reads 1:1 in the material's stock unit — otherwise its quantity would be
     * misread, so it is reported for the buyer instead.
     *
     * @return array{relinked: array<int, int>, needs_review: array<int, int>}
     */
    private function reconcileRequisitions(ElementMaterial $line, LibraryMaterial $material, string $factor): array
    {
        $enquiryId = $line->element?->taskMaterialsData?->task?->project_enquiry_id;
        $items = RequisitionItem::query()
            ->where('budget_item_persistent_id', $line->persistent_id)
            ->whereNull('material_id')
            ->when($enquiryId, fn ($query) => $query->where(fn ($q) => $q->whereNull('project_enquiry_id')->orWhere('project_enquiry_id', $enquiryId)))
            ->whereHas('requisition', fn ($query) => $query->whereIn('status', self::OPEN_REQUISITION_STATUSES))
            ->whereDoesntHave('purchaseOrderItems')
            ->lockForUpdate()
            ->get();

        $relinked = [];
        $review = [];
        foreach ($items as $item) {
            if (bccomp($factor, '1', 6) === 0) {
                $item->update(['material_id' => $material->id]);
                $relinked[] = (int) $item->id;
            } else {
                $review[] = (int) $item->id;
            }
        }

        return ['relinked' => $relinked, 'needs_review' => $review];
    }

    private function projectReference(ElementMaterial $line): array
    {
        $enquiryId = $line->element?->taskMaterialsData?->task?->project_enquiry_id;
        $enquiry = $enquiryId ? ProjectEnquiry::find($enquiryId, ['id', 'job_number', 'title']) : null;

        return ['enquiry_id' => $enquiryId, 'job_number' => $enquiry?->job_number, 'title' => $enquiry?->title, 'element' => $line->element?->name];
    }
}
