<?php

namespace App\Modules\Finance\Support;

use Illuminate\Support\Facades\DB;

/**
 * Issues the ERP's document numbers. One series per business transaction.
 *
 * The number identifies the business event, not how it was settled: a payment
 * is PAY-2026-0147 whether it left Equity or the office float, because the
 * account it left is a field on the record. Anything that needs a new series
 * adds a constant here rather than a second numbering scheme of its own.
 */
final class DocumentNumber
{
    public const PAYMENT = 'PAY';
    public const REQUISITION = 'REQ';
    public const RECEIPT = 'RCT';
    public const JOURNAL = 'JV';
    public const ADVANCE = 'PCA';
    public const RETIREMENT = 'PCR';

    /**
     * Claim the next number in a series.
     *
     * MUST be called inside a transaction: the row is held with FOR UPDATE
     * until commit, which is what stops two concurrent payments taking the same
     * number. Callers in this codebase already open one.
     */
    public static function next(string $prefix, ?string $period = null): string
    {
        $period ??= (string) now()->year;

        DB::table('document_sequences')->insertOrIgnore([
            'prefix' => $prefix,
            'period' => $period,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('document_sequences')
            ->where('prefix', $prefix)->where('period', $period)
            ->lockForUpdate()
            ->first();

        $number = (int) ($row->next_number ?? 1);

        DB::table('document_sequences')
            ->where('id', $row->id)
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return sprintf('%s-%s-%04d', $prefix, $period, $number);
    }
}
