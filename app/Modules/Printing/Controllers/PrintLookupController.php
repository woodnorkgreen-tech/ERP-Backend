<?php

namespace App\Modules\Printing\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Assets\Models\Asset;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrintLookupController extends Controller
{
    public function materials(Request $request): JsonResponse
    {
        $materials = LibraryMaterial::query()
            ->active()
            ->when($request->filled('search'), fn ($q) => $q->search($request->get('search')))
            ->orderBy('material_name')
            ->limit((int) $request->get('limit', 12))
            ->get()
            ->map(fn ($material) => [
                'id' => $material->id,
                'material_code' => $material->material_code,
                'material_name' => $material->material_name,
                'unit_of_measure' => $material->unit_of_measure,
            ]);

        return response()->json(['data' => $materials]);
    }

    public function machines(Request $request): JsonResponse
    {
        $machines = Asset::query()
            ->active()
            ->when($request->filled('search'), fn ($q) => $q->search($request->get('search')))
            ->orderBy('name')
            ->limit((int) $request->get('limit', 12))
            ->get()
            ->map(fn ($asset) => [
                'id' => $asset->id,
                'name' => $asset->name,
                'asset_code' => $asset->asset_code,
            ]);

        return response()->json(['data' => $machines]);
    }

    public function operators(Request $request): JsonResponse
    {
        $operators = User::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->get('search') . '%';
                $q->where('name', 'like', $term)->orWhere('email', 'like', $term);
            })
            ->orderBy('name')
            ->limit((int) $request->get('limit', 12))
            ->get(['id', 'name', 'email']);

        return response()->json(['data' => $operators]);
    }
}
