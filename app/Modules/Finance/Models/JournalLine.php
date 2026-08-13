<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    protected $table = 'journal_lines';

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'entry_type',
        'amount',
        'currency',
        'fx_rate',
        'base_amount',
        'description',
        'cost_centre_id',
        'activity_id',
        'project_id',
        'project_enquiry_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fx_rate' => 'decimal:8',
        'base_amount' => 'decimal:2',
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
