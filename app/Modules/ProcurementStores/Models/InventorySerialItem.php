<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

class InventorySerialItem extends Model
{
    protected $fillable = ['material_id', 'inventory_lot_id', 'tracking_code', 'manufacturer_serial', 'status', 'condition_grade', 'warehouse_code', 'location_bin', 'project_id', 'holder_name'];
}
