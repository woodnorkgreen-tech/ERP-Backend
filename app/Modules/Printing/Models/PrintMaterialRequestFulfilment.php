<?php

namespace App\Modules\Printing\Models;

use App\Models\User;
use App\Modules\ProcurementStores\Models\InventoryLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintMaterialRequestFulfilment extends Model
{
    protected $fillable = [
        'print_material_request_id', 'inventory_log_id', 'issued_quantity_m',
        'issued_by', 'issued_at', 'notes',
    ];

    protected $casts = ['issued_quantity_m' => 'decimal:3', 'issued_at' => 'datetime'];

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(PrintMaterialRequest::class, 'print_material_request_id');
    }

    public function inventoryLog(): BelongsTo
    {
        return $this->belongsTo(InventoryLog::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
