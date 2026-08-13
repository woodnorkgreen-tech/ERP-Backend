<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    protected $table = 'journal_entries';

    protected $fillable = [
        'entry_no',
        'posting_date',
        'accounting_period_id',
        'cost_line_id',
        'spend_voucher_id',
        'source_type',
        'source_id',
        'source_ref',
        'description',
        'total_debit',
        'total_credit',
        'status',
        'reversal_of_id',
        'created_by',
        'posted_at',
    ];

    protected $casts = [
        'posting_date' => 'date',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'posted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id');
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function costLine(): BelongsTo
    {
        return $this->belongsTo(CostLine::class, 'cost_line_id');
    }

    public function spendVoucher(): BelongsTo
    {
        return $this->belongsTo(SpendVoucher::class, 'spend_voucher_id');
    }

    public function isBalanced(): bool
    {
        return bccomp((string) $this->total_debit, (string) $this->total_credit, 2) === 0;
    }
}
