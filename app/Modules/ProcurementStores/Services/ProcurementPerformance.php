<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\Requisition;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * How long the buying chain actually takes, and which suppliers keep their word.
 *
 * Nothing measured either. Every timestamp needed was already being written —
 * `requisitions.submitted_at` / `approved_at`, `purchase_orders.submitted_at` /
 * `approved_at`, the goods-receipt date, `bills.verified_at`, the payment date —
 * and no code read any of them as a duration. So "procurement is slow" was an
 * opinion nobody could locate, and a supplier's lead time lived in whichever
 * buyer had dealt with them before.
 *
 * Supplier lead time is DERIVED here rather than stored on the supplier record.
 * A typed-in lead time is a claim; this is what the last N orders actually did,
 * and it cannot go stale or be optimistic. It is also why phase 3's
 * replenishment planner can eventually answer *when* to order — it has no lead
 * time of its own, and this is where one comes from.
 *
 * Read-only. Nothing here writes, and no workflow depends on it.
 */
class ProcurementPerformance
{
    /**
     * The buying chain as a sequence of waits, each owned by somebody.
     *
     * Ordered as the work happens, so the row with the largest median is the
     * answer to "where does the time go" without anyone having to interpret it.
     *
     * @return array<string, mixed>
     */
    public function stages(?string $from = null, ?string $to = null): array
    {
        $requisitions = Requisition::query()
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->get(['id', 'created_at', 'submitted_at', 'approved_at', 'status']);

        $orders = PurchaseOrder::query()
            ->with([
                'goodsReceiptNotes:id,purchase_order_id,date',
                'bills:id,purchase_order_id,bill_date,created_at,verified_at',
                'bills.payments:id,bill_id,payment_date',
            ])
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->get(['id', 'created_at', 'submitted_at', 'approved_at', 'due_date', 'status']);

        $stages = [
            $this->stage(
                'requisition_raised_to_submitted', 'Requisition sat in draft', 'Requester',
                $requisitions->map(fn ($r) => $this->days($r->created_at, $r->submitted_at)),
            ),
            $this->stage(
                'requisition_approval', 'Waiting for requisition approval', 'Approver',
                $requisitions->map(fn ($r) => $this->days($r->submitted_at, $r->approved_at)),
            ),
            $this->stage(
                'order_approval', 'Waiting for purchase order approval', 'Approver',
                $orders->map(fn ($o) => $this->days($o->submitted_at, $o->approved_at)),
            ),
            $this->stage(
                'order_to_first_delivery', 'Supplier delivering', 'Supplier',
                $orders->map(fn ($o) => $this->days($o->approved_at, $this->firstReceiptDate($o))),
            ),
            $this->stage(
                'delivery_to_invoice', 'Waiting for the supplier invoice', 'Supplier / Accounts',
                $orders->map(fn ($o) => $this->days($this->firstReceiptDate($o), $o->bills->min('created_at'))),
            ),
            $this->stage(
                'invoice_verification', 'Waiting for invoice verification', 'Accounts',
                $orders->flatMap(fn ($o) => $o->bills->map(fn ($b) => $this->days($b->created_at, $b->verified_at))),
            ),
            $this->stage(
                'verified_to_payment', 'Verified, waiting to be paid', 'Accounts',
                $orders->flatMap(fn ($o) => $o->bills->map(
                    fn ($b) => $this->days($b->verified_at, $b->payments->min('payment_date'))
                )),
            ),
        ];

        $measured = collect($stages)->where('completed', '>', 0);

        return [
            'stages' => $stages,
            'summary' => [
                'requisitions' => $requisitions->count(),
                'orders' => $orders->count(),
                // The end-to-end figure is the sum of the medians rather than a
                // median of end-to-end times: most orders in any window are
                // still mid-chain, and waiting for them all to finish before
                // reporting anything would mean reporting nothing.
                'indicative_days_end_to_end' => round((float) $measured->sum('median_days'), 1),
                'slowest_stage' => $measured->sortByDesc('median_days')->first()['key'] ?? null,
            ],
        ];
    }

    /**
     * Lead time and reliability per supplier, from what they actually did.
     *
     * `due_date` is the date the order asked for, so on-time is measured against
     * the promise on the order rather than against a target invented here.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function suppliers(?string $from = null, ?string $to = null): Collection
    {
        return PurchaseOrder::query()
            ->with(['supplier:id,supplier_name', 'goodsReceiptNotes:id,purchase_order_id,date'])
            ->whereNotNull('supplier_id')
            ->whereNotIn('status', ['cancelled'])
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
            ->get(['id', 'supplier_id', 'date', 'due_date', 'approved_at', 'status'])
            ->groupBy('supplier_id')
            ->map(function (Collection $orders, $supplierId) {
                $delivered = $orders->filter(fn ($o) => $this->firstReceiptDate($o) !== null);

                $leadTimes = $delivered->map(fn ($o) => $this->days($o->date, $this->firstReceiptDate($o)))
                    ->filter(fn ($days) => $days !== null);

                // Judged only on orders that arrived: an order still open is not
                // yet late in the sense of a broken promise, and counting it as
                // a miss would punish a supplier for an order placed yesterday.
                $onTime = $delivered->filter(function ($order) {
                    $received = $this->firstReceiptDate($order);

                    return $order->due_date && $received && $received->lte($order->due_date);
                });

                $lateDays = $delivered
                    ->map(fn ($o) => $o->due_date ? $this->days($o->due_date, $this->firstReceiptDate($o)) : null)
                    ->filter(fn ($days) => $days !== null && $days > 0);

                $open = $orders->filter(fn ($o) => $this->firstReceiptDate($o) === null
                    && ! in_array($o->status, ['cancelled'], true));

                return [
                    'supplier_id' => (int) $supplierId,
                    'supplier_name' => $orders->first()->supplier?->supplier_name ?? 'Supplier',
                    'orders' => $orders->count(),
                    'delivered' => $delivered->count(),
                    'avg_lead_time_days' => $leadTimes->isNotEmpty()
                        ? round((float) $leadTimes->avg(), 1) : null,
                    'median_lead_time_days' => $this->median($leadTimes),
                    'on_time' => $onTime->count(),
                    'late' => $delivered->count() - $onTime->count(),
                    'on_time_rate' => $delivered->isNotEmpty()
                        ? round($onTime->count() / $delivered->count() * 100, 1) : null,
                    'avg_days_late' => $lateDays->isNotEmpty() ? round((float) $lateDays->avg(), 1) : null,
                    'open_orders' => $open->count(),
                    'overdue_orders' => $open->filter(
                        fn ($o) => $o->due_date && $o->due_date->isPast()
                    )->count(),
                ];
            })
            ->sortByDesc('orders')
            ->values();
    }

    /**
     * One wait, summarised.
     *
     * `completed` is reported beside the averages because a median over three
     * orders is not the same claim as a median over three hundred, and a reader
     * cannot tell the difference from the number alone.
     *
     * @param  Collection<int, float|null>  $durations
     * @return array<string, mixed>
     */
    private function stage(string $key, string $label, string $owner, Collection $durations): array
    {
        $measured = $durations->filter(fn ($days) => $days !== null)->values();

        return [
            'key' => $key,
            'label' => $label,
            'owner' => $owner,
            'completed' => $measured->count(),
            'avg_days' => $measured->isNotEmpty() ? round((float) $measured->avg(), 1) : null,
            'median_days' => $this->median($measured),
            'worst_days' => $measured->isNotEmpty() ? round((float) $measured->max(), 1) : null,
        ];
    }

    /** @param Collection<int, float> $values */
    private function median(Collection $values): ?float
    {
        if ($values->isEmpty()) {
            return null;
        }

        $sorted = $values->sort()->values();
        $count = $sorted->count();
        $middle = intdiv($count, 2);

        return round((float) ($count % 2
            ? $sorted[$middle]
            : ($sorted[$middle - 1] + $sorted[$middle]) / 2), 1);
    }

    /** The first time anything on this order actually turned up. */
    private function firstReceiptDate(PurchaseOrder $order): ?CarbonInterface
    {
        $earliest = $order->goodsReceiptNotes->pluck('date')->filter()->min();

        return $earliest ? \Illuminate\Support\Carbon::parse($earliest) : null;
    }

    /**
     * Whole days between two moments, or null when either end never happened.
     *
     * Never negative: a receipt dated before its order is a data-entry error,
     * and letting it subtract from an average would quietly flatter the figure.
     */
    private function days(mixed $start, mixed $end): ?float
    {
        if (! $start || ! $end) {
            return null;
        }

        $startAt = \Illuminate\Support\Carbon::parse($start);
        $endAt = \Illuminate\Support\Carbon::parse($end);

        return max(0.0, round($startAt->floatDiffInDays($endAt), 1));
    }
}
