<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostingRule extends Model
{
    protected $table = 'posting_rules';

    protected $fillable = [
        'expense_code_id',
        'voucher_type',
        'payment_source_id',
        'debit_account_id',
        'credit_account_id',
        'priority',
        'effective_from',
        'effective_to',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'priority' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function expenseCode(): BelongsTo
    {
        return $this->belongsTo(ExpenseCode::class, 'expense_code_id');
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class, 'payment_source_id');
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'credit_account_id');
    }

    public function scopeActive($query, ?string $date = null)
    {
        $targetDate = $date ?? now()->toDateString();

        return $query->where('is_active', true)
            ->where('effective_from', '<=', $targetDate)
            ->where(function ($q) use ($targetDate) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $targetDate);
            });
    }
}
