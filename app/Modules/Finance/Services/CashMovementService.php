<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\PaymentSource;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CashMovementService
{
    public function create(array $data, int $actorId): CashMovement
    {
        return DB::transaction(function () use ($data, $actorId): CashMovement {
            $source = PaymentSource::with('glAccount')->findOrFail($data['payment_source_id']);
            $offset = ChartOfAccount::postable()->find($data['offset_account_id']);

            if (! $source->gl_account_id || ! $offset) {
                throw new InvalidArgumentException('The account and offset account must both be active, postable ledger accounts.');
            }

            $amount = number_format((float) $data['amount'], 2, '.', '');
            if ((float) $amount <= 0) {
                throw new InvalidArgumentException('The movement amount must be greater than zero.');
            }

            $movement = CashMovement::create([
                ...$data,
                'amount' => $amount,
                'status' => 'posted',
                'created_by' => $actorId,
            ]);

            $entry = app(JournalPostingService::class)->postBalancedEntry(
                entryNo: 'JE-CASH-' . str_pad((string) $movement->id, 7, '0', STR_PAD_LEFT),
                postingDate: $movement->transaction_date->toDateString(),
                sourceType: CashMovement::class,
                sourceId: $movement->id,
                sourceRef: $movement->reference,
                description: $movement->description,
                legs: $movement->direction === 'in'
                    ? [
                        ['account_id' => $source->gl_account_id, 'entry_type' => 'debit', 'amount' => $amount, 'description' => $movement->description],
                        ['account_id' => $offset->id, 'entry_type' => 'credit', 'amount' => $amount, 'description' => $movement->description],
                    ]
                    : [
                        ['account_id' => $offset->id, 'entry_type' => 'debit', 'amount' => $amount, 'description' => $movement->description],
                        ['account_id' => $source->gl_account_id, 'entry_type' => 'credit', 'amount' => $amount, 'description' => $movement->description],
                    ],
                createdBy: $actorId,
            );

            $movement->forceFill(['journal_entry_id' => $entry->id])->save();

            return $movement->fresh(['paymentSource', 'offsetAccount', 'journalEntry']);
        });
    }

    public function void(CashMovement $movement, int $actorId, string $reason): CashMovement
    {
        if ($movement->status !== 'posted' || ! $movement->journal_entry_id) {
            throw new InvalidArgumentException('Only a posted cash movement can be voided.');
        }

        return DB::transaction(function () use ($movement, $actorId, $reason): CashMovement {
            $movement = CashMovement::query()->lockForUpdate()->findOrFail($movement->id);
            app(JournalPostingService::class)->reverseEntry(
                $movement->journalEntry,
                $actorId,
                'Cash movement voided: ' . $reason,
            );
            $movement->forceFill([
                'status' => 'voided',
                'voided_by' => $actorId,
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            return $movement->fresh(['paymentSource', 'offsetAccount', 'journalEntry']);
        });
    }
}