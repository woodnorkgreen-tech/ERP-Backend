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
    /**
     * The category assigned when nobody names one, so a material can still
     * reach Active without the taxonomy — see the migration that creates it.
     */
    private const UNCATEGORIZED_CODE = 'UNCAT';

    private bool $uncategorizedIdResolved = false;
    private ?int $uncategorizedId = null;

    public function __construct(
        private readonly MaterialDefaultsService $defaults,
        private readonly ?MaterialDuplicateGuard $duplicates = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  Already validated (StoreMaterialRequest
     *                                       shape, or the narrower subset a quick
     *                                       create supplies: material_name +
     *                                       material_category_id + attributes).
     * @param  array{acknowledged?: array<int,int>, reason?: ?string, automatic_reason?: ?string}  $review
     *         Report 79C: the duplicate review the person did. Every creation
     *         passes the same gate; an exact duplicate is always refused, a
     *         similar one needs each candidate acknowledged and a reason.
     */
    public function create(array $data, int $userId, array $review = []): LibraryMaterial
    {
        $guard = $this->duplicates ?? app(MaterialDuplicateGuard::class);

        // Checked and created while no other request can register the same
        // identity, so two people naming the same new item at once get one.
        return $guard->withLock((string) ($data['material_name'] ?? ''), fn () => $this->register($data, $userId, $review, $guard));
    }

    private function register(array $data, int $userId, array $review, MaterialDuplicateGuard $guard): LibraryMaterial
    {
        unset($data['duplicate_review']);
        $conversions = $data['uom_conversions'] ?? [];
        unset($data['uom_conversions']);
        $data['created_by'] = $userId;
        $data['updated_by'] = $userId;

        // A name is the only thing creation truly demands. Category is where
        // item type, stock unit, disposition and tracking mode all come from
        // (MaterialDefaultsService), so leaving it blank would otherwise mean
        // asking for all four by hand instead of one search box. Filing it
        // under a placeholder keeps the material re-classifiable — the bulk
        // repair and merge tools already move rows off a category later —
        // while letting it reach Active today.
        if (blank($data['material_category_id'] ?? null)) {
            $data['material_category_id'] = $this->uncategorizedCategoryId();
        }

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

        if (($data['tracking_mode'] ?? '') === 'serialized_item') $data['is_serialized'] = true;
        $data = MaterialFieldSync::syncControlCompatibility($data);
        $data = MaterialFieldSync::syncUomCompatibility($data);

        // Wrap attributes in 'attributes' key for JSON column if not already
        if (isset($data['attributes']) && !isset($data['attributes']['attributes'])) {
            $data['attributes'] = ['attributes' => $data['attributes']];
        }

        $data = MaterialFieldSync::syncCategoryStrings($data);

        // After the defaults: the stock unit the category gives is part of
        // what identifies the material (Masking Tape 24mm in pcs vs rolls).
        $registrationReview = $guard->assertCanRegister($data, $review);

        $requestedStatus = $data['item_status'] ?? null;
        $material = new LibraryMaterial($data);
        $material->registration_review = $registrationReview;
        $material->setRelation('materialCategory', $category);

        // Anything short of the full governance set is born under review:
        // searchable and editable, but refused by checkIn() and adjustStock().
        $material->item_status = MaterialCompleteness::resolveStatus($material, $requestedStatus);
        $material->is_active = $material->item_status === 'Active';
        $material->save();

        MaterialFieldSync::syncUomConversions($material, $conversions);

        return $material;
    }

    /**
     * Memoized per instance so a bulk-create loop (one $registration shared
     * across every variant) pays this lookup once, not once per row. Null
     * when the migration hasn't run yet — the material simply falls back to
     * the old draft-without-category behaviour rather than failing to save.
     */
    private function uncategorizedCategoryId(): ?int
    {
        if (! $this->uncategorizedIdResolved) {
            $this->uncategorizedId = MaterialCategory::where('code', self::UNCATEGORIZED_CODE)->value('id');
            $this->uncategorizedIdResolved = true;
        }

        return $this->uncategorizedId;
    }
}
