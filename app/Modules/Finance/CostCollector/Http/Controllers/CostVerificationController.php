<?php

namespace App\Modules\Finance\CostCollector\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\CostCollector\Exceptions\CostValidationException;
use App\Modules\Finance\CostCollector\Http\Resources\CostLineResource;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CostVerificationController extends Controller
{
    public function __construct(private CostVerificationService $verification) {}

    /**
     * The queue.
     *
     * Ordered oldest first, because the risk in cost verification is a backlog
     * ageing quietly, not a single large item.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CostLine::class);

        $validated = $request->validate([
            'status' => 'nullable|string|max:32',
            'job_number' => 'nullable|string|max:64',
            'unbudgeted' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $lines = CostLine::with('expenseCode')
            ->where('nature', '!=', CostLine::NATURE_PLANNED)
            ->when(
                $validated['status'] ?? null,
                fn ($q, $status) => $q->where('status', $status),
                fn ($q) => $q->whereIn('status', [CostLine::STATUS_SUBMITTED, CostLine::STATUS_QUERIED]),
            )
            ->when($validated['job_number'] ?? null, fn ($q, $job) => $q->where('job_number', $job))
            ->when($validated['unbudgeted'] ?? null, fn ($q) => $q->whereNull('consumes_line_id'))
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => CostLineResource::collection($lines->items()),
            'meta' => [
                'current_page' => $lines->currentPage(),
                'last_page' => $lines->lastPage(),
                'total' => $lines->total(),
                'awaiting' => CostLine::whereIn('status', [CostLine::STATUS_SUBMITTED, CostLine::STATUS_QUERIED])
                    ->where('nature', '!=', CostLine::NATURE_PLANNED)->count(),
            ],
        ]);
    }

    public function verify(Request $request, CostLine $cost): JsonResponse
    {
        $this->authorize('verify', $cost);

        $validated = $request->validate([
            'tax_amount' => 'nullable|numeric|min:0',
            'vat_treatment_id' => 'nullable|integer|exists:vat_treatments,id',
            'wht_category_id' => 'nullable|integer|exists:wht_categories,id',
        ]);

        return $this->attempt(fn () => $this->verification->verify(
            $cost, $request->user(), array_filter($validated, fn ($v) => $v !== null),
        ), 'Cost verified.');
    }

    public function query(Request $request, CostLine $cost): JsonResponse
    {
        $this->authorize('verify', $cost);

        $validated = $request->validate(['note' => 'required|string|max:500']);

        return $this->attempt(
            fn () => $this->verification->query($cost, $request->user(), $validated['note']),
            'Query sent back to the person who reported it.',
        );
    }

    public function reject(Request $request, CostLine $cost): JsonResponse
    {
        $this->authorize('verify', $cost);

        $validated = $request->validate(['reason' => 'required|string|max:500']);

        return $this->attempt(
            fn () => $this->verification->reject($cost, $request->user(), $validated['reason']),
            'Cost rejected.',
        );
    }

    public function reverse(Request $request, CostLine $cost): JsonResponse
    {
        $this->authorize('reverse', $cost);

        $validated = $request->validate(['reason' => 'required|string|max:500']);

        return $this->attempt(
            fn () => $this->verification->reverse($cost, $request->user(), $validated['reason']),
            'Cost reversed. It no longer counts against the project.',
        );
    }

    public function resubmit(Request $request, CostLine $cost): JsonResponse
    {
        $this->authorize('resubmit', $cost);

        return $this->attempt(fn () => $this->verification->resubmit($cost), 'Sent back for verification.');
    }

    /** @param callable():CostLine $action */
    private function attempt(callable $action, string $message): JsonResponse
    {
        try {
            $line = $action();
        } catch (CostValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], 422);
        }

        return response()->json([
            'message' => $message,
            'data' => new CostLineResource($line->load('expenseCode')),
        ]);
    }
}
