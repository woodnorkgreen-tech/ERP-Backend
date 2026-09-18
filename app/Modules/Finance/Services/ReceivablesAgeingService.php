<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\ProjectInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which issued invoices are still owed, and for how long.
 *
 * Uses ProjectInvoice::scopeWithVerifiedPaidAmount() — the same fixed,
 * filtered payment sum EnquiryController::projectInvoices() now uses — so a
 * `pending` or `reversed` receipt cannot make an outstanding invoice look
 * settled here either. Money is bcmath throughout (2 decimal places), never
 * float.
 *
 * Bucket boundaries are the standard five: not yet due, then 1-30/31-60/
 * 61-90/90+ days overdue. A not-yet-due invoice is deliberately its own
 * bucket rather than folded into "1-30 overdue" — it is not overdue at all.
 */
class ReceivablesAgeingService
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

    /**
     * One row per invoice still owed, oldest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function outstandingRows(Carbon $asOf): Collection
    {
        return ProjectInvoice::query()
            ->whereNotIn('status', ['draft', 'void'])
            ->whereNotNull('due_date')
            ->with(['enquiry:id,job_number,title,client_id', 'enquiry.client:id,full_name,company_name'])
            ->withVerifiedPaidAmount()
            ->get()
            ->map(function (ProjectInvoice $invoice) use ($asOf) {
                $total = number_format((float) $invoice->total_amount, 2, '.', '');
                $paid = number_format((float) ($invoice->paid_amount ?? 0), 2, '.', '');
                $balance = bcsub($total, $paid, 2);
                $dueDate = Carbon::parse($invoice->due_date)->startOfDay();
                $daysOverdue = $dueDate->lt($asOf) ? (int) $dueDate->diffInDays($asOf) : 0;

                return [
                    'balance' => $balance,
                    'days_overdue' => $daysOverdue,
                    'bucket' => $this->bucketFor($daysOverdue),
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'project_enquiry_id' => $invoice->project_enquiry_id,
                    'job_number' => $invoice->enquiry?->job_number,
                    'client_name' => $invoice->enquiry?->client?->company_name
                        ?: $invoice->enquiry?->client?->full_name,
                    'due_date' => $dueDate->toDateString(),
                    'total_amount' => $total,
                    'paid_amount' => $paid,
                ];
            })
            ->filter(fn (array $row) => bccomp($row['balance'], '0.00', 2) === 1)
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
