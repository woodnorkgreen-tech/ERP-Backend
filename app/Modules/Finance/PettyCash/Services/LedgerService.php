<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use Illuminate\Support\Facades\DB;
use Exception;

class LedgerService
{
    public const BALANCE_ID = 1;

    /**
     * Post a ledger entry atomically and update the cached balance projection.
     */
    public function post(LedgerEntry $entry)
    {
        return DB::transaction(function () use ($entry) {
            $balance = PettyCashBalance::where('id', self::BALANCE_ID)->lockForUpdate()->first();
            if (!$balance) {
                $balance = PettyCashBalance::create(['id' => self::BALANCE_ID, 'current_balance' => 0.00]);
            }

            $current = number_format($balance->current_balance, 2, '.', '');

            if ($entry->type === 'credit') {
                $new = bcadd($current, $entry->amount, 2);
            } else {
                $new = bcsub($current, $entry->amount, 2);
            }

            $balance->current_balance = $new;
            $balance->last_transaction_id = $entry->sourceId;
            $balance->last_transaction_type = $entry->sourceType;
            $balance->updated_at = now();
            $balance->save();

            DB::table('petty_cash_ledger_entries')->insert($entry->toRow($new));

            return $balance;
        });
    }

    /**
     * Rebuild the balance from the ledger entries (credits - debits).
     */
    public function rebuildFromLedger(): PettyCashBalance
    {
        $credits = DB::table('petty_cash_ledger_entries')->where('type', 'credit')->sum('amount');
        $debits = DB::table('petty_cash_ledger_entries')->where('type', 'debit')->sum('amount');

        $new = bcsub((string)$credits, (string)$debits, 2);

        $balance = PettyCashBalance::firstOrCreate(['id' => self::BALANCE_ID], ['current_balance' => 0.00]);
        $balance->current_balance = $new;
        $balance->updated_at = now();
        $balance->save();

        return $balance;
    }
}
