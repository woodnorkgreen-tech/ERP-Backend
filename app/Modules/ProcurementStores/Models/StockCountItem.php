<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

class StockCountItem extends Model
{
    protected $fillable = ['stock_count_id', 'material_id', 'entry_method', 'material_updated_at_snapshot', 'system_quantity', 'counted_quantity', 'variance_quantity', 'opening_unit_cost', 'location_bin', 'variance_reason', 'adjustment_log_id'];
    protected $casts = [
        'material_updated_at_snapshot' => 'datetime',
        'system_quantity' => 'decimal:6',
        'counted_quantity' => 'decimal:6',
        'variance_quantity' => 'decimal:6',
        'opening_unit_cost' => 'decimal:4',
    ];
    public function stockCount() { return $this->belongsTo(StockCount::class); }
    public function material() { return $this->belongsTo(\App\Modules\MaterialsLibrary\Models\LibraryMaterial::class); }
    public function adjustmentLog() { return $this->belongsTo(InventoryLog::class, 'adjustment_log_id'); }
}
