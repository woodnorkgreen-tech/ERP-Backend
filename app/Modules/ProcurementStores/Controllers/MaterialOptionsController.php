<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\CostCollector\Services\MaterialExpenseCodeResolver;
use App\Modules\MaterialsLibrary\Services\MaterialPurchaseOptions;
use Illuminate\Http\Request;

/*
 * The catalogue lookup behind every "what do you want to buy" picker.
 *
 * Raising a requisition needs nothing more than being a signed-in, active
 * member of staff — that is the point of it, anyone may ask to buy something.
 * Reading the material library, though, is gated on materials_library.view,
 * which only Procurement, Stores, Production and Managers hold. So everyone
 * else opened the picker, got a silent 403 on every keystroke, and could only
 * type a free-text description — quietly turning governed catalogue purchases
 * into ungoverned one-offs.
 *
 * Choosing from a catalogue is not the same act as browsing or managing it, so
 * this exposes the narrow read a picker needs, under the same authorisation as
 * the requisition it feeds, rather than widening the library's own boundary.
 */
class MaterialOptionsController extends Controller
{
    private const LIMIT = 20;

    public function index(
        Request $request,
        MaterialPurchaseOptions $purchaseOptions,
        MaterialExpenseCodeResolver $expenseCodes,
    ) {
        $search = trim((string) $request->input('search', ''));

        // Whether the line being filled sits on a job. The suggestion is only
        // useful if it is a code the requisition may actually carry — see
        // suggestedCode(). Absent means "not stated", and nothing is filtered.
        $jobContext = $request->has('job_context') ? $request->boolean('job_context') : null;

        // A picker with nothing typed asks for nothing: an unfiltered catalogue
        // read is a browse, and this endpoint is not the place to do one.
        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $materials = LibraryMaterial::query()
            // governed(), not active(): item_status is the authoritative flag,
            // and the legacy is_active scope still offers a retired material
            // whose old boolean was never cleared.
            ->governed()
            ->with(['stock', 'baseUom', 'purchaseUom', 'uomConversions', 'materialCategory.parent'])
            ->search($search)
            ->orderBy('material_name')
            ->limit(self::LIMIT)
            ->get();

        // Resolved once per distinct category rather than per row: the catalogue
        // maps categories, not materials, so a page of boards would otherwise ask
        // the same question twenty times.
        $suggestions = [];

        return response()->json([
            'data' => $materials->map(function (LibraryMaterial $material) use ($purchaseOptions, $expenseCodes, &$suggestions, $jobContext) {
                $onHand = (float) ($material->stock?->quantity_on_hand ?? 0);
                $reserved = (float) ($material->stock?->quantity_reserved ?? 0);

                return array_merge([
                    'id' => $material->id,
                    'material_code' => $material->material_code,
                    'material_name' => $material->material_name,
                    'category' => $material->category,
                    'unit_cost' => (float) $material->unit_cost,
                    'base_uom_id' => $material->base_uom_id,
                    'base_uom' => $material->baseUom?->code,
                    'purchase_uom' => $material->purchaseUom
                        ? ['id' => $material->purchaseUom->id, 'code' => $material->purchaseUom->code, 'name' => $material->purchaseUom->name]
                        : null,
                    // What is already on the shelf. Shown while choosing, which
                    // is the only moment it can stop the purchase.
                    'quantity_on_hand' => $onHand,
                    'available' => max(0, $onHand - $reserved),

                    // What Finance would classify this material as, resolved by
                    // the same rule a Stores issue goes through. Sent so a
                    // requisition line can fill its expense type from the
                    // catalogue instead of asking a requester to pick one, and so
                    // the accrual raised on receipt and the issue that eventually
                    // consumes it agree by construction rather than by discipline.
                    //
                    // Sent as the code's identity rather than a bare id: the line
                    // has to render what it was filled with, and an id renders as
                    // nothing.
                    'suggested_expense_code' => $this->suggestedCode($material, $expenseCodes, $suggestions, $jobContext),
                ], $purchaseOptions->forMaterial($material));
            }),
        ]);
    }

    /**
     * The code this material would be classified under, or null.
     *
     * The resolver answers in codes because that is what the cost ledger stores;
     * a picker needs the id its field binds to and the words to show for it.
     * Memoised on the resolved code so a page of one category costs one lookup.
     *
     * @param  array<string, ?ExpenseCode>  $memo
     * @return array{id:int,code:string,expense_type:string,job_id_rule:string}|null
     */
    private function suggestedCode(
        LibraryMaterial $material,
        MaterialExpenseCodeResolver $expenseCodes,
        array &$memo,
        ?bool $jobContext,
    ): ?array {
        $code = $expenseCodes->resolve($material, allowFallback: false);

        if (! $code) {
            return null;
        }

        $suggestion = $memo[$code] ??= ExpenseCode::active()->where('code', $code)
            ->first(['id', 'code', 'expense_type', 'expense_family', 'job_id_rule', 'is_procurable']);

        if (! $suggestion) {
            return null;
        }

        // This endpoint only ever fills a requisition line, so a code no purchase
        // order can carry is never the right answer here.
        if (! $suggestion->is_procurable) {
            return null;
        }

        // A suggestion the requisition cannot legally carry is worse than none.
        //
        // The resolver answers from the material alone and falls back to a
        // Direct-materials code, every one of which requires a job number. On an
        // office requisition that filled each line with a code the picker does
        // not offer: the select found no matching option and rendered blank,
        // while the payload still carried the code — invisible until approval
        // rejected it, or until the collector could not post the receipt.
        if ($jobContext === true && $suggestion->job_id_rule === ExpenseCode::JOB_NOT_ALLOWED) {
            return null;
        }

        if ($jobContext === false && $suggestion->job_id_rule === ExpenseCode::JOB_REQUIRED) {
            return null;
        }

        return [
            'id' => $suggestion->id,
            'code' => $suggestion->code,
            'expense_type' => $suggestion->expense_type,
            'expense_family' => $suggestion->expense_family,
            'job_id_rule' => $suggestion->job_id_rule,
        ];
    }
}
