<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryLot extends Model
{
    protected $fillable = ['material_id', 'lot_number', 'expiry_date', 'status', 'warehouse_code', 'location_bin', 'quantity_on_hand', 'quantity_reserved'];
    protected $casts = ['expiry_date' => 'date', 'quantity_on_hand' => 'decimal:4', 'quantity_reserved' => 'decimal:4'];

    public function getAvailableAttribute(): float
    {
        return (float) $this->quantity_on_hand - (float) $this->quantity_reserved;
    }
}
