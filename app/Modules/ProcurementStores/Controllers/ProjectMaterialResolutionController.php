<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Models\ElementMaterial;
use App\Modules\MaterialsLibrary\Services\MaterialDuplicateFinder;
use App\Modules\MaterialsLibrary\Support\MaterialControl;
use App\Modules\ProcurementStores\Services\ProjectMaterialResolution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Report 78B — the Project Material Desk's Resolve Material actions.
 *
 * Every action is identity or classification only. Stock is received through
 * Receive Stock; new items are activated by the Material Library custodian.
 */
class ProjectMaterialResolutionController extends Controller
{
    public function __construct(private readonly ProjectMaterialResolution $resolution) {}

    private function denied(): ?JsonResponse
    {
        return auth()->user()?->can(Permissions::STORES_MANAGE)
            ? null
            : response()->json(['message' => 'Only Stores team members can resolve a material to the catalogue.'], 403);
    }

    /** Match an existing Active item, or request a new one. */
    public function resolve(Request $request, ElementMaterial $elementMaterial): JsonResponse
    {
        if ($denied = $this->denied()) {
            return $denied;
        }

        $validated = $request->validate([
            'material_id' => 'nullable|required_without:new_material|prohibits:new_material|integer|exists:library_materials,id',
            'note' => 'nullable|string|max:500',
            // Stock units in one requirement unit, when the catalogue cannot read it.
            'factor' => 'nullable|numeric|gt:0|max:1000000',
            'save_to_material' => 'nullable|boolean',
            'apply_to_matching' => 'nullable|boolean',
            // Stock is never taken in here (Report 78B Phase 3).
            'receive' => 'prohibited',
            'new_material' => 'nullable|array|required_without:material_id',
            'new_material.material_name' => 'required_with:new_material|string|max:255',
            'new_material.material_category_id' => 'required_with:new_material|integer|exists:material_categories,id',
            'new_material.material_code' => 'nullable|string|max:100|unique:library_materials,material_code',
            'new_material.attributes' => 'nullable|array',
            'new_material.issue_disposition' => ['nullable', Rule::in(MaterialControl::DISPOSITIONS)],
            'new_material.tracking_mode' => ['nullable', Rule::in(MaterialControl::TRACKING_MODES)],
            'new_material.base_uom_id' => 'nullable|integer|exists:units_of_measure,id',
            'new_material.purchase_uom_id' => 'nullable|integer|exists:units_of_measure,id',
            'new_material.issue_uom_id' => 'nullable|integer|exists:units_of_measure,id',
            'new_material.uom_conversions' => 'nullable|array|max:12',
            'new_material.uom_conversions.*.from_uom_id' => 'required|integer|distinct|exists:units_of_measure,id',
            'new_material.uom_conversions.*.factor' => 'required|numeric|gt:0',
            'new_material.default_unit_cost' => 'nullable|numeric|min:0',
            'request_reason' => 'required_with:new_material|nullable|string|min:5|max:500',
            'acknowledged_candidates' => 'nullable|array',
            'acknowledged_candidates.*' => 'integer',
        ], [
            'receive.prohibited' => 'Resolve Material links identity only. Take stock in through Receive Stock — a stock adjustment there needs a Stores reviewer.',
        ]);

        if (! empty($validated['new_material'])) {
            $result = $this->resolution->requestNewMaterial(
                $elementMaterial,
                $validated['new_material'],
                $validated['acknowledged_candidates'] ?? [],
                (string) $validated['request_reason'],
                (int) auth()->id(),
            );

            return response()->json([
                'message' => "Requested \"{$result['material']->material_name}\". It is Under Review until the Material Library custodian activates it; this line links to it automatically then.",
                'data' => ['project_material' => $result['line'], 'material' => $result['material'], 'outcome' => 'awaiting_registration'],
            ]);
        }

        if ($refused = $this->refuseCatalogueWrite($validated)) {
            return $refused;
        }

        $result = $this->resolution->resolve($elementMaterial, (int) $validated['material_id'], (int) auth()->id(), $validated['note'] ?? null, [
            'factor' => isset($validated['factor']) ? (string) $validated['factor'] : null,
            'save_to_material' => (bool) ($validated['save_to_material'] ?? false),
            'apply_to_matching' => (bool) ($validated['apply_to_matching'] ?? false),
        ]);

        if ($result['outcome'] === 'awaiting_registration') {
            return response()->json([
                'message' => "\"{$result['material']->material_name}\" is still Under Review. This line links to it automatically as soon as it is activated.",
                'data' => ['project_material' => $result['line'], 'material' => $result['material'], 'outcome' => 'awaiting_registration'],
            ]);
        }

        $message = "Linked to \"{$result['material']->material_name}\". Nothing was received, issued, ordered or charged.";
        if ($result['matching'] !== null && $result['matching']['linked'] !== []) {
            $message .= ' '.count($result['matching']['linked']).' other line(s) with the same description were linked too.';
        }
        if ($result['matching'] !== null && $result['matching']['skipped'] !== []) {
            $message .= ' '.count($result['matching']['skipped']).' were left for their own resolution (usually a unit that needs its own reading).';
        }
        if ($result['requisitions']['relinked'] !== []) {
            $message .= ' '.count($result['requisitions']['relinked']).' open requisition line(s) now name this material.';
        }
        if ($result['requisitions']['needs_review'] !== []) {
            $message .= ' '.count($result['requisitions']['needs_review']).' open requisition line(s) use a different unit and were left for the buyer to review.';
        }

        return response()->json([
            'message' => $message,
            'data' => [
                'project_material' => $result['line'],
                'material' => $result['material'],
                'unit' => $result['unit'],
                'requisitions' => $result['requisitions'],
                'matching' => $result['matching'] ? ['linked' => $result['matching']['linked'], 'skipped' => $result['matching']['skipped']] : null,
                'outcome' => 'resolved',
            ],
        ]);
    }

    /** Keeping a reading on the material is a catalogue change. */
    private function refuseCatalogueWrite(array $validated): ?JsonResponse
    {
        if (! ($validated['save_to_material'] ?? false)) {
            return null;
        }
        if (empty($validated['factor'])) {
            return response()->json(['message' => 'Give the reading to keep on the material.', 'errors' => ['factor' => ['Enter how many stock units one requirement unit holds.']]], 422);
        }

        return auth()->user()?->can(Permissions::MATERIALS_LIBRARY_MANAGE)
            ? null
            : response()->json(['message' => 'Only the Material Library custodian can keep a conversion on the material. Use it for this requirement only.'], 403);
    }

    /** Previous matches for this description, and other open lines asking for the same thing. */
    public function context(ElementMaterial $elementMaterial): JsonResponse
    {
        if ($denied = $this->denied()) {
            return $denied;
        }

        return response()->json(['data' => array_merge($this->resolution->context($elementMaterial), [
            'can_manage_library' => (bool) auth()->user()?->can(Permissions::MATERIALS_LIBRARY_MANAGE),
        ])]);
    }

    /** Give a linked line whose unit the catalogue cannot read its reading. */
    public function unitReading(Request $request, ElementMaterial $elementMaterial): JsonResponse
    {
        if ($denied = $this->denied()) {
            return $denied;
        }
        $validated = $request->validate([
            'factor' => 'required|numeric|gt:0|max:1000000',
            'save_to_material' => 'nullable|boolean',
        ]);
        if ($refused = $this->refuseCatalogueWrite($validated)) {
            return $refused;
        }

        $result = $this->resolution->setUnitReading($elementMaterial, (string) $validated['factor'], (bool) ($validated['save_to_material'] ?? false), (int) auth()->id());
        $unit = $result['unit'];

        return response()->json([
            'message' => "Reads as 1 {$elementMaterial->unit_of_measurement} = ".(float) $unit['factor']." {$unit['base_unit']}"
                .(($validated['save_to_material'] ?? false) ? ', kept on the material for every job.' : ' for this requirement.')
                .' It can be issued now.',
            'data' => ['project_material' => $result['line'], 'unit' => $unit],
        ]);
    }

    /** How the requirement reads against each candidate material. */
    public function compatibility(Request $request, ElementMaterial $elementMaterial): JsonResponse
    {
        if ($denied = $this->denied()) {
            return $denied;
        }
        $validated = $request->validate(['material_ids' => 'required|array|max:60', 'material_ids.*' => 'integer']);

        return response()->json(['data' => $this->resolution->compatibility($elementMaterial, $validated['material_ids'])]);
    }

    /** Existing items a proposed new material may duplicate. */
    public function registrationCandidates(Request $request, ElementMaterial $elementMaterial, MaterialDuplicateFinder $finder): JsonResponse
    {
        if ($denied = $this->denied()) {
            return $denied;
        }
        $validated = $request->validate([
            'name' => 'required|string|max:255', 'category_id' => 'nullable|integer',
            'base_uom_id' => 'nullable|integer', 'unit' => 'nullable|string|max:50',
        ]);

        // Report 79C: the same verdict the registration gate will give on
        // submit, so the items shown here are exactly the ones to acknowledge.
        $check = app(\App\Modules\MaterialsLibrary\Services\MaterialDuplicateGuard::class)->check([
            'material_name' => $validated['name'],
            'material_category_id' => $validated['category_id'] ?? null,
            'base_uom_id' => $validated['base_uom_id'] ?? null,
            'unit_of_measure' => $validated['unit'] ?? null,
        ]);

        return response()->json(['data' => array_values(array_merge($check['exact'], $check['similar'])), 'verdict' => $check['verdict']]);
    }

    /** Non-stock / direct purchase, or back to awaiting resolution. */
    public function classify(Request $request, ElementMaterial $elementMaterial): JsonResponse
    {
        if ($denied = $this->denied()) {
            return $denied;
        }
        $validated = $request->validate([
            'disposition' => ['required', Rule::in([ElementMaterial::DISPOSITION_NON_STOCK, 'awaiting_resolution'])],
            'reason' => 'required|string|min:5|max:500',
        ]);

        $line = $this->resolution->classify($elementMaterial, $validated['disposition'], $validated['reason'], (int) auth()->id());

        return response()->json([
            'message' => $validated['disposition'] === ElementMaterial::DISPOSITION_NON_STOCK
                ? 'Classified as a non-stock direct purchase. It is bought through a requisition and charged to the job, never held as Stores inventory.'
                : 'Returned to awaiting resolution.',
            'data' => ['project_material' => $line, 'disposition' => $line->effectiveDisposition()],
        ]);
    }
}
