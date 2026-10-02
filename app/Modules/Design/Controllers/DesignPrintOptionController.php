<?php

namespace App\Modules\Design\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Design\Models\DesignPrintOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DesignPrintOptionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'surfaces' => DesignPrintOption::where('kind', 'surface')->orderBy('id')->get(),
            'bleeds' => DesignPrintOption::where('kind', 'bleed')->orderBy('bleed_per_side_m')->get(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['surface', 'bleed'])],
            'label' => ['required_if:kind,surface', 'nullable', 'string', 'max:191'],
            'bleed_per_side_m' => ['required_if:kind,bleed', 'nullable', 'numeric', 'min:0.001', 'max:10'],
        ]);

        if ($data['kind'] === 'surface') {
            $label = trim($data['label']);
            $option = DesignPrintOption::firstOrCreate(['kind' => 'surface', 'label' => $label]);
        } else {
            $metres = round((float) $data['bleed_per_side_m'], 3);
            $option = DesignPrintOption::firstOrCreate(
                ['kind' => 'bleed', 'bleed_per_side_m' => $metres],
                ['label' => number_format($metres * 100, 1, '.', '') . 'cm / ' . number_format($metres, 3, '.', '') . 'm']
            );
        }

        return response()->json(['data' => $option], $option->wasRecentlyCreated ? 201 : 200);
    }
}
