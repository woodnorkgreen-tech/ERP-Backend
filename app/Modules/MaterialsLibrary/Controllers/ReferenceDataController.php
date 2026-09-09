<?php

namespace App\Modules\MaterialsLibrary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\MaterialItemType;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\MaterialsLibrary\Requests\StoreUnitOfMeasureRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

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

    /**
     * Register a unit the registry does not hold yet.
     *
     * Adoption, not duplication: a code or name that already exists returns the
     * unit already registered (reactivating it if it had been retired) instead
     * of creating a second row. Two units meaning the same thing is the one
     * failure mode that would quietly break conversions and stock arithmetic.
     */
    public function storeUnitOfMeasure(StoreUnitOfMeasureRequest $request): JsonResponse
    {
        $data = $request->validated();
        $name = trim($data['name']);
        $code = $this->normaliseUnitCode($data['code'] ?? null, $name);

        $existing = UnitOfMeasure::whereRaw('LOWER(code) = ?', [$code])
            ->orWhereRaw('LOWER(TRIM(name)) = ?', [Str::lower($name)])
            ->first();

        if ($existing) {
            if (! $existing->is_active) {
                $existing->update(['is_active' => true]);
            }

            return response()->json([
                'data' => $existing->fresh(),
                'adopted' => true,
                'message' => "“{$existing->name} ({$existing->code})” is already registered — it has been selected for you.",
            ]);
        }

        $allowsFraction = array_key_exists('allows_fraction', $data)
            ? (bool) $data['allows_fraction']
            : ! in_array($data['dimension'], ['count', 'package'], true);

        $unit = UnitOfMeasure::create([
            'code' => $code,
            'name' => $name,
            'dimension' => $data['dimension'],
            'decimal_places' => $data['decimal_places'] ?? $this->defaultDecimalPlaces($data['dimension'], $allowsFraction),
            'allows_fraction' => $allowsFraction,
            'is_active' => true,
        ]);

        return response()->json(['data' => $unit, 'adopted' => false], 201);
    }

    /** Registry codes are lowercase and short — they sit beside every quantity on screen. */
    private function normaliseUnitCode(?string $code, string $name): string
    {
        $candidate = Str::lower(trim((string) ($code ?: $name)));
        $candidate = preg_replace('/[^a-z0-9]+/', '_', $candidate) ?? '';
        $candidate = trim($candidate, '_');

        return Str::limit($candidate, 20, '') ?: 'unit';
    }

    private function defaultDecimalPlaces(string $dimension, bool $allowsFraction): int
    {
        if (! $allowsFraction) {
            return 0;
        }

        return match ($dimension) {
            'area' => 4,
            'length', 'volume', 'mass' => 3,
            default => 2,
        };
    }
}
