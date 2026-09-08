<?php

namespace App\Modules\Design\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Design\Models\DesignType;
use App\Modules\Design\Requests\StoreDesignTypeRequest;
use App\Modules\Design\Resources\DesignTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesignTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DesignType::query()->orderBy('stream')->orderBy('sort_order')->orderBy('name');

        if ($request->filled('stream')) {
            $query->where('stream', $request->stream);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json(['data' => DesignTypeResource::collection($query->get())]);
    }

    public function store(StoreDesignTypeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        $type = DesignType::create($data);

        return response()->json([
            'message' => 'Design type created successfully',
            'data' => new DesignTypeResource($type),
        ], 201);
    }

    public function update(StoreDesignTypeRequest $request, DesignType $type): JsonResponse
    {
        $data = $request->validated();
        $data['updated_by'] = auth()->id();
        $type->update($data);

        return response()->json([
            'message' => 'Design type updated successfully',
            'data' => new DesignTypeResource($type),
        ]);
    }

    public function destroy(DesignType $type): JsonResponse
    {
        $type->delete();

        return response()->json(['message' => 'Design type deleted successfully']);
    }
}
