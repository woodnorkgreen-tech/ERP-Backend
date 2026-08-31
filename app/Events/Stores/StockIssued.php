<?php

namespace App\Events\Stores;

use App\Modules\ProcurementStores\Models\InventoryLog;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly InventoryLog $inventoryLog
    ) {}
}
