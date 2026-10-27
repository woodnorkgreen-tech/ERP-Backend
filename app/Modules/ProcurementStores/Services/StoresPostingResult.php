<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\ProcurementStores\Models\StoresFinancePosting;
use Illuminate\Support\Facades\DB;

/**
 * Report 79D — what Finance did with the stock movements just posted.
 *
 * A stock movement commits first; its project cost and journal are posted
 * straight after commit through the outbox (StoresFinanceOutbox). That posting
 * can fail — no unit value, no approved account — while the stock has
 * genuinely left the shelf, and the screen used to say "charged to the
 * project" either way. This reads the outbox row of each movement once the
 * request's transaction has committed, so the response says what actually
 * happened: posted (with the cost line and journal), failed (with the reason),
 * still pending, not applicable, or no project cost at all.
 *
 * Read only. A failed posting stays on the Finance posting issues list, where
 * Retry / Resolve valuation repost it without moving stock again.
 */
class StoresPostingResult
{
    public const POSTED = 'posted';
    public const FAILED = 'failed';
    public const PENDING = 'pending';
    public const NOT_APPLICABLE = 'not_applicable';
    public const NONE = 'none';

    /**
     * @param  array<int, int>  $logIds  inventory_logs ids, in line order
     * @return array{lines: array<int, array>, summary: array<string, int>}
     */
    public function forLogs(array $logIds): array
    {
        $logIds = array_values(array_map('intval', $logIds));
        $postings = StoresFinancePosting::query()
            ->with('costLine:id,ref,journal_entry_id')
            ->whereIn('inventory_log_id', $logIds ?: [0])
            ->get()
            ->groupBy('inventory_log_id');
        $journalIds = $postings->flatten()->map(fn ($p) => $p->journal_entry_id ?: $p->costLine?->journal_entry_id)->filter()->unique()->values();
        $journals = $journalIds->isEmpty() ? collect() : DB::table('journal_entries')->whereIn('id', $journalIds)->pluck('entry_no', 'id');

        $lines = [];
        $summary = [self::POSTED => 0, self::FAILED => 0, self::PENDING => 0, self::NOT_APPLICABLE => 0, self::NONE => 0];
        foreach ($logIds as $index => $logId) {
            $posting = $postings->get($logId)?->sortByDesc('id')->first();
            $status = match (true) {
                $posting === null => self::NONE,
                $posting->status === 'posted' => self::POSTED,
                $posting->status === 'failed' => self::FAILED,
                $posting->status === 'not_applicable' => self::NOT_APPLICABLE,
                default => self::PENDING,
            };
            $summary[$status]++;
            $journalId = $posting?->journal_entry_id ?: $posting?->costLine?->journal_entry_id;
            $lines[] = array_filter([
                'line' => $index,
                'inventory_log_id' => $logId,
                'status' => $status,
                'posting_id' => $posting?->id,
                'cost_line_ref' => $posting?->costLine?->ref,
                'journal_entry_no' => $journalId ? ($journals[$journalId] ?? null) : null,
                'error' => $status === self::FAILED ? $posting?->last_error : null,
            ], fn ($value) => $value !== null);
        }

        return ['lines' => $lines, 'summary' => $summary];
    }

    /** One sentence for the person, never claiming a cost that was not posted. */
    public function sentence(array $summary): string
    {
        $parts = [];
        if ($summary[self::POSTED] > 0) {
            $parts[] = "{$summary[self::POSTED]} charged to the project";
        }
        if ($summary[self::FAILED] > 0) {
            $parts[] = "{$summary[self::FAILED]} not yet costed — the Finance posting failed and is listed under Finance posting issues";
        }
        if ($summary[self::PENDING] > 0) {
            $parts[] = "{$summary[self::PENDING]} waiting for Finance to post";
        }
        if ($summary[self::NOT_APPLICABLE] > 0) {
            $parts[] = "{$summary[self::NOT_APPLICABLE]} needed no accounting entry";
        }

        return $parts ? ucfirst(implode('; ', $parts)).'.' : '';
    }
}
