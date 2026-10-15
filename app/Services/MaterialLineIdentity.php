<?php

namespace App\Services;

use App\Models\ElementMaterial;
use App\Modules\ProcurementStores\Models\BoardRequest;
use App\Modules\ProcurementStores\Models\InventoryLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Report 78B — who decides a BOM line's Material Library identity, and when it
 * may change.
 *
 * Two writers touch `element_materials.library_material_id`: the materials list
 * save (Projects) and Resolve Material (Stores). Before this class the list
 * save wrote whatever the browser sent, so a screen opened before Stores
 * resolved a line — or a draft restored from the browser — sent `null` and
 * silently undid the resolution (Report 78 F-01). And a line could change
 * material underneath stock already issued against it (F-02).
 *
 * The rules:
 *  - A client only changes an identity it has seen. Each line is served with
 *    `libraryMaterialIdBaseline`; a save whose baseline differs from the stored
 *    identity is a stale view, and the stored identity stands.
 *  - A client that sends no baseline (older callers, imports) may set an
 *    identity on an unlinked line, but `null` never erases an established one.
 *  - Any real change of an established identity is refused while the line has
 *    open fulfilment of a different material: net issued stock, or an open
 *    board request. The controlled path is to return or reverse that stock
 *    first (existing return and Finance-reversal flows), then relink.
 */
class MaterialLineIdentity
{
    private const ISSUE_TYPES = ['check_out', 'issue', 'consumption'];

    /**
     * The identity a save should leave on this line.
     *
     * @param  array<string, mixed>  $input  one material line as the client sent it
     */
    public function effectiveId(?ElementMaterial $existing, array $input): ?int
    {
        $current = $existing?->library_material_id ? (int) $existing->library_material_id : null;
        if (! array_key_exists('libraryMaterialId', $input)) {
            return $current;
        }

        $requested = filled($input['libraryMaterialId']) ? (int) $input['libraryMaterialId'] : null;
        if ($requested === $current || $current === null) {
            return $requested;
        }

        // An established identity. Only a client that saw it may change it.
        if (! array_key_exists('libraryMaterialIdBaseline', $input)) {
            return $requested ?? $current;
        }
        $baseline = filled($input['libraryMaterialIdBaseline']) ? (int) $input['libraryMaterialIdBaseline'] : null;

        return $baseline === $current ? $requested : $current;
    }

    /** True when a save would change the stored identity. */
    public function changes(?ElementMaterial $existing, array $input): bool
    {
        return $existing !== null
            && $this->effectiveId($existing, $input) !== ($existing->library_material_id ? (int) $existing->library_material_id : null);
    }

    /**
     * Net quantity out per material against this line (issues less reopening
     * returns), plus open board requests.
     *
     * @return array{movements: array<int, float>, open_board_requests: array<int, int>}
     */
    public function openFulfilment(ElementMaterial $line): array
    {
        $issued = InventoryLog::where('project_material_id', $line->id)
            ->whereIn('type', self::ISSUE_TYPES)
            ->groupBy('material_id')
            ->select('material_id', DB::raw('SUM(ABS(quantity)) as qty'))
            ->pluck('qty', 'material_id');
        $returned = InventoryLog::where('project_material_id', $line->id)
            ->fulfilmentReopeningReturns()
            ->groupBy('material_id')
            ->select('material_id', DB::raw('SUM(ABS(quantity)) as qty'))
            ->pluck('qty', 'material_id');

        $movements = [];
        foreach ($issued as $materialId => $quantity) {
            $net = round((float) $quantity - (float) ($returned[$materialId] ?? 0), 6);
            if ($net > 0.000001) {
                $movements[(int) $materialId] = $net;
            }
        }

        $boards = BoardRequest::where('project_material_id', $line->id)
            ->whereIn('status', ['pending', 'partial'])
            ->groupBy('material_id')
            ->select('material_id', DB::raw('COUNT(*) as n'))
            ->pluck('n', 'material_id')
            ->map(fn ($n) => (int) $n)->all();

        return ['movements' => $movements, 'open_board_requests' => $boards];
    }

    /**
     * Refuse removing a line from the list while stock is still out against it.
     * The issue would point at a requirement that no longer exists, and the
     * job would lose the record of what it was given.
     */
    public function assertRemovable(ElementMaterial $line): void
    {
        $open = $this->openFulfilment($line);
        if ($open['movements'] !== [] || $open['open_board_requests'] !== []) {
            throw ValidationException::withMessages(['materials' => "'{$line->description}' cannot be removed: stock has been issued or requested against it. "
                .'Return the stock (or reverse the issue) first, or keep the line and reduce its quantity.']);
        }
    }

    /**
     * Refuse a change of identity to $newMaterialId while stock of another
     * material is still out against the line.
     */
    public function assertCanBecome(ElementMaterial $line, ?int $newMaterialId, string $field = 'library_material_id'): void
    {
        $open = $this->openFulfilment($line);
        $blocking = collect($open['movements'])->keys()
            ->merge(array_keys($open['open_board_requests']))
            ->unique()
            ->reject(fn ($materialId) => $newMaterialId !== null && (int) $materialId === $newMaterialId);

        if ($blocking->isNotEmpty()) {
            throw ValidationException::withMessages([$field => "'{$line->description}' already has stock of another material issued or requested against it. "
                .'Its material cannot change until that stock is returned or the issue is reversed, so the issued quantity stays with the material that was actually issued.']);
        }
    }
}
