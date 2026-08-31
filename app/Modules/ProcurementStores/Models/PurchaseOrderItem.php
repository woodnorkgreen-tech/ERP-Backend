<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $with = ['uom'];

    protected $connection = 'mysql';

    protected $fillable = [
        'purchase_order_id',
        'requisition_item_id',
        'material_id',
        'custom_description',
        'quantity',
        'uom_id',
        'unit_price',
        'total',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function requisitionItem()
    {
        return $this->belongsTo(RequisitionItem::class);
    }

    public function goodsReceiptNoteItems()
    {
        return $this->hasMany(GoodsReceiptNoteItem::class);
    }

    /**
     * FIXED: Changed from 'Material' to 'LibraryMaterial'
     */
    public function material()
    {
        return $this->belongsTo('App\Modules\MaterialsLibrary\Models\LibraryMaterial', 'material_id');
    }

    public function uom()
    {
        return $this->belongsTo(\App\Modules\MaterialsLibrary\Models\UnitOfMeasure::class, 'uom_id');
    }
}
