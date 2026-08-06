<?php

namespace App\Modules\MaterialsLibrary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\Workstation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                    'is_selectable' => $child->is_selectable,
                    'default_issue_disposition' => $child->default_issue_disposition,
                    'default_tracking_mode' => $child->default_tracking_mode,
                    'allowed_uoms' => $child->allowed_uoms,
                    'required_attributes' => $child->required_attributes,
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
                'is_selectable' => $cat->is_selectable,
                'default_issue_disposition' => $cat->default_issue_disposition,
                'default_tracking_mode' => $cat->default_tracking_mode,
                'allowed_uoms' => $cat->allowed_uoms,
                'required_attributes' => $cat->required_attributes,
            ]);

        return response()->json(['data' => $categories]);
    }

    /**
     * Suggest the next available SKU code for a given workstation + category.
     *
     * Format: {WS_CODE}-{CAT_CODE}-{SEQ:04d}
     * Example: CARP-PLY-0001, CNC-MDF-0003, LFP-VNL-0015
     *
     * The sequence is per workstation+category prefix, ensuring each combination
     * has its own independent counter. Gaps in sequence are skipped; the next
     * truly unused code is returned.
     */
    public function suggestCode(Request $request): JsonResponse
    {
        $request->validate([
            'workstation_id' => 'required|integer|exists:workstations,id',
            'category_id'    => 'required|integer|exists:material_categories,id',
        ]);

        $workstation = Workstation::findOrFail($request->workstation_id);
        $category    = MaterialCategory::findOrFail($request->category_id);

        $wsCode  = strtoupper(trim($workstation->code));
        $catCode = strtoupper(trim($category->code ?? $category->name));

        // Trim category code to 6 chars max to keep SKUs readable
        $catCode = preg_replace('/[^A-Z0-9]/', '', substr($catCode, 0, 6));

        $prefix = "{$wsCode}-{$catCode}-";

        // Count existing codes with this prefix to seed the sequence
        $count = DB::table('library_materials')
            ->where('material_code', 'like', $prefix . '%')
            ->whereNull('deleted_at')
            ->count();

        // Find the next unused code, skipping any gaps
        $seq  = $count + 1;
        $code = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

        while (DB::table('library_materials')->where('material_code', $code)->exists()) {
            $seq++;
            $code = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
        }

        return response()->json([
            'code'   => $code,
            'prefix' => $prefix,
            'seq'    => $seq,
        ]);
    }
}
