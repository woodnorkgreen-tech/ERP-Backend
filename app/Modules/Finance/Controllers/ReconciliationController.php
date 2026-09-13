<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\ReconciliationStatement;
use App\Modules\Finance\Models\StatementTransaction;
use App\Modules\Finance\Services\ReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ReconciliationController extends Controller
{
    public function accounts(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        return response()->json([
            'data' => PaymentSource::query()
                ->whereIn('type', ['bank', 'mobile_money', 'card', 'petty_cash'])
                ->where('is_active', true)
                ->with('glAccount:id,code,name')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function import(Request $request, ReconciliationService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE), 403);

        $data = $request->validate([
            'payment_source_id' => ['required', 'integer', 'exists:payment_sources,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'opening_balance' => ['required', 'numeric'],
            'closing_balance' => ['required', 'numeric'],
            'statement' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        try {
            $statement = $service->importCsv(
                PaymentSource::findOrFail($data['payment_source_id']),
                $data['statement'],
                $data['period_start'],
                $data['period_end'],
                (string) $data['opening_balance'],
                (string) $data['closing_balance'],
                $request->user()->id,
            );

            return response()->json(['data' => $statement, 'summary' => $service->summary($statement)], 201);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function show(Request $request, ReconciliationStatement $statement, ReconciliationService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        return response()->json([
            'data' => $statement->load('paymentSource', 'transactions.matches'),
            'summary' => $service->summary($statement),
        ]);
    }

    public function match(Request $request, ReconciliationStatement $statement, StatementTransaction $transaction, ReconciliationService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE), 403);

        $data = $request->validate([
            'journal_entry_id' => ['nullable', 'integer', 'exists:journal_entries,id'],
            'payment_id' => ['nullable', 'integer', 'exists:payments,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            return response()->json(['data' => $service->match(
                $statement,
                $transaction,
                $data['journal_entry_id'] ?? null,
                $data['payment_id'] ?? null,
                (string) $data['amount'],
                $request->user()->id,
            )]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function ignore(Request $request, ReconciliationStatement $statement, StatementTransaction $transaction, ReconciliationService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE), 403);

        try {
            return response()->json(['data' => $service->ignore($statement, $transaction, $request->user()->id)]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
    
    public function suggestions(Request $request, ReconciliationStatement $statement, StatementTransaction $transaction, ReconciliationService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        try {
            return response()->json(['data' => $service->suggestions($statement, $transaction)]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function reconcile(Request $request, ReconciliationStatement $statement, ReconciliationService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE), 403);

        try {
            return response()->json(['data' => $service->reconcile($statement, $request->user()->id)]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function reopen(Request $request, ReconciliationStatement $statement, ReconciliationService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE), 403);

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            return response()->json(['data' => $service->reopen($statement, $data['reason'])]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}