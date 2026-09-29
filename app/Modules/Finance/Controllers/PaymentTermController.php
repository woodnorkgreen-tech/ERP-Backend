<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\PaymentTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * W1-7: configurable payment-term templates. No term is seeded by this
 * controller or its migration — Finance creates the real, confirmed values;
 * "Due on Receipt"/7/14/30 days appear only as illustrative examples in the
 * decision documents, never as data this code writes on WNG's behalf.
 */
class PaymentTermController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PaymentTerm::query()->orderBy('name');

        if ($request->boolean('active_only')) {
            $query->active();
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'is_custom' => 'required|boolean',
            'days' => 'required_if:is_custom,false|nullable|integer|min:0|max:365',
            'is_active' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
        ]);

        $term = PaymentTerm::create([
            'name' => $data['name'],
            'days' => $data['is_custom'] ? null : $data['days'],
            'is_custom' => $data['is_custom'],
            'is_active' => $data['is_active'] ?? true,
            'is_default' => $data['is_default'] ?? false,
            'created_by' => Auth::id(),
        ]);

        if ($term->is_default) {
            PaymentTerm::where('id', '!=', $term->id)->update(['is_default' => false]);
        }

        return response()->json(['message' => 'Payment term created.', 'data' => $term], 201);
    }

    public function update(Request $request, PaymentTerm $paymentTerm): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'is_custom' => 'sometimes|boolean',
            'days' => 'nullable|integer|min:0|max:365',
            'is_active' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
        ]);

        $paymentTerm->update($data);

        if ($paymentTerm->is_default) {
            PaymentTerm::where('id', '!=', $paymentTerm->id)->update(['is_default' => false]);
        }

        return response()->json(['message' => 'Payment term updated.', 'data' => $paymentTerm->fresh()]);
    }
}
