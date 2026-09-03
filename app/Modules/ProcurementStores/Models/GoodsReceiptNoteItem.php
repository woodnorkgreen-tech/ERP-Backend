<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

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
        'store_status',
        'unit_price',
        'confirmed_by',
        'confirmed_at',
    ];

    protected $casts = [
        'accepted' => 'boolean',
        'material_id' => 'integer',
        'ordered_quantity' => 'decimal:6',
        'received_quantity' => 'decimal:6',
        'stock_quantity' => 'decimal:6',
        'receipt_unit_cost' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'confirmed_at' => 'datetime',
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

    /**
     * Who on the Stores side confirmed this item into inventory.
     */
    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    // Access material through: $grnItem->purchaseOrderItem->material,
    // or directly via material_id once Stores has confirmed it.
}
