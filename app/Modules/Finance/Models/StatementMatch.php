<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatementMatch extends Model
{
    protected $table = 'finance_statement_matches';

    protected $fillable = [
        'statement_transaction_id', 'journal_entry_id', 'payment_id',
        'amount', 'match_type', 'matched_by',
    ];

    protected $casts = ['amount' => 'decimal:2'];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(StatementTransaction::class, 'statement_transaction_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}