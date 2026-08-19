<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovementAllocation extends Model
{
    protected $fillable = ['inventory_lot_id', 'inventory_serial_item_id', 'quantity'];
    protected $casts = ['quantity' => 'decimal:4'];
}
