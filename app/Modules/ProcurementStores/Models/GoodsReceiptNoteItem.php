<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptNoteItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'goods_receipt_note_id',
        'purchase_order_item_id',
        'material_id',
        'ordered_quantity',
        'received_quantity',
        'entered_uom_id',
        'stock_quantity',
        'receipt_unit_cost',
        'stock_status',
        'inventory_log_id',
        'condition',
        'accepted',
    ];

    protected $casts = [
        'accepted' => 'boolean',
        'material_id' => 'integer',
        'ordered_quantity' => 'decimal:6',
        'received_quantity' => 'decimal:6',
        'stock_quantity' => 'decimal:6',
        'receipt_unit_cost' => 'decimal:4',
    ];

    /**
     * Get the GRN that owns this item.
     */
    public function goodsReceiptNote()
    {
        return $this->belongsTo(GoodsReceiptNote::class);
    }

    /**
     * Get the purchase order item.
     */
    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function inspection()
    {
        return $this->hasOne(GoodsReceiptInspection::class);
    }

    // No need for direct material relationship
    // Access material through: $grnItem->purchaseOrderItem->material
}
