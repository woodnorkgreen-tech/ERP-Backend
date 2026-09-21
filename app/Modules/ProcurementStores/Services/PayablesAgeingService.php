<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\ProcurementStores\Models\Bill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which supplier bills are still owed, and for how long.
 *
 * Unlike the receivables side, a bill's `balance` and `status` are already
 * maintained on every payment by Bill::updatePaymentStatus() — no balance
 * needs recomputing here, and the same `whereIn('status', [...])->
 * where('balance', '>', 0)` filter BillController::getPendingBills() already
 * uses is reused verbatim, so this report and that screen can never
 * disagree about which bills are open. Money is bcmath throughout (2
 * decimal places), never float.
 *
 * Bucket boundaries mirror ReceivablesAgeingService's — not yet due, then
 * 1-30/31-60/61-90/90+ days overdue — defined locally rather than shared
 * across the Finance/ProcurementStores module boundary for five lines.
 */
class PayablesAgeingService
{
    private const BUCKETS = [
        ['id' => 'current', 'label' => 'Current (not yet due)'],
        ['id' => '1_30', 'label' => '1-30 days overdue'],
        ['id' => '31_60', 'label' => '31-60 days overdue'],
        ['id' => '61_90', 'label' => '61-90 days overdue'],
        ['id' => '90_plus', 'label' => 'Over 90 days overdue'],
    ];

    /** @return array<string, mixed> */
    public function summary(?string $asOf = null, ?string $activeBucket = null): array
    {
        $asOfDate = $asOf ? Carbon::parse($asOf)->startOfDay() : now()->startOfDay();

        $rows = $this->outstandingRows($asOfDate);

        $buckets = collect(self::BUCKETS)->map(function (array $bucket) use ($rows, $activeBucket) {
            $matching = $rows->where('bucket', $bucket['id']);

            return [
                'id' => $bucket['id'],
                'label' => $bucket['label'],
                'count' => $matching->count(),
                'value' => $this->sum($matching),
                'active' => $activeBucket === $bucket['id'],
            ];
        })->values()->all();

        $filteredRows = $activeBucket ? $rows->where('bucket', $activeBucket) : $rows;

        return [
            'as_of' => $asOfDate->toDateString(),
            'buckets' => $buckets,
            'rows' => $filteredRows->values()->all(),
            'totals' => [
                'count' => $rows->count(),
                'value' => $this->sum($rows),
            ],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function outstandingRows(Carbon $asOf): Collection
    {
        return Bill::query()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->where('balance', '>', 0)
            ->whereNotNull('due_date')
            ->with('supplier:id,supplier_name')
            ->get()
            ->map(function (Bill $bill) use ($asOf) {
                $balance = number_format((float) $bill->balance, 2, '.', '');
                $dueDate = Carbon::parse($bill->due_date)->startOfDay();
                $daysOverdue = $dueDate->lt($asOf) ? (int) $dueDate->diffInDays($asOf) : 0;

                return [
                    'balance' => $balance,
                    'days_overdue' => $daysOverdue,
                    'bucket' => $this->bucketFor($daysOverdue),
                    'bill_id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'supplier_id' => $bill->supplier_id,
                    'supplier_name' => $bill->supplier?->supplier_name,
                    'due_date' => $dueDate->toDateString(),
                    'amount' => number_format((float) $bill->amount, 2, '.', ''),
                    'paid_amount' => number_format((float) $bill->paid_amount, 2, '.', ''),
                ];
            })
            ->sortByDesc('days_overdue')
            ->values();
    }

    private function bucketFor(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue === 0 => 'current',
            $daysOverdue <= 30 => '1_30',
            $daysOverdue <= 60 => '31_60',
            $daysOverdue <= 90 => '61_90',
            default => '90_plus',
        };
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function sum(Collection $rows): string
    {
        return $rows->reduce(fn (string $carry, array $row) => bcadd($carry, $row['balance'], 2), '0.00');
    }
}
