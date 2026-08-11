<?php

namespace App\Modules\Design\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Design\Models\DesignBomItem;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Requests\StoreDesignBomItemRequest;
use App\Modules\Design\Resources\DesignBomItemResource;
use Illuminate\Http\JsonResponse;

class DesignBomItemController extends Controller
{
    public function index(DesignItem $item): JsonResponse
    {
        return response()->json([
            'data' => DesignBomItemResource::collection($item->bomItems()->with('material.baseUom')->latest()->get()),
        ]);
    }

    public function store(StoreDesignBomItemRequest $request, DesignItem $item): JsonResponse
    {
        $data = $request->validated();
        $data['design_item_id'] = $item->id;
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        $bomItem = DesignBomItem::create($data)->load('material.baseUom');

        return response()->json([
            'message' => 'BOM item created successfully',
            'data' => new DesignBomItemResource($bomItem),
        ], 201);
    }

    public function update(StoreDesignBomItemRequest $request, DesignBomItem $bomItem): JsonResponse
    {
        $data = $request->validated();
        $data['updated_by'] = auth()->id();
        $bomItem->update($data);
        $bomItem->load('material.baseUom');

        return response()->json([
            'message' => 'BOM item updated successfully',
            'data' => new DesignBomItemResource($bomItem),
        ]);
    }

    public function destroy(DesignBomItem $bomItem): JsonResponse
    {
        $bomItem->delete();

        return response()->json(['message' => 'BOM item deleted successfully']);
    }
}
