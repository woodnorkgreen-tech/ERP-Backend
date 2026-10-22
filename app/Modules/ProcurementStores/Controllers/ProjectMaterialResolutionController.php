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
            'new_material.uom_conversions' => 'nullable|array|max:2',
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
                'message' => "Requested \"{$result['material']->material_name}\". It is Under Review until the Material Library custodian activates it; the line stays unlinked until then.",
                'data' => ['project_material' => $result['line'], 'material' => $result['material'], 'outcome' => 'awaiting_registration'],
            ]);
        }

        $result = $this->resolution->resolve($elementMaterial, (int) $validated['material_id'], (int) auth()->id(), $validated['note'] ?? null);

        $message = "Linked to \"{$result['material']->material_name}\". Nothing was received, issued, ordered or charged.";
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
                'outcome' => 'resolved',
            ],
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
        $validated = $request->validate(['name' => 'required|string|max:255', 'category_id' => 'nullable|integer']);

        return response()->json(['data' => $finder->candidates($validated['name'], $validated['category_id'] ?? null)]);
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
