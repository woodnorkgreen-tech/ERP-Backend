<?php

namespace App\Modules\MaterialsLibrary\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\Workstation;
use App\Modules\MaterialsLibrary\Support\MaterialCompleteness;
use App\Modules\MaterialsLibrary\Support\MaterialFieldSync;

/**
 * The one place a catalogue row gets created from already-validated data.
 *
 * Used by the Materials Library registration form and, since Sept 2026, by
 * Stores' "receive stock" flow when the person receiving a delivery cannot
 * find the item and needs to name it on the spot. Both callers get exactly
 * the same defaults, the same code generation and the same Active/Under
 * Review decision — a material's governance rules must never have two
 * implementations to fall out of step (see MaterialCompleteness).
 */
class MaterialRegistrationService
{
    public function __construct(private readonly MaterialDefaultsService $defaults)
    {
    }

    /**
     * @param  array<string, mixed>  $data  Already validated (StoreMaterialRequest
     *                                       shape, or the narrower subset a quick
     *                                       create supplies: material_name +
     *                                       material_category_id + attributes).
     */
    public function create(array $data, int $userId): LibraryMaterial
    {
        $conversions = $data['uom_conversions'] ?? [];
        unset($data['uom_conversions']);
        $data['created_by'] = $userId;
        $data['updated_by'] = $userId;

        // Let the taxonomy answer what it can before anything is asked of the
        // typist. Only gaps are filled; a supplied value always wins.
        $data = $this->defaults->apply($data);

        $categoryId = $data['material_category_id'] ?? null;
        $category = $categoryId
            ? MaterialCategory::with('parent')->find($categoryId)
            : null;

        if (blank($data['material_code'] ?? null)) {
            if ($category) {
                $workstationCode = ! empty($data['workstation_id'])
                    ? Workstation::whereKey($data['workstation_id'])->value('code')
                    : null;
                $data['material_code'] = $this->defaults->suggestCode($category, $workstationCode);
            } else {
                // The code column is unique and not nullable, so a draft without
                // a category still needs an identity.
                $data['material_code'] = $this->defaults->suggestDraftCode();
            }
        }

        $data = MaterialFieldSync::syncControlCompatibility($data);
        $data = MaterialFieldSync::syncUomCompatibility($data);

        // Wrap attributes in 'attributes' key for JSON column if not already
        if (isset($data['attributes']) && !isset($data['attributes']['attributes'])) {
            $data['attributes'] = ['attributes' => $data['attributes']];
        }

        $data = MaterialFieldSync::syncCategoryStrings($data);

        $requestedStatus = $data['item_status'] ?? null;
        $material = new LibraryMaterial($data);
        $material->setRelation('materialCategory', $category);

        // Anything short of the full governance set is born under review:
        // searchable and editable, but refused by checkIn() and adjustStock().
        $material->item_status = MaterialCompleteness::resolveStatus($material, $requestedStatus);
        $material->is_active = $material->item_status === 'Active';
        $material->save();

        MaterialFieldSync::syncUomConversions($material, $conversions);

        return $material;
    }
}
