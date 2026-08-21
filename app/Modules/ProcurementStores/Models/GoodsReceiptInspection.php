<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

class GoodsReceiptInspection extends Model
{
    protected $fillable = ['goods_receipt_note_item_id', 'inspected_quantity', 'accepted_quantity', 'rejected_quantity', 'quarantined_quantity', 'outcome', 'status', 'findings', 'condition_notes', 'supplier_action', 'supplier_action_due_on', 'supplier_reference', 'inspected_by', 'inspected_at'];
    protected $casts = ['inspected_quantity' => 'decimal:6', 'accepted_quantity' => 'decimal:6', 'rejected_quantity' => 'decimal:6', 'quarantined_quantity' => 'decimal:6', 'supplier_action_due_on' => 'date', 'inspected_at' => 'datetime'];
    public function item() { return $this->belongsTo(GoodsReceiptNoteItem::class, 'goods_receipt_note_item_id'); }
    public function inspector() { return $this->belongsTo(\App\Models\User::class, 'inspected_by'); }
}
