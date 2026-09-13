<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    protected $table = 'finance_cash_movements';

    protected $fillable = [
        'payment_source_id', 'transaction_date', 'direction', 'transaction_type',
        'reference', 'description', 'counterparty', 'amount', 'offset_account_id',
        'journal_entry_id', 'status', 'created_by', 'voided_by', 'voided_at',
        'void_reason',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
        'voided_at' => 'datetime',
    ];

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class);
    }

    public function offsetAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'offset_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}