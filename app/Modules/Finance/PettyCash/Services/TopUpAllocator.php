<?php

namespace App\Modules\Finance\PettyCash\Services;

class TopUpAllocator
{
    protected $repo;

    public function __construct($repo)
    {
        $this->repo = $repo;
    }

    /**
     * Plan allocation across available top-ups using FIFO (oldest first).
     * Returns array of splits: [ ['top_up_id'=>int, 'amount'=>string, 'transaction_cost'=>string], ... ]
     * Throws Exception if insufficient funds.
     */
    public function plan(float $amount, float $transactionCost = 0.0): array
    {
        $required = bcadd((string)$amount, (string)$transactionCost, 2);
        $amountToAllocate = number_format($amount, 2, '.', '');
        $costToAllocate = number_format($transactionCost, 2, '.', '');

        $topUps = $this->repo->getTopUpsWithAvailableBalance();

        // Order FIFO (oldest first) by date_topped_up then id
        $sorted = $topUps->sortBy(function ($t) {
            return $t->date_topped_up ? $t->date_topped_up->timestamp : 0;
        })->values();

        $availableTotal = '0.00';
        foreach ($sorted as $topUp) {
            $availableTotal = bcadd($availableTotal, $this->availableBalance($topUp), 2);
        }

        if (bccomp($availableTotal, $required, 2) < 0) {
            throw new \Exception('Insufficient funds across all top-ups. Required: ' . $required . ', Available: ' . $availableTotal);
        }

        $remaining = $required;
        $slices = [];

        foreach ($sorted as $topUp) {
            $available = $this->availableBalance($topUp);
            if (bccomp($available, '0.00', 2) <= 0) continue;

            $take = bccomp($available, $remaining, 2) >= 0
                ? $remaining
                : $available;

            $slices[] = [
                'top_up_id' => $topUp->id,
                'total' => number_format($take, 2, '.', ''),
            ];

            $remaining = bcsub($remaining, $take, 2);
            if (bccomp($remaining, '0.00', 2) <= 0) break;
        }

        $allocations = [];
        $amountDistributed = '0.00';
        $costDistributed = '0.00';

        foreach ($slices as $i => $slice) {
            if ($i === count($slices) - 1) {
                $amountShare = bcsub($amountToAllocate, $amountDistributed, 2);
                $costShare = bcsub($costToAllocate, $costDistributed, 2);
            } else {
                $ratio = bcdiv((string)$slice['total'], $required, 6);
                $amountShare = number_format((float)bcmul($ratio, $amountToAllocate, 6), 2, '.', '');
                $costShare = number_format((float)bcmul($ratio, $costToAllocate, 6), 2, '.', '');
                $amountDistributed = bcadd($amountDistributed, $amountShare, 2);
                $costDistributed = bcadd($costDistributed, $costShare, 2);
            }

            $allocations[] = [
                'top_up_id' => $slice['top_up_id'],
                'amount' => $amountShare,
                'transaction_cost' => $costShare,
            ];
        }

        return $allocations;
    }

    private function availableBalance($topUp): string
    {
        if (isset($topUp->calculated_remaining_balance)) {
            return number_format((float)$topUp->calculated_remaining_balance, 2, '.', '');
        }

        return number_format((float)$topUp->remaining_balance, 2, '.', '');
    }
}
