<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationStatement extends Model
{
    protected $table = 'finance_reconciliation_statements';

    protected $fillable = [
        'payment_source_id', 'period_start', 'period_end', 'opening_balance',
        'closing_balance', 'currency', 'status', 'imported_by', 'reconciled_by',
        'reconciled_at', 'reopen_reason',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'reconciled_at' => 'datetime',
    ];

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(StatementTransaction::class, 'statement_id');
    }
}