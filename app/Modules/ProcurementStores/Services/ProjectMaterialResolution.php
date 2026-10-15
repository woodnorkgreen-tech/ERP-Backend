<?php

namespace App\Modules\ProcurementStores\Services;

use App\Events\MaterialsListChanged;
use App\Models\ElementMaterial;
use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
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
 * When no item exists, Stores requests one: an Under Review library item that
 * only the Material Library custodian (`materials_library.manage`) can
 * activate. The line waits as "awaiting registration" and is resolved to the
 * item once it is Active — through this same path, with the same checks.
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
     * Link an unlinked requirement to an Active library item.
     *
     * @return array{line: ElementMaterial, material: LibraryMaterial, unit: array, requisitions: array}
     */
    public function resolve(ElementMaterial $line, int $materialId, int $userId, ?string $note = null): array
    {
        $result = DB::transaction(function () use ($line, $materialId, $userId, $note) {
            $locked = ElementMaterial::with('element.taskMaterialsData.task')->lockForUpdate()->findOrFail($line->id);
            $this->assertOpenForStores($locked);

            if ($locked->library_material_id) {
                throw ValidationException::withMessages(['material_id' => 'This line is already linked to a Material Library item.']);
            }
            if ($locked->requirement_disposition === ElementMaterial::DISPOSITION_NON_STOCK) {
                throw ValidationException::withMessages(['material_id' => 'This line is classified as a non-stock direct purchase. Return it to "awaiting resolution" before linking it to an inventory material.']);
            }

            $material = LibraryMaterial::with(['uomConversions', 'baseUom'])->findOrFail($materialId);
            if (($material->item_status ?? 'Active') !== 'Active') {
                throw ValidationException::withMessages([
                    'material_id' => "Only Active Material Library items can be linked. '{$material->material_name}' is {$material->item_status}.",
                ]);
            }

            // A line whose link was lost may still carry issue history; it can
            // only be relinked to the material that was actually issued.
            $this->identity->assertCanBecome($locked, (int) $material->id, 'material_id');

            // Refuses an incompatible unit with the reason, before anything is written.
            $snapshot = $this->units->snapshot($locked, $material, $userId);

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
                'line' => $locked->fresh(['libraryMaterial']),
                'material' => $material,
                'unit' => $this->units->assess($locked->fresh(), $material),
                'requisitions' => $requisitions,
                'task_id' => $materialsData?->enquiry_task_id,
            ];
        });

        // Budget, procurement task and planned cost lines carry the line's
        // library identity; the existing listener chain refreshes them.
        if ($result['task_id']) {
            MaterialsListChanged::dispatch((int) $result['task_id']);
        }

        return $result;
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
        $candidates = $this->duplicates->candidates((string) $data['material_name'], isset($data['material_category_id']) ? (int) $data['material_category_id'] : null);
        $exact = $candidates->where('match', 'exact');
        if ($exact->isNotEmpty()) {
            throw ValidationException::withMessages([
                'new_material.material_name' => "'{$exact->first()['name']}' ({$exact->first()['code']}) already exists in the Material Library. Match it instead of registering a duplicate.",
            ])->status(422);
        }
        $unacknowledged = $candidates->pluck('id')->diff(array_map('intval', $acknowledged));
        if ($unacknowledged->isNotEmpty()) {
            $exception = ValidationException::withMessages([
                'duplicate_candidates' => 'Similar Material Library items exist. Check each one; register a new item only if none of them is this material.',
            ]);
            $exception->response = response()->json([
                'message' => 'Similar Material Library items exist. Check each one before requesting a new material.',
                'code' => 'DUPLICATE_CANDIDATES',
                'candidates' => $candidates->values(),
                'errors' => ['duplicate_candidates' => ['Similar Material Library items exist.']],
            ], 422);
            throw $exception;
        }

        return DB::transaction(function () use ($line, $data, $acknowledged, $reason, $userId, $candidates) {
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
                'candidates_reviewed' => $candidates->map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'match' => $c['match']])->values()->all(),
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
        $enquiryStatus = $enquiryId ? ProjectEnquiry::whereKey($enquiryId)->value('status') : null;
        $projectStatus = $enquiryId ? Project::where('enquiry_id', $enquiryId)->value('status') : null;
        if (in_array($enquiryStatus, ['completed', 'cancelled', 'closed'], true) || in_array($projectStatus, ['completed', 'cancelled', 'closed'], true)) {
            throw ValidationException::withMessages(['project_material_id' => 'This job is '.($projectStatus && in_array($projectStatus, ['completed', 'cancelled', 'closed'], true) ? $projectStatus : $enquiryStatus).'; its material requirements can no longer be changed from Stores.']);
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
