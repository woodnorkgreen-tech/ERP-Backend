<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Http\Controllers\Concerns\HandlesProjectErrors;
use App\Modules\Projects\Models\DeliverablesBlueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliverablesBlueprintController extends Controller
{
    use HandlesProjectErrors;

    public function index(): JsonResponse
    {
        return $this->safe(function () {
            $blueprints = DeliverablesBlueprint::latest()->get();
            return response()->json([
                'success' => true,
                'data' => $blueprints
            ]);
        }, 'List deliverables blueprints');
    }

    public function store(Request $request): JsonResponse
    {
        return $this->safe(function () use ($request) {
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
            ], 201);
        }, 'Create deliverables blueprint');
    }

    public function show(DeliverablesBlueprint $deliverablesBlueprint): JsonResponse
    {
        return $this->safe(fn () => response()->json([
            'success' => true,
            'data' => $deliverablesBlueprint
        ]), 'Show deliverables blueprint');
    }

    public function update(Request $request, DeliverablesBlueprint $deliverablesBlueprint): JsonResponse
    {
        return $this->safe(function () use ($request, $deliverablesBlueprint) {
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
        }, 'Update deliverables blueprint');
    }

    public function destroy(DeliverablesBlueprint $deliverablesBlueprint): JsonResponse
    {
        return $this->safe(function () use ($deliverablesBlueprint) {
            $deliverablesBlueprint->delete();

            return response()->json([
                'success' => true,
                'message' => 'Architecture decommissioned successfully'
            ]);
        }, 'Delete deliverables blueprint');
    }
}
