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

    public function index(Request $request, ReconciliationService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        $sourceId = $request->validate([
            'payment_source_id' => ['required', 'integer', 'exists:payment_sources,id'],
        ])['payment_source_id'];

        $source = PaymentSource::findOrFail($sourceId);

        return response()->json([
            'data' => $service->listStatements($source),
        ]);
    }

    public function prefill(Request $request, ReconciliationService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        $sourceId = $request->validate([
            'payment_source_id' => ['required', 'integer', 'exists:payment_sources,id'],
        ])['payment_source_id'];

        $source = PaymentSource::findOrFail($sourceId);

        return response()->json([
            'data' => $service->prefill($source),
        ]);
    }

    public function show(Request $request, ReconciliationStatement $statement, ReconciliationService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        return response()->json([
            'data' => $statement->load('paymentSource', 'transactions.matches.journalEntry', 'transactions.matches.payment'),
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

    public function createAndMatch(Request $request, ReconciliationStatement $statement, StatementTransaction $transaction, ReconciliationService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE), 403);

        $data = $request->validate([
            'offset_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'transaction_type' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'counterparty' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $updatedTx = $service->createAndMatch(
                $statement,
                $transaction,
                $data,
                $request->user()->id,
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Ledger movement posted and statement transaction matched.',
                'data' => $updatedTx->load('matches.journalEntry', 'matches.payment'),
                'summary' => $service->summary($statement->fresh()),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function unmatch(Request $request, ReconciliationStatement $statement, StatementTransaction $transaction, ReconciliationService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE), 403);

        try {
            return response()->json([
                'status' => 'success',
                'message' => 'Transaction unmatched.',
                'data' => $service->unmatch($statement, $transaction),
                'summary' => $service->summary($statement->fresh()),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function ignore(Request $request, ReconciliationStatement $statement, StatementTransaction $transaction, ReconciliationService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE), 403);

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            return response()->json(['data' => $service->ignore($statement, $transaction, $request->user()->id, $data['reason'])]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function autoMatch(Request $request, ReconciliationStatement $statement, ReconciliationService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE), 403);

        $matchedCount = $service->runAutoMatch($statement, $request->user()->id);
        $tolerance = $service->dateToleranceDays();

        return response()->json([
            'status' => 'success',
            'matched_count' => $matchedCount,
            'message' => $matchedCount > 0
                ? "Auto-match complete: {$matchedCount} transaction(s) matched automatically."
                : "No new matches found based on Reference + Amount + Account + Date (±{$tolerance} days tolerance).",
            'summary' => $service->summary($statement->fresh()),
        ]);
    }
    
    public function suggestions(Request $request, ReconciliationStatement $statement, StatementTransaction $transaction, ReconciliationService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        try {
            return response()->json(['data' => $service->candidates($statement, $transaction)]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function candidates(Request $request, ReconciliationStatement $statement, StatementTransaction $transaction, ReconciliationService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        try {
            return response()->json([
                'data' => $service->candidates(
                    $statement,
                    $transaction,
                    $filters['search'] ?? null,
                    $filters['from_date'] ?? null,
                    $filters['to_date'] ?? null,
                ),
            ]);
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
