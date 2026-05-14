<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Models\DeliverablesBlueprint;
use Illuminate\Http\Request;

class DeliverablesBlueprintController extends Controller
{
    public function index()
    {
        $blueprints = DeliverablesBlueprint::latest()->get();
        return response()->json([
            'success' => true,
            'data' => $blueprints
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string',
            'description' => 'nullable|string',
            'materials' => 'nullable|array',
            'labour' => 'nullable|array',
            'keywords' => 'nullable|array',
        ]);

        $blueprint = DeliverablesBlueprint::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Architecture created successfully',
            'data' => $blueprint
        ]);
    }

    public function show(DeliverablesBlueprint $deliverablesBlueprint)
    {
        return response()->json([
            'success' => true,
            'data' => $deliverablesBlueprint
        ]);
    }

    public function update(Request $request, DeliverablesBlueprint $deliverablesBlueprint)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string',
            'description' => 'nullable|string',
            'materials' => 'nullable|array',
            'labour' => 'nullable|array',
            'keywords' => 'nullable|array',
        ]);

        $deliverablesBlueprint->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Architecture updated successfully',
            'data' => $deliverablesBlueprint
        ]);
    }

    public function destroy(DeliverablesBlueprint $deliverablesBlueprint)
    {
        $deliverablesBlueprint->delete();

        return response()->json([
            'success' => true,
            'message' => 'Architecture decommissioned successfully'
        ]);
    }
}
