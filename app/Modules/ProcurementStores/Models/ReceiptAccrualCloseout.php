<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

/** Received value Finance confirmed will never be invoiced, released from the receipt accrual (Report 76A-3). */
class ReceiptAccrualCloseout extends Model
{
    protected $fillable = ['goods_receipt_note_item_id', 'quantity', 'value', 'reason', 'journal_entry_id', 'created_by'];

    protected $casts = ['quantity' => 'decimal:6', 'value' => 'decimal:2'];

    public function goodsReceiptNoteItem()
    {
        return $this->belongsTo(GoodsReceiptNoteItem::class);
    }
}
