<?php

namespace App\Modules\Printing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Design\Models\DesignHandoff;
use App\Modules\Design\Resources\DesignHandoffResource;
use App\Modules\Printing\Resources\PrintJobResource;
use App\Modules\Printing\Services\PrintIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrintIntakeController extends Controller
{
    public function __construct(private readonly PrintIntakeService $intake)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $handoffs = DesignHandoff::query()
            ->where('target_module', 'printing')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')), fn ($q) => $q->where('status', 'pending'))
            ->latest('handed_off_at')
            ->paginate((int) $request->get('per_page', 20));

        return response()->json($handoffs->through(fn ($handoff) => new DesignHandoffResource($handoff)));
    }

    public function accept(DesignHandoff $handoff): JsonResponse
    {
        return response()->json([
            'message' => 'Printing intake accepted',
            'data' => new PrintJobResource($this->intake->accept($handoff)),
        ], 201);
    }

    public function reject(Request $request, DesignHandoff $handoff): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json([
            'message' => 'Printing intake rejected',
            'data' => new DesignHandoffResource($this->intake->reject($handoff, $data['reason'])),
        ]);
    }
}
