<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read model for one custody invariant: funding received = consumed + remaining. */
class FundCustodyService
{
    public function overview(Carbon $start, Carbon $end): array
    {
        $topUps = PettyCashTopUp::query()->with('creator')
            ->orderByDesc('date_topped_up')->orderByDesc('id')->get();
        $consumption = $this->consumptionByTopUp();

        $batches = $topUps->map(function (PettyCashTopUp $topUp) use ($consumption) {
            $used = round((float) ($consumption[$topUp->id] ?? 0), 2);
            $received = round((float) $topUp->amount, 2);
            $remaining = round($received - $used, 2);

            return [
                'id' => $topUp->id,
                'reference' => 'PCF-'.str_pad((string) $topUp->id, 6, '0', STR_PAD_LEFT),
                'date' => $topUp->date_topped_up?->toDateString(),
                'source' => $topUp->payment_method,
                'transaction_code' => $topUp->transaction_code,
                'description' => $topUp->description,
                'custodian' => $topUp->creator?->name,
                'received' => $received,
                'consumed' => $used,
                'remaining' => $remaining,
                'utilization_percentage' => $received > 0 ? round(($used / $received) * 100, 1) : 0,
                'age_days' => $topUp->date_topped_up?->diffInDays(now()) ?? 0,
                'state' => $remaining <= 0 ? 'exhausted' : ($used > 0 ? 'in_use' : 'unspent'),
            ];
        })->values();

        // Archiving is a presentation state, never a financial reversal.
        $period = PettyCashDisbursement::query()->active()
            ->whereBetween('date_disbursed', [$start->toDateString(), $end->toDateString()]);
        $periodSpent = (float) (clone $period)->sum(DB::raw('amount + COALESCE(transaction_cost, 0)'));
        $periodCount = (clone $period)->count();
        $periodDays = max(1, $start->diffInDays($end) + 1);
        $averageDailySpend = round($periodSpent / $periodDays, 2);
        $currentBalance = (float) PettyCashBalance::current()->current_balance;
        $unallocated = round($currentBalance - (float) $batches->sum('remaining'), 2);
        $approvedCommitments = (float) PettyCashRequisition::query()->where('status', 'approved')->sum('total_amount');
        $pendingRequests = (float) PettyCashRequisition::query()->where('status', 'pending')->sum('total_amount');

        return [
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'summary' => [
                'funds_received_all_time' => round((float) $topUps->sum('amount'), 2),
                'funds_consumed_all_time' => round((float) $batches->sum('consumed'), 2),
                'funds_remaining' => round((float) $batches->sum('remaining'), 2),
                'ledger_balance' => round($currentBalance, 2),
                'reconciliation_difference' => $unallocated,
                'approved_commitments' => round($approvedCommitments, 2),
                'pending_requests' => round($pendingRequests, 2),
                'spendable_after_commitments' => round($currentBalance - $approvedCommitments, 2),
                'period_spend' => round($periodSpent, 2),
                'period_payments' => $periodCount,
                'average_payment' => $periodCount ? round($periodSpent / $periodCount, 2) : 0,
                'average_daily_spend' => $averageDailySpend,
                'estimated_runway_days' => $averageDailySpend > 0 ? round($currentBalance / $averageDailySpend, 1) : null,
            ],
            'batches' => $batches,
            'daily' => $this->timeline($start, $end, 'day'),
            'weekly' => $this->timeline($start->copy()->subWeeks(11)->startOfWeek(), $end, 'week'),
            'monthly' => $this->timeline($start->copy()->subMonths(11)->startOfMonth(), $end, 'month'),
            'attention' => $batches->filter(fn ($batch) =>
                ($batch['state'] === 'unspent' && $batch['age_days'] > 14)
                || $batch['remaining'] < 0
            )->values(),
            'largest_payments' => (clone $period)->orderByRaw('(amount + COALESCE(transaction_cost, 0)) desc')->limit(5)
                ->get(['id', 'receiver', 'description', 'date_disbursed', 'amount', 'transaction_cost'])
                ->map(fn ($payment) => [
                    'id' => $payment->id,
                    'receiver' => $payment->receiver,
                    'description' => $payment->description,
                    'date' => $payment->date_disbursed?->toDateString(),
                    'total' => round((float) $payment->amount + (float) $payment->transaction_cost, 2),
                ])->values(),
        ];
    }

    public function topUp(int $id): array
    {
        $topUp = PettyCashTopUp::query()->with('creator')->findOrFail($id);
        $payments = $this->paymentSlices()->where('top_up_id', $id)->sortByDesc('date')->values();
        $consumed = round((float) $payments->sum('total'), 2);

        return [
            'id' => $topUp->id,
            'reference' => 'PCF-'.str_pad((string) $topUp->id, 6, '0', STR_PAD_LEFT),
            'received' => (float) $topUp->amount,
            'consumed' => $consumed,
            'remaining' => round((float) $topUp->amount - $consumed, 2),
            'payments' => $payments,
        ];
    }

    private function consumptionByTopUp(): Collection
    {
        return $this->paymentSlices()->groupBy('top_up_id')
            ->map(fn (Collection $rows) => round((float) $rows->sum('total'), 2));
    }

    /** Normalize legacy direct links and split allocations into the same atomic funding slice. */
    private function paymentSlices(): Collection
    {
        $allocated = DB::table('petty_cash_disbursement_allocations as a')
            ->join('petty_cash_disbursements as d', 'd.id', '=', 'a.disbursement_id')
            ->where('d.status', 'active')
            ->selectRaw("a.top_up_id, d.id as disbursement_id, d.date_disbursed as date, d.receiver, d.description, d.classification, d.project_name, d.requisition_id, a.amount, a.transaction_cost, (a.amount + COALESCE(a.transaction_cost, 0)) as total")
            ->get();

        $direct = DB::table('petty_cash_disbursements as d')
            ->where('d.status', 'active')->whereNotNull('d.top_up_id')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('petty_cash_disbursement_allocations as a')->whereColumn('a.disbursement_id', 'd.id'))
            ->selectRaw("d.top_up_id, d.id as disbursement_id, d.date_disbursed as date, d.receiver, d.description, d.classification, d.project_name, d.requisition_id, d.amount, COALESCE(d.transaction_cost, 0) as transaction_cost, (d.amount + COALESCE(d.transaction_cost, 0)) as total")
            ->get();

        return $allocated->concat($direct)->map(fn ($row) => (array) $row);
    }

    private function timeline(Carbon $start, Carbon $end, string $grain): array
    {
        $rows = PettyCashDisbursement::query()->active()
            ->whereBetween('date_disbursed', [$start->toDateString(), $end->toDateString()])->get();

        $grouped = $rows->groupBy(function ($row) use ($grain) {
            $date = Carbon::parse($row->date_disbursed);
            return match ($grain) {
                'week' => $date->startOfWeek()->toDateString(),
                'month' => $date->format('Y-m'),
                default => $date->toDateString(),
            };
        });

        $cursorStart = match ($grain) {
            'week' => $start->copy()->startOfWeek(),
            'month' => $start->copy()->startOfMonth(),
            default => $start->copy()->startOfDay(),
        };
        $interval = match ($grain) {'week' => '1 week', 'month' => '1 month', default => '1 day'};

        return collect(CarbonPeriod::create($cursorStart, $interval, $end))->map(function (Carbon $date) use ($grain, $grouped) {
            $label = $grain === 'month' ? $date->format('Y-m') : $date->toDateString();
            $group = $grouped->get($label, collect());
            return [
                'period' => $label,
                'amount' => round((float) $group->sum(fn ($row) => (float) $row->amount + (float) $row->transaction_cost), 2),
                'payments' => $group->count(),
            ];
        })->values()->all();
    }
}
