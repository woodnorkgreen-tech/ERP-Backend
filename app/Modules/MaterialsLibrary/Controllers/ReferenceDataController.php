<?php

namespace App\Modules\MaterialsLibrary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\MaterialItemType;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use Illuminate\Http\JsonResponse;

class ReferenceDataController extends Controller
{
    public function itemTypes(): JsonResponse
    {
        return response()->json(['data' => MaterialItemType::where('is_active', true)->orderBy('name')->get()]);
    }

    public function unitsOfMeasure(): JsonResponse
    {
        return response()->json(['data' => UnitOfMeasure::where('is_active', true)
            ->orderBy('dimension')->orderBy('name')->get()]);
    }
}
