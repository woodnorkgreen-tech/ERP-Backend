<?php

namespace App\Modules\Finance\Governance;

use Illuminate\Support\Facades\DB;

/**
 * How each Finance document is ACTUALLY numbered today (Reports 74 §21, 75).
 *
 * A register of facts read from the code, shown so that nobody has to assume.
 * Only payments draw from `document_sequences`; every other document gets its
 * number another way, and each way is labelled for what it is. Nothing here is a
 * setting: changing how a document is numbered is a policy decision first and a
 * migration second, and this report makes neither.
 */
final class DocumentNumberingRegister
{
    private const SEQUENCE = 'A locked, gap-free counter (document_sequences), one series per year';
    private const LAST_PLUS_ONE = 'Reads the last number used and adds one. No lock: two people saving at the same instant can be given the same number';
    private const ROW_ID = 'Built from the record\'s internal id. Unique, but leaves a gap whenever a draft is abandoned';
    private const DERIVED = 'Derived from the document it posts. Unique by construction; not an independent series';

    /**
     * @return list<array{key: string, document: string, format: string, prefix: ?string, reset: string, mechanism: string, controlled: bool, gap_free: bool, concurrency_safe: bool, sequence_prefix: ?string, status: string}>
     */
    public static function series(): array
    {
        $row = fn (string $key, string $document, string $format, ?string $prefix, string $reset, string $mechanism, bool $controlled, bool $gapFree, bool $safe, ?string $sequence = null) => [
            'key' => $key, 'document' => $document, 'format' => $format, 'prefix' => $prefix, 'reset' => $reset, 'mechanism' => $mechanism,
            'controlled' => $controlled, 'gap_free' => $gapFree, 'concurrency_safe' => $safe, 'sequence_prefix' => $sequence,
            'status' => $controlled ? 'Controlled series. The number it should continue from is not on record.' : 'Numbering policy pending.',
        ];

        return [
            $row('payment', 'Payments (petty cash, vouchers, supplier, payroll, salary advance)', 'PAY-<year>-<0001>', 'PAY', 'Restarts each year', self::SEQUENCE, true, true, true, 'PAY'),
            $row('client_invoice', 'Client invoices', 'INV-<yyyymm>-<000001>', 'INV', 'Never; the month is part of the number', self::ROW_ID, false, false, true),
            $row('payment_voucher', 'Payment vouchers', 'SV-<yyyymmdd>-<0000001>', 'SV', 'Never; the date is part of the number', self::ROW_ID, false, false, true),
            $row('supplier_bill', 'Supplier bills', 'BILL-<year>-<0001>', 'BILL', 'Restarts each year', self::LAST_PLUS_ONE, false, false, false),
            $row('purchase_order', 'Purchase orders', 'PO-<year>-<0001>', 'PO', 'Restarts each year', self::LAST_PLUS_ONE, false, false, false),
            $row('goods_received', 'Goods received notes', 'GRN-<year>-<0001>', 'GRN', 'Restarts each year', self::LAST_PLUS_ONE, false, false, false),
            $row('purchase_requisition', 'Purchase requisitions', 'PR-<year>-<0001>', 'PR', 'Restarts each year', self::LAST_PLUS_ONE, false, false, false),
            $row('petty_cash_requisition', 'Petty cash requisitions', 'PCR-<000001>', 'PCR', 'Never', self::LAST_PLUS_ONE, false, false, false),
            $row('journal_entry', 'Journal entries', 'JE-<source>-<id>, e.g. JE-INV-0000012', 'JE', 'Never', self::DERIVED, false, false, true),
        ];
    }

    /** The register with the live counters beside the one series that has them. */
    public static function report(): array
    {
        $sequences = DB::getSchemaBuilder()->hasTable('document_sequences')
            ? DB::table('document_sequences')->orderBy('prefix')->orderBy('period')->get(['prefix', 'period', 'next_number', 'updated_at']) : collect();

        return [
            'series' => array_map(function (array $series) use ($sequences) {
                $series['counters'] = $series['sequence_prefix'] === null ? []
                    : $sequences->where('prefix', $series['sequence_prefix'])->map(fn ($s) => ['period' => $s->period, 'next_number' => (int) $s->next_number, 'updated_at' => $s->updated_at])->values()->all();

                return $series;
            }, self::series()),
            'unused_series' => ['REQ', 'RCT', 'JV', 'PCA', 'PCR'],
            'note' => 'Only payments use the controlled counter. Five further series are defined in the code and used by nothing. Which documents need an unbroken series, and the number each should continue from, are decisions for Finance and the accountant; none has been made here.',
        ];
    }
}
