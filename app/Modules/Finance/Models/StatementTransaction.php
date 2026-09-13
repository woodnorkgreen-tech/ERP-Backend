<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatementTransaction extends Model
{
    protected $table = 'finance_statement_transactions';

    protected $fillable = [
        'statement_id', 'transaction_date', 'external_reference', 'description',
        'debit', 'credit', 'statement_balance', 'fingerprint', 'match_status',
        'matched_by', 'matched_at', 'metadata',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'statement_balance' => 'decimal:2',
        'matched_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(ReconciliationStatement::class, 'statement_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(StatementMatch::class, 'statement_transaction_id');
    }

    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}