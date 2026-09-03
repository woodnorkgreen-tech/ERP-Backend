<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\ProcurementStores\Jobs\ProcessStoresFinancePosting;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\StoresFinancePosting;

class StoresFinanceOutbox
{
    public function queue(InventoryLog $log, string $postingType): StoresFinancePosting
    {
        $posting = StoresFinancePosting::firstOrCreate(
            ['inventory_log_id' => $log->id, 'posting_type' => $postingType],
            ['status' => 'pending']
        );
        if ($posting->wasRecentlyCreated || $posting->status === 'failed') {
            ProcessStoresFinancePosting::dispatch($posting->id)->afterCommit();
        }
        return $posting;
    }
}
