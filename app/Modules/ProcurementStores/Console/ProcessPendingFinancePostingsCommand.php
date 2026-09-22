<?php

namespace App\Modules\ProcurementStores\Console;

use App\Modules\ProcurementStores\Models\StoresFinancePosting;
use App\Modules\ProcurementStores\Services\StoresFinanceOutbox;
use Illuminate\Console\Command;
use Throwable;

class ProcessPendingFinancePostingsCommand extends Command
{
    protected $signature = 'stores:process-finance-postings {--limit=1000}';
    protected $description = 'Synchronously process pending Stores-to-Finance outbox records';

    public function handle(StoresFinanceOutbox $outbox): int
    {
        $processed = 0;
        $failed = 0;
        $limit = max(1, (int) $this->option('limit'));

        StoresFinancePosting::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (StoresFinancePosting $posting) use ($outbox, &$processed, &$failed) {
                try {
                    $outbox->processSynchronously($posting);
                    $processed++;
                } catch (Throwable $exception) {
                    $failed++;
                    $this->error("Posting {$posting->id} failed: {$exception->getMessage()}");
                }
            });

        $this->info("Processed {$processed} pending Stores Finance posting(s); {$failed} failed.");

        // A data exception is retained as a failed outbox record for Finance to
        // resolve; it must not roll back an otherwise successful deployment.
        return self::SUCCESS;
    }
}
