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
        // Report 76A-3: `unit_price` is NET of VAT; these keep the supplier's
        // quote as given and what it normalised to. NULL on earlier lines.
        'quoted_unit_price',
        'quoted_price_includes_tax',
        'vat_treatment_id',
        'tax_rate_percent',
        'net_amount',
        'tax_amount',
        'gross_amount',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
        'quoted_unit_price' => 'decimal:4',
        'quoted_price_includes_tax' => 'boolean',
        'net_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'gross_amount' => 'decimal:2',
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
