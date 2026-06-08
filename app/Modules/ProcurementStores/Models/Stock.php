<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;

class Stock extends Model
{
    use SoftDeletes;

    // Tracking mode — board materials are tracked individually; consumables by count
    const TRACK_BY_AREA  = 'individual';
    const TRACK_BY_COUNT = 'count';

    protected $fillable = [
        'material_id',
        'quantity_on_hand',
        'quantity_reserved',
        'min_stock_level',
        'warehouse_code',
        'location_bin',
        'tracking_mode',
    ];

    protected $casts = [
        'quantity_on_hand' => 'decimal:2',
        'quantity_reserved' => 'decimal:2',
        'min_stock_level' => 'decimal:2'
    ];

    /**
     * Link back to the Master Library item
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(LibraryMaterial::class, 'material_id');
    }

    /**
     * Calculate available stock
     */
    public function getAvailableAttribute(): float
    {
        return (float)($this->quantity_on_hand - $this->quantity_reserved);
    }
}
