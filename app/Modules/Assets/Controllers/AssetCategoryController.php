<?php

namespace App\Modules\Assets\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\AssetCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssetCategoryController extends Controller
{
    /**
     * List categories. Defaults to a flat, active-only list (sorted, parents
     * before their children) — what the asset form's dropdown needs.
     * Pass ?tree=1 for the nested shape the management screen uses.
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('tree')) {
            $tree = AssetCategory::with('children')
                ->roots()
                ->orderBy('sort_order')->orderBy('name')
                ->get();

            return response()->json(['data' => $tree]);
        }

        $categories = AssetCategory::query()
            ->when(!$request->boolean('with_inactive'), fn ($q) => $q->active())
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }

    /**
     * Create a category (or sub-category). Used by both the dedicated
     * management screen and the "+ Add new category" quick-add in the asset form.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer', 'exists:asset_categories,id'],
            'code' => ['nullable', 'string', 'max:10', 'unique:asset_categories,code'],
        ]);

        $exists = AssetCategory::where('name', $data['name'])
            ->where('parent_id', $data['parent_id'] ?? null)
            ->first();

        if ($exists) {
            return response()->json(['data' => $exists, 'message' => 'Category already exists']);
        }

        $data['code'] = $data['code'] ?? AssetCategory::suggestCode($data['name']);

        $category = AssetCategory::create($data);

        return response()->json([
            'message' => 'Category created',
            'data' => $category,
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $category = AssetCategory::findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:10', "unique:asset_categories,code,{$id}"],
            'parent_id' => [
                'nullable', 'integer', 'exists:asset_categories,id',
                Rule::notIn([$id]), // can't be its own parent
            ],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['boolean'],
        ]);

        $category->update($data);

        return response()->json([
            'message' => 'Category updated',
            'data' => $category,
        ]);
    }

    /**
     * Delete a category — blocked if it still has assets or sub-categories,
     * so we never silently orphan data tracked in the asset register.
     */
    public function destroy($id): JsonResponse
    {
        $category = AssetCategory::findOrFail($id);

        $assetCount = $category->assets()->count();
        if ($assetCount > 0) {
            return response()->json([
                'message' => "Cannot delete — {$assetCount} asset(s) are filed under this category.",
            ], 422);
        }

        $childCount = $category->children()->count();
        if ($childCount > 0) {
            return response()->json([
                'message' => "Cannot delete — it still has {$childCount} sub-categor" . ($childCount === 1 ? 'y' : 'ies') . '.',
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted']);
    }
}
