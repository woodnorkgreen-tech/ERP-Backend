<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Services\CashMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CashMovementController extends Controller
{
    public function accounts(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        return response()->json([
            'data' => ChartOfAccount::postable()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'account_type', 'category']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        $data = $request->validate([
            'payment_source_id' => ['nullable', 'integer', 'exists:payment_sources,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $movements = CashMovement::with('paymentSource:id,code,name', 'offsetAccount:id,code,name')
            ->when($data['payment_source_id'] ?? null, fn ($query, $id) => $query->where('payment_source_id', $id))
            ->when($data['start_date'] ?? null, fn ($query, $date) => $query->whereDate('transaction_date', '>=', $date))
            ->when($data['end_date'] ?? null, fn ($query, $date) => $query->whereDate('transaction_date', '<=', $date))
            ->latest('transaction_date')->latest('id')->paginate(50);

        return response()->json(['data' => $movements]);
    }

    public function store(Request $request, CashMovementService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE), 403);

        $data = $request->validate([
            'payment_source_id' => ['required', 'integer', 'exists:payment_sources,id'],
            'transaction_date' => ['required', 'date'],
            'direction' => ['required', 'in:in,out'],
            'transaction_type' => ['required', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:1000'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'offset_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
        ]);

        try {
            return response()->json(['data' => $service->create($data, $request->user()->id)], 201);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function void(Request $request, CashMovement $movement, CashMovementService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            return response()->json(['data' => $service->void($movement, $request->user()->id, $data['reason'])]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}