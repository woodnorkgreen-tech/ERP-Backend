<?php

namespace App\Modules\ProcurementStores\Models;

use App\Modules\Finance\CostCollector\Models\CostLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoresFinancePosting extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'next_retry_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function inventoryLog(): BelongsTo { return $this->belongsTo(InventoryLog::class); }
    public function costLine(): BelongsTo { return $this->belongsTo(CostLine::class); }
}
