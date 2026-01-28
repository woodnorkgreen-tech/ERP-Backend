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
        'condition',
        'accepted',
    ];

    protected $casts = [
        'accepted' => 'boolean',
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

    // No need for direct material relationship
    // Access material through: $grnItem->purchaseOrderItem->material
}