<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A supplier's credit against a verified bill, or that bill's reversal
 * (Report 76A-3). A document of its own with its own journal: the bill and the
 * bill's journal are never edited or deleted.
 *
 * This is a correction of what the supplier CHARGED. It moves no stock. Goods
 * physically sent back are a Stores return, which is a different document.
 */
class SupplierCreditNote extends Model
{
    public const KIND_CREDIT = 'credit';
    public const KIND_REVERSAL = 'reversal';

    protected $fillable = [
        'credit_number', 'bill_id', 'supplier_id', 'kind', 'reversal_of_bill_id', 'supplier_reference', 'credit_date', 'reason',
        'net_amount', 'vat_amount', 'wht_amount', 'amount', 'receipt_value_released', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'credit_date' => 'date', 'net_amount' => 'decimal:2', 'vat_amount' => 'decimal:2', 'wht_amount' => 'decimal:2',
        'amount' => 'decimal:2', 'receipt_value_released' => 'decimal:2',
    ];

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function lines()
    {
        return $this->hasMany(SupplierCreditNoteLine::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function journalEntry()
    {
        return $this->belongsTo(\App\Modules\Finance\Models\JournalEntry::class);
    }
}
