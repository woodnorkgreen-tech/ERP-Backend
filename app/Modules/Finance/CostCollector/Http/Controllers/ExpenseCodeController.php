<?php

namespace App\Modules\Finance\CostCollector\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\CostCollector\Http\Resources\ExpenseCodeResource;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The expense-type picker.
 *
 * The catalogue runs to hundreds of codes, so a flat dropdown is unusable. This
 * exposes search and family drill-down instead, and returns the per-code form
 * definition so the client renders the right fields without knowing anything
 * about any particular expense type.
 */
class ExpenseCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'nullable|string|max:120',
            'family' => 'nullable|string|max:120',
            'job_context' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $query = ExpenseCode::active();

        if ($search = $validated['q'] ?? null) {
            $query->where(function ($q) use ($search) {
                $q->where('expense_type', 'like', "%{$search}%")
                    ->orWhere('simple_meaning', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($family = $validated['family'] ?? null) {
            $query->where('expense_family', $family);
        }

        // Capturing against a project should not offer codes that forbid a Job
        // ID, and vice versa — filtering here keeps the picker short rather than
        // letting the user pick something the collector will then reject.
        if (array_key_exists('job_context', $validated)) {
            $query->where('job_id_rule', $validated['job_context']
                ? '!='
                : '=', ExpenseCode::JOB_NOT_ALLOWED);
        }

        $codes = $query->orderBy('sort_order')->orderBy('expense_type')
            ->limit($validated['limit'] ?? 50)
            ->get();

        return response()->json(['data' => ExpenseCodeResource::collection($codes)]);
    }

    /** The families, for drill-down when the user does not know what to search. */
    public function families(): JsonResponse
    {
        $families = ExpenseCode::active()
            ->selectRaw('expense_family, COUNT(*) as code_count')
            ->groupBy('expense_family')
            ->orderBy('expense_family')
            ->get();

        return response()->json(['data' => $families]);
    }

    public function show(string $code): JsonResponse
    {
        $expenseCode = ExpenseCode::active()->where('code', $code)->firstOrFail();

        return response()->json(['data' => new ExpenseCodeResource($expenseCode)]);
    }
}
