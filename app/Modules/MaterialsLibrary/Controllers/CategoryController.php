<?php

namespace App\Modules\MaterialsLibrary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * Return the full category tree (parents with their children).
     * Used by the frontend to build cascading dropdowns.
     */
    public function tree(): JsonResponse
    {
        $parents = MaterialCategory::with(['children' => fn ($q) => $q->active()->ordered()])
            ->active()
            ->roots()
            ->ordered()
            ->get()
            ->map(fn ($parent) => [
                'id'       => $parent->id,
                'name'     => $parent->name,
                'children' => $parent->children->map(fn ($child) => [
                    'id'        => $child->id,
                    'name'      => $child->name,
                    'code'      => $child->code,
                    'parent_id' => $child->parent_id,
                ]),
            ]);

        return response()->json(['data' => $parents]);
    }

    /**
     * Return a flat list of all active categories.
     * Used for simple select dropdowns and validation lookups.
     */
    public function index(): JsonResponse
    {
        $categories = MaterialCategory::with('parent')
            ->active()
            ->ordered()
            ->get()
            ->map(fn ($cat) => [
                'id'          => $cat->id,
                'name'        => $cat->name,
                'parent_id'   => $cat->parent_id,
                'parent_name' => $cat->parent?->name,
                'is_root'     => $cat->isRoot(),
            ]);

        return response()->json(['data' => $categories]);
    }
}
