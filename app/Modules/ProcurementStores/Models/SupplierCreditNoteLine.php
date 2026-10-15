<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierCreditNoteLine extends Model
{
    protected $fillable = [
        'supplier_credit_note_id', 'bill_line_id', 'quantity', 'net_amount', 'vat_amount', 'receipt_value_released',
        'posted_to_inventory', 'posted_to_variance',
    ];

    protected $casts = [
        'quantity' => 'decimal:6', 'net_amount' => 'decimal:2', 'vat_amount' => 'decimal:2', 'receipt_value_released' => 'decimal:2',
        'posted_to_inventory' => 'decimal:2', 'posted_to_variance' => 'decimal:2',
    ];

    public function billLine()
    {
        return $this->belongsTo(BillLine::class);
    }
}
