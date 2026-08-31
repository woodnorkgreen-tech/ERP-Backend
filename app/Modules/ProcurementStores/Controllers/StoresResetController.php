<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ProcurementStores\Services\StoresResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoresResetController extends Controller
{
    public function __construct(private readonly StoresResetService $reset) {}

    private function superAdmin(): void
    {
        abort_unless(
            auth()->user()?->hasRole('Super Admin'),
            403,
            'Only a Super Admin can reset the Stores inventory.'
        );
    }

    public function preview(): JsonResponse
    {
        $this->superAdmin();
        return response()->json(['data' => $this->reset->preview()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->superAdmin();
        $validated = $request->validate([
            'confirmation' => 'required|string',
            'reason' => 'required|string|min:10|max:500',
        ]);
        if ($validated['confirmation'] !== StoresResetService::CONFIRMATION_PHRASE) {
            return response()->json([
                'message' => 'Type '.StoresResetService::CONFIRMATION_PHRASE.' exactly to confirm this reset.',
            ], 422);
        }

        $deleted = $this->reset->reset($validated['reason']);
        return response()->json([
            'message' => 'Stores inventory reset. '.array_sum($deleted).' records cleared; start the opening inventory to set the new baseline.',
            'data' => ['deleted' => $deleted],
        ]);
    }
}
