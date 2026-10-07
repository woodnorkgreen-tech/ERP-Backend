<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\Models\FinanceEventPosting;
use App\Modules\Finance\Services\FinanceEventPoster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What this installation's queue and cost postings actually look like.
 *
 * READ-ONLY unless `--retry` is passed. Without it this starts nothing,
 * processes nothing and changes nothing: it prints the queue driver, what is
 * sitting in `jobs` and `failed_jobs`, and which cost postings need attention.
 * It exists because the answer to "is a worker draining the queue?" could not
 * be obtained for production from the repository (Reports 48 and 76A), and an
 * operator on the host can obtain it with one command.
 *
 * It never touches the `jobs` table, with or without `--retry`. Old queued
 * jobs are not processed, retried or deleted here.
 */
class PostingHealthCommand extends Command
{
    protected $signature = 'finance:posting-health
        {--retry : Re-run cost postings that failed or were left unfinished (idempotent). Does not touch the jobs table.}
        {--limit=500 : Most postings to re-run with --retry}';

    protected $description = 'Read-only report of the queue driver, waiting jobs and cost postings needing attention';

    public function handle(FinanceEventPoster $poster): int
    {
        $driver = (string) config('queue.default');
        $this->line('Queue driver (QUEUE_CONNECTION): '.$driver);
        $this->line('Config cached: '.(app()->configurationIsCached() ? 'yes — the cached value is in force, not .env' : 'no'));

        if (Schema::hasTable('jobs')) {
            $jobs = DB::table('jobs')->selectRaw('COUNT(*) AS n, MIN(created_at) AS oldest, MAX(created_at) AS newest, SUM(reserved_at IS NOT NULL) AS reserved')->first();
            $this->line('Jobs waiting: '.(int) $jobs->n
                .($jobs->n ? ' (oldest '.date('Y-m-d H:i', (int) $jobs->oldest).', newest '.date('Y-m-d H:i', (int) $jobs->newest).', reserved by a worker now: '.(int) $jobs->reserved.')' : ''));

            DB::table('jobs')->selectRaw("queue, JSON_UNQUOTE(JSON_EXTRACT(payload, '$.displayName')) AS job, COUNT(*) AS n")
                ->groupBy('queue', 'job')->orderByDesc('n')->limit(15)->get()
                ->each(fn ($row) => $this->line(sprintf('  %5d  %s  [%s]', $row->n, $row->job, $row->queue)));
        } else {
            $this->line('Jobs waiting: no jobs table');
        }

        $this->line('Failed jobs: '.(Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 'no failed_jobs table'));

        if ($driver !== 'sync') {
            $this->warn('An asynchronous driver needs a running worker. Jobs that only grow, with none reserved and none failed, mean nothing is draining the queue.');
        }
        $this->line('Cost-chain postings (commitments, accruals, payment costs, reversals, budget lines) do NOT use the queue; see below.');

        if (! Schema::hasTable('finance_event_postings')) {
            $this->warn('finance_event_postings does not exist yet — run migrations.');

            return self::SUCCESS;
        }

        $byStatus = FinanceEventPosting::query()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $this->line('Cost postings: '.($byStatus->isEmpty() ? 'none recorded yet' : $byStatus->map(fn ($n, $s) => "{$s} {$n}")->implode(', ')));

        $attention = FinanceEventPosting::query()->needingAttention()->orderBy('id')->limit(50)->get();
        $this->line('Needing attention (failed, or unfinished for over '.FinanceEventPosting::STALE_AFTER_MINUTES.' minutes): '
            .FinanceEventPosting::query()->needingAttention()->count());
        foreach ($attention as $posting) {
            $this->line(sprintf('  #%d %s subject %d — %s after %d attempt(s): %s',
                $posting->id, $posting->posting_type, $posting->subject_id, $posting->status, $posting->attempts,
                $posting->last_error ?: 'never ran'));
        }

        if ($this->option('retry')) {
            $tally = $poster->sweep(max(1, (int) $this->option('limit')));
            $this->info("Re-ran {$tally['examined']} posting(s): {$tally['posted']} posted, {$tally['failed']} still failing.");
        }

        return self::SUCCESS;
    }
}
