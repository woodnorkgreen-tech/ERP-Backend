<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Fetch all non-archived top ups
        $topUps = \Illuminate\Support\Facades\DB::table('petty_cash_top_ups')
            ->where('is_archived', false)
            ->get()
            ->map(function ($item) {
                $item->entry_type = 'credit';
                $item->sort_date = $item->created_at;
                return $item;
            });

        // 2. Fetch all active, non-archived disbursements
        $disbursements = \Illuminate\Support\Facades\DB::table('petty_cash_disbursements')
            ->where('status', 'active')
            ->where('is_archived', false)
            ->get()
            ->map(function ($item) {
                $item->entry_type = 'debit';
                $item->sort_date = $item->date_disbursed ?? $item->created_at;
                return $item;
            });

        // 3. Merge and sort chronologically
        $merged = $topUps->concat($disbursements)->sortBy('sort_date');

        // 4. Loop and write to petty_cash_ledger_entries while tracking balance_snapshot
        $currentBalance = 0.00;
        foreach ($merged as $item) {
            if ($item->entry_type === 'credit') {
                $currentBalance += (float)$item->amount;
                $reference = 'TOP-' . str_pad($item->id, 6, '0', STR_PAD_LEFT);
                $metadata = json_encode([
                    'payment_method' => $item->payment_method ?? 'cash',
                    'transaction_code' => $item->transaction_code ?? null,
                    'description' => $item->description ?? 'Top Up',
                    'created_by' => $item->created_by ?? null,
                ]);
            } else {
                $cost = (float)($item->transaction_cost ?? 0);
                $totalDeducted = (float)$item->amount + $cost;
                $currentBalance -= $totalDeducted;
                $reference = 'PCR-' . str_pad($item->id, 6, '0', STR_PAD_LEFT);
                $metadata = json_encode([
                    'receiver' => $item->receiver,
                    'account' => $item->account,
                    'description' => $item->description,
                    'classification' => $item->classification,
                    'payment_method' => $item->payment_method,
                    'transaction_code' => $item->transaction_code,
                    'transaction_cost' => $cost,
                    'budget_category' => $item->budget_category ?? null,
                    'created_by' => $item->created_by ?? null,
                ]);
            }

            \Illuminate\Support\Facades\DB::table('petty_cash_ledger_entries')->insert([
                'reference_number' => $reference,
                'type' => $item->entry_type,
                'amount' => $item->entry_type === 'credit' ? $item->amount : ((float)$item->amount + (float)($item->transaction_cost ?? 0)),
                'balance_snapshot' => $currentBalance,
                'metadata' => $metadata,
                'posted_at' => $item->sort_date,
                'created_at' => $item->created_at ?? now(),
                'updated_at' => $item->updated_at ?? now(),
            ]);
        }

        // 5. Update cached current_balance in petty_cash_balances table
        \Illuminate\Support\Facades\DB::table('petty_cash_balances')->updateOrInsert(
            ['id' => 1],
            [
                'current_balance' => $currentBalance,
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('petty_cash_ledger_entries')->truncate();
        \Illuminate\Support\Facades\DB::table('petty_cash_balances')->where('id', 1)->update(['current_balance' => 0.00]);
    }
};
