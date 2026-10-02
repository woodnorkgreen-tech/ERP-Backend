<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\ConsumableUnit;
use App\Modules\ProcurementStores\Services\ConsumableUnitService;
use Illuminate\Http\Request;

class ConsumableUnitController extends Controller
{
    public function __construct(private readonly ConsumableUnitService $units) {}
    private function allow(Request $request, string $permission): void { abort_unless($request->user()?->can($permission), 403); }
    public function index(Request $request)
    {
        $this->allow($request, Permissions::STORES_VIEW);
        return response()->json(['data' => LibraryMaterial::where('tracking_mode', 'consumable_unit')->orderBy('material_name')->get()->map(fn ($m) => $this->units->summary($m)), 'indicators' => $this->units->indicators()]);
    }
    public function material(Request $request, LibraryMaterial $material)
    {
        $this->allow($request, Permissions::STORES_VIEW);
        abort_unless($material->isConsumableUnit(), 422, 'Material is not configured for consumable-unit tracking.');
        return response()->json(['data' => $this->units->summary($material)]);
    }
    public function show(Request $request, ConsumableUnit $unit)
    {
        $this->allow($request, Permissions::STORES_VIEW);
        return response()->json(['data' => $unit->load(['material', 'supplier', 'parent', 'children', 'counts', 'movements.project', 'movements.actor']), 'readiness' => $this->units->summary($unit->material)['readiness']]);
    }
    public function count(Request $request, ConsumableUnit $unit)
    {
        $this->allow($request, Permissions::STORES_MANAGE);
        $data = $request->validate(['physical_quantity' => 'required|numeric|min:0', 'notes' => 'required|string|min:5|max:4000']);
        return response()->json(['data' => $this->units->count($unit, (string) $data['physical_quantity'], $data['notes'], $request->user()->id)], 201);
    }
    public function hold(Request $request, ConsumableUnit $unit)
    {
        $this->allow($request, Permissions::STORES_REVIEW);
        $data = $request->validate(['hold' => 'required|boolean', 'reason' => 'required|string|min:5|max:4000']);
        return response()->json(['data' => $this->units->hold($unit, $data['hold'], $data['reason'], $request->user()->id)]);
    }
    public function convert(Request $request, LibraryMaterial $material)
    {
        $this->allow($request, Permissions::STORES_ADJUST_QUANTITY);
        $data = $request->validate(['controlled_units' => 'required|array|min:1|max:100', 'controlled_units.*.quantity' => 'required|numeric|gt:0', 'controlled_units.*.opened' => 'nullable|boolean', 'notes' => 'required|string|min:5|max:4000']);
        return response()->json(['data' => $this->units->convert($material, $data['controlled_units'], $data['notes'], $request->user()->id)], 201);
    }
}
