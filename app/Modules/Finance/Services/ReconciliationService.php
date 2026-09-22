<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\FinanceSetting;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\ReconciliationStatement;
use App\Modules\Finance\Models\StatementMatch;
use App\Modules\Finance\Models\StatementTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReconciliationService
{
    public const DATE_TOLERANCE_SETTING = 'reconciliation_date_tolerance_days';

    public function importCsv(
        PaymentSource $source,
        UploadedFile $file,
        string $periodStart,
        string $periodEnd,
        string $openingBalance,
        string $closingBalance,
        ?int $actorId,
    ): ReconciliationStatement {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('The statement file could not be opened.');
        }

        $headers = fgetcsv($handle);
        if (! is_array($headers)) {
            fclose($handle);
            throw new InvalidArgumentException('The statement file is empty.');
        }

        $headers = array_map(fn ($header) => $this->normalise((string) $header), $headers);
        foreach (['date', 'debit', 'credit'] as $required) {
            if (! in_array($required, $headers, true)) {
                fclose($handle);
                throw new InvalidArgumentException("The statement must contain a {$required} column.");
            }
        }

        return DB::transaction(function () use ($source, $file, $handle, $headers, $periodStart, $periodEnd, $openingBalance, $closingBalance, $actorId): ReconciliationStatement {
            $statement = ReconciliationStatement::create([
                'payment_source_id' => $source->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'opening_balance' => $this->decimal($openingBalance),
                'closing_balance' => $this->decimal($closingBalance),
                'currency' => $source->currency ?: 'KES',
                'status' => 'draft',
                'imported_by' => $actorId,
            ]);

            while (($row = fgetcsv($handle)) !== false) {
                if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }

                $values = [];
                foreach ($headers as $index => $header) {
                    $values[$header] = trim((string) ($row[$index] ?? ''));
                }

                $date = $values['date'];
                if (! $date || ! strtotime($date)) {
                    throw new InvalidArgumentException('Every statement row must contain a valid date.');
                }

                $debit = $this->decimal($values['debit'] ?? '0');
                $credit = $this->decimal($values['credit'] ?? '0');
                if (bccomp($debit, '0.00', 2) === 1 && bccomp($credit, '0.00', 2) === 1) {
                    throw new InvalidArgumentException('A statement row cannot contain both debit and credit amounts.');
                }

                $reference = $values['reference'] ?? $values['external_reference'] ?? null;
                $description = $values['description'] ?? $values['details'] ?? null;
                $balance = ($values['balance'] ?? $values['statement_balance'] ?? '') !== ''
                    ? $this->decimal($values['balance'] ?? $values['statement_balance'])
                    : null;
                $fingerprint = hash('sha256', implode('|', [$date, $reference, $description, $debit, $credit, $balance]));

                $transaction = StatementTransaction::firstOrCreate(
                    ['statement_id' => $statement->id, 'fingerprint' => $fingerprint],
                    [
                        'transaction_date' => date('Y-m-d', strtotime($date)),
                        'external_reference' => $reference,
                        'description' => $description,
                        'debit' => $debit,
                        'credit' => $credit,
                        'statement_balance' => $balance,
                        'match_status' => 'unmatched',
                        'metadata' => ['source_file' => $file->getClientOriginalName()],
                    ],
                );

                $this->autoMatch($statement, $transaction, $reference, $debit, $credit, $date, $actorId);
            }

            fclose($handle);

            return $statement->load('paymentSource', 'transactions');
        });
    }

    public function autoMatch(
        ReconciliationStatement $statement,
        StatementTransaction $transaction,
        ?string $reference,
        string $debit,
        string $credit,
        string $date,
        ?int $actorId
    ): bool {
        if ($transaction->match_status !== 'unmatched') {
            return false;
        }

        $cleanRef = trim((string) ($reference ?: $transaction->external_reference));
        if ($cleanRef === '') {
            // Control 3 (Reference): Required for safe automatic matching to prevent ambiguous false positives.
            return false;
        }

        $isCredit = bccomp($credit, '0.00', 2) === 1;
        $amount = $isCredit ? $credit : $debit;
        if (bccomp((string) $amount, '0.00', 2) !== 1) {
            return false;
        }

        // Control 4 (Date): Within the effective Finance clearing-date tolerance.
        $txDate = \Carbon\Carbon::parse($date);
        $dateTolerance = $this->dateToleranceDays($txDate->toDateString());
        $dateStart = $txDate->copy()->subDays($dateTolerance)->toDateString();
        $dateEnd = $txDate->copy()->addDays($dateTolerance)->toDateString();

        // Control 1: Account (must match payment_source_id of this statement)
        // Control 2: Amount (exact match)
        // Control 3: Reference (payment_no, external_reference, cheque number, or m-pesa code)
        // Payments are disbursements, so only a bank debit can match one.
        $payment = ! $isCredit
            ? Payment::query()
                ->where('payment_source_id', $statement->payment_source_id)
                ->where(function ($query) use ($cleanRef) {
                    $query->where('payment_no', $cleanRef)
                        ->orWhere('external_reference', $cleanRef);
                })
                ->whereBetween('date_disbursed', [$dateStart, $dateEnd])
                ->where('amount', $amount)
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('finance_statement_matches')
                        ->whereColumn('finance_statement_matches.payment_id', 'payments.id');
                })
                ->first()
            : null;

        $journal = null;
        if ($payment) {
            $journal = JournalEntry::where('source_type', Payment::class)
                ->where('source_id', $payment->id)
                ->first();
        } else {
            // Check journal entries directly (e.g. client deposits or direct account entries)
            $glAccountId = $statement->paymentSource?->gl_account_id;
            $journalQuery = JournalEntry::query()
                ->where(function ($query) use ($cleanRef) {
                    $query->where('entry_no', $cleanRef)
                        ->orWhere('source_ref', $cleanRef);
                })
                ->whereBetween('posting_date', [$dateStart, $dateEnd])
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('finance_statement_matches')
                        ->whereColumn('finance_statement_matches.journal_entry_id', 'journal_entries.id');
                });

            // A source without a mapped GL account cannot pass the account
            // control. On a bank statement, a credit is a GL debit (money in)
            // and a debit is a GL credit (money out).
            if (! $glAccountId) {
                return false;
            }

            $journalQuery->whereHas('lines', function ($q) use ($glAccountId, $isCredit, $amount) {
                $q->where('account_id', $glAccountId)
                    ->where('entry_type', $isCredit ? 'debit' : 'credit')
                    ->where('amount', $amount);
            });

            $journal = $journalQuery->first();
        }

        if (! $payment && ! $journal) {
            return false;
        }

        StatementMatch::create([
            'statement_transaction_id' => $transaction->id,
            'journal_entry_id' => $journal?->id,
            'payment_id' => $payment?->id,
            'amount' => $amount,
            'match_type' => 'automatic',
            'matched_by' => $actorId,
        ]);

        $transaction->forceFill([
            'match_status' => 'matched',
            'matched_by' => $actorId,
            'matched_at' => now(),
        ])->save();

        return true;
    }

    public function dateToleranceDays(?string $on = null): int
    {
        return max(0, min(31, FinanceSetting::integer(self::DATE_TOLERANCE_SETTING, 3, $on)));
    }

    public function runAutoMatch(ReconciliationStatement $statement, ?int $actorId): int
    {
        $statement->loadMissing('paymentSource');
        $unmatched = $statement->transactions()->where('match_status', 'unmatched')->get();
        $matchedCount = 0;

        foreach ($unmatched as $tx) {
            $matched = $this->autoMatch(
                $statement,
                $tx,
                $tx->external_reference,
                (string) $tx->debit,
                (string) $tx->credit,
                $tx->transaction_date->toDateString(),
                $actorId
            );

            if ($matched) {
                $matchedCount++;
            }
        }

        return $matchedCount;
    }

    public function match(
        ReconciliationStatement $statement,
        StatementTransaction $transaction,
        ?int $journalEntryId,
        ?int $paymentId,
        string $amount,
        ?int $actorId,
    ): StatementTransaction {
        if ($statement->status === 'reconciled') {
            throw new InvalidArgumentException('A reconciled statement cannot be changed. Reopen it first.');
        }
        if ($transaction->statement_id !== $statement->id) {
            throw new InvalidArgumentException('The transaction does not belong to this statement.');
        }
        if (! $journalEntryId && ! $paymentId) {
            throw new InvalidArgumentException('Select a journal entry or payment to match.');
        }

        $statementAmount = bccomp((string) $transaction->credit, '0.00', 2) === 1
            ? (string) $transaction->credit
            : (string) $transaction->debit;
        if (bccomp($this->decimal($amount), $this->decimal($statementAmount), 2) !== 0) {
            throw new InvalidArgumentException('The match amount must equal the statement transaction amount.');
        }

        DB::transaction(function () use ($transaction, $journalEntryId, $paymentId, $amount, $actorId): void {
            $transaction = StatementTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $transaction->matches()->delete();
            StatementMatch::create([
                'statement_transaction_id' => $transaction->id,
                'journal_entry_id' => $journalEntryId,
                'payment_id' => $paymentId,
                'amount' => $this->decimal($amount),
                'match_type' => 'manual',
                'matched_by' => $actorId,
            ]);
            $transaction->forceFill([
                'match_status' => 'matched',
                'ignore_reason' => null,
                'matched_by' => $actorId,
                'matched_at' => now(),
            ])->save();
        });

        return $transaction->fresh('matches');
    }

    public function ignore(ReconciliationStatement $statement, StatementTransaction $transaction, ?int $actorId, string $reason): StatementTransaction
    {
        if ($statement->status === 'reconciled') {
            throw new InvalidArgumentException('A reconciled statement cannot be changed. Reopen it first.');
        }
        if ($transaction->statement_id !== $statement->id) {
            throw new InvalidArgumentException('The transaction does not belong to this statement.');
        }

        $transaction->matches()->delete();
        $transaction->forceFill([
            'match_status' => 'ignored',
            'ignore_reason' => $reason,
            'matched_by' => $actorId,
            'matched_at' => now(),
        ])->save();

        return $transaction->fresh('matches');
    }
    
    public function unmatch(ReconciliationStatement $statement, StatementTransaction $transaction): StatementTransaction
    {
        if ($statement->status === 'reconciled') {
            throw new InvalidArgumentException('A reconciled statement cannot be changed. Reopen it first.');
        }
        if ($transaction->statement_id !== $statement->id) {
            throw new InvalidArgumentException('The transaction does not belong to this statement.');
        }

        DB::transaction(function () use ($transaction): void {
            $transaction = StatementTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $transaction->matches()->delete();
            $transaction->forceFill([
                'match_status' => 'unmatched',
                'ignore_reason' => null,
                'matched_by' => null,
                'matched_at' => null,
            ])->save();
        });

        return $transaction->fresh('matches');
    }

    public function createAndMatch(
        ReconciliationStatement $statement,
        StatementTransaction $transaction,
        array $movementData,
        ?int $actorId,
    ): StatementTransaction {
        if ($statement->status === 'reconciled') {
            throw new InvalidArgumentException('A reconciled statement cannot be changed. Reopen it first.');
        }
        if ($transaction->statement_id !== $statement->id) {
            throw new InvalidArgumentException('The transaction does not belong to this statement.');
        }

        return DB::transaction(function () use ($statement, $transaction, $movementData, $actorId): StatementTransaction {
            $isCredit = bccomp((string) $transaction->credit, '0.00', 2) === 1;
            $amount = $isCredit ? (string) $transaction->credit : (string) $transaction->debit;
            $direction = $isCredit ? 'in' : 'out';

            $payload = [
                'payment_source_id' => $statement->payment_source_id,
                'transaction_date' => $transaction->transaction_date->toDateString(),
                'direction' => $direction,
                'transaction_type' => $movementData['transaction_type'] ?? 'bank_fee',
                'offset_account_id' => (int) $movementData['offset_account_id'],
                'amount' => $amount,
                'reference' => ! empty($movementData['reference']) ? $movementData['reference'] : $transaction->external_reference,
                'description' => ! empty($movementData['description']) ? $movementData['description'] : ($transaction->description ?: 'Bank transaction adjustment'),
                'counterparty' => $movementData['counterparty'] ?? null,
            ];

            $cashService = app(CashMovementService::class);
            $movement = $cashService->create($payload, $actorId ?? 1);

            return $this->match(
                $statement,
                $transaction,
                $movement->journal_entry_id,
                null,
                $amount,
                $actorId,
            );
        });
    }

    public function candidates(
        ReconciliationStatement $statement,
        StatementTransaction $transaction,
        ?string $search = null,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        if ($transaction->statement_id !== $statement->id) {
            throw new InvalidArgumentException('The transaction does not belong to this statement.');
        }

        $amount = bccomp((string) $transaction->credit, '0.00', 2) === 1
            ? (string) $transaction->credit
            : (string) $transaction->debit;

        $from = $fromDate ?: $transaction->transaction_date->copy()->subDays(30)->toDateString();
        $to = $toDate ?: $transaction->transaction_date->copy()->addDays(30)->toDateString();

        $paymentsQuery = Payment::query()
            ->where('payment_source_id', $statement->payment_source_id)
            ->whereBetween('date_disbursed', [$from, $to]);

        if ($search) {
            $paymentsQuery->where(function ($q) use ($search) {
                $q->where('payment_no', 'like', "%{$search}%")
                  ->orWhere('external_reference', 'like', "%{$search}%")
                  ->orWhere('payee_name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        } else {
            $paymentsQuery->where('amount', $amount);
        }

        $journalsQuery = JournalEntry::query()
            ->whereBetween('posting_date', [$from, $to]);

        if ($search) {
            $journalsQuery->where(function ($q) use ($search) {
                $q->where('entry_no', 'like', "%{$search}%")
                  ->orWhere('source_ref', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        } else {
            $journalsQuery->where(function ($query) use ($amount) {
                $query->where('total_debit', $amount)->orWhere('total_credit', $amount);
            });
        }

        return [
            'payments' => $paymentsQuery->limit(20)->get(['id', 'payment_no', 'external_reference', 'payee_name', 'description', 'amount', 'date_disbursed']),
            'journals' => $journalsQuery->limit(20)->get(['id', 'entry_no', 'source_ref', 'description', 'total_debit', 'total_credit', 'posting_date']),
        ];
    }

    public function listStatements(PaymentSource $source): array
    {
        $statements = ReconciliationStatement::where('payment_source_id', $source->id)
            ->with(['paymentSource', 'transactions'])
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->get();

        return $statements->map(function ($st) {
            return $this->summary($st);
        })->toArray();
    }

    public function prefill(PaymentSource $source): array
    {
        $latest = ReconciliationStatement::where('payment_source_id', $source->id)
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first();

        if (! $latest) {
            return [
                'has_previous' => false,
                'opening_balance' => '0.00',
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
            ];
        }

        return [
            'has_previous' => true,
            'last_statement_id' => $latest->id,
            'last_status' => $latest->status,
            'opening_balance' => (string) $latest->closing_balance,
            'period_start' => $latest->period_end->copy()->addDay()->toDateString(),
            'period_end' => $latest->period_end->copy()->addDay()->endOfMonth()->toDateString(),
        ];
    }

    public function summary(ReconciliationStatement $statement): array
    {
        $statement->loadMissing('paymentSource', 'transactions');
        $debits = $statement->transactions->sum(fn ($row) => (float) $row->debit);
        $credits = $statement->transactions->sum(fn ($row) => (float) $row->credit);
        $expected = (float) $statement->opening_balance + $credits - $debits;
        $difference = round((float) $statement->closing_balance - $expected, 2);
        $unresolved = $statement->transactions->where('match_status', 'unmatched')->count();

        $ledgerMovement = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', $statement->paymentSource->gl_account_id)
            ->whereIn('je.status', ['posted', 'reversed'])
            ->whereBetween('je.posting_date', [$statement->period_start->toDateString(), $statement->period_end->toDateString()])
            ->selectRaw("COALESCE(SUM(CASE WHEN jl.entry_type = 'credit' THEN jl.base_amount ELSE -jl.base_amount END), 0) as movement")
            ->value('movement');
        $ledgerExpected = round((float) $statement->opening_balance + (float) $ledgerMovement, 2);
        $ledgerDifference = round((float) $statement->closing_balance - $ledgerExpected, 2);

        return [
            'statement_id' => $statement->id,
            'account' => $statement->paymentSource->only(['id', 'code', 'name', 'type', 'currency']),
            'period_start' => $statement->period_start->toDateString(),
            'period_end' => $statement->period_end->toDateString(),
            'opening_balance' => round((float) $statement->opening_balance, 2),
            'closing_balance' => round((float) $statement->closing_balance, 2),
            'statement_credits' => round($credits, 2),
            'statement_debits' => round($debits, 2),
            'expected_closing_balance' => round($expected, 2),
            'statement_difference' => $difference,
            'ledger_movement' => round((float) $ledgerMovement, 2),
            'ledger_expected_closing_balance' => $ledgerExpected,
            'ledger_difference' => $ledgerDifference,
            'transaction_count' => $statement->transactions->count(),
            'unmatched_count' => $unresolved,
            'can_reconcile' => abs($difference) < 0.01 && abs($ledgerDifference) < 0.01 && $unresolved === 0,
            'status' => $statement->status,
        ];
    }

    public function reconcile(ReconciliationStatement $statement, ?int $actorId): ReconciliationStatement
    {
        $summary = $this->summary($statement);
        if (! $summary['can_reconcile']) {
            throw new InvalidArgumentException('Resolve unmatched transactions and the statement balance difference before reconciling.');
        }

        $statement->forceFill([
            'status' => 'reconciled',
            'reconciled_by' => $actorId,
            'reconciled_at' => now(),
        ])->save();

        return $statement->fresh('paymentSource');
    }

    public function reopen(ReconciliationStatement $statement, string $reason): ReconciliationStatement
    {
        if ($statement->status !== 'reconciled') {
            throw new InvalidArgumentException('Only a reconciled statement can be reopened.');
        }

        $statement->forceFill([
            'status' => 'reopened',
            'reopen_reason' => $reason,
            'reconciled_by' => null,
            'reconciled_at' => null,
        ])->save();

        return $statement->fresh('paymentSource');
    }

    private function normalise(string $value): string
    {
        return match (strtolower(trim($value))) {
            'transaction date', 'posting date' => 'date',
            'ref', 'reference number', 'transaction reference' => 'reference',
            'narration', 'details', 'memo' => 'description',
            'dr', 'withdrawal', 'withdrawals' => 'debit',
            'cr', 'deposit', 'deposits' => 'credit',
            'running balance', 'closing balance' => 'balance',
            default => strtolower(trim($value)),
        };
    }

    private function decimal(mixed $value): string
    {
        $clean = str_replace([',', 'KES', ' '], '', (string) $value);
        return number_format((float) ($clean ?: 0), 2, '.', '');
    }
}
