<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'purchase_order_id',
        'material_id',
        'custom_description',
        'quantity',
        'unit_price',
        'total',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * FIXED: Changed from 'Material' to 'LibraryMaterial'
     */
    public function material()
    {
        return $this->belongsTo('App\Modules\MaterialsLibrary\Models\LibraryMaterial', 'material_id');
    }
}