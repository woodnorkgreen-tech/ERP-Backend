<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

/** The accepted goods-receipt quantity one bill line covers, and its accrued value (Report 76A-3). */
class BillLineReceiptAllocation extends Model
{
    protected $fillable = ['bill_line_id', 'goods_receipt_note_item_id', 'quantity', 'receipt_value', 'released_quantity', 'released_value'];

    protected $casts = ['quantity' => 'decimal:6', 'receipt_value' => 'decimal:2', 'released_quantity' => 'decimal:6', 'released_value' => 'decimal:2'];

    public function billLine()
    {
        return $this->belongsTo(BillLine::class);
    }

    public function goodsReceiptNoteItem()
    {
        return $this->belongsTo(GoodsReceiptNoteItem::class);
    }
}
