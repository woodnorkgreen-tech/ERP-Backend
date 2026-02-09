<?php

namespace App\Modules\Finance\PettyCash\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PettyCashTopUp extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'petty_cash_top_ups';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'amount',
        'previous_balance',
        'date_topped_up',
        'payment_method',
        'transaction_code',
        'description',
        'created_by',
        'is_archived',
        'archived_at',
        'archived_by',
        'requisition_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'previous_balance' => 'decimal:2',
            'date_topped_up' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Get the user who created this top-up.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the requisition this top-up was created for.
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PettyCashRequisition::class, 'requisition_id');
    }

    /**
     * Get all disbursements associated with this top-up.
     */
    public function disbursements(): HasMany
    {
        return $this->hasMany(PettyCashDisbursement::class, 'top_up_id');
    }

    /**
     * Get only active disbursements associated with this top-up.
     */
    public function activeDisbursements(): HasMany
    {
        return $this->hasMany(PettyCashDisbursement::class, 'top_up_id')
                    ->where('status', 'active');
    }

    /**
     * Calculate the remaining balance for this top-up.
     */
    public function getRemainingBalanceAttribute(): float
    {
        $totalDisbursed = $this->activeDisbursements()->sum('amount');
        return (float) ($this->amount - $totalDisbursed);
    }

    /**
     * Check if this top-up has been fully disbursed.
     */
    public function getIsFullyDisbursedAttribute(): bool
    {
        return $this->remaining_balance <= 0;
    }

    /**
     * Scope to filter top-ups by payment method.
     */
    public function scopeByPaymentMethod($query, string $paymentMethod)
    {
        return $query->where('payment_method', $paymentMethod);
    }

    /**
     * Scope to filter top-ups by date range.
     */
    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date_topped_up', [$startDate, $endDate]);
    }

    /**
     * Scope to filter top-ups by creator.
     */
    public function scopeByCreator($query, int $userId)
    {
        return $query->where('created_by', $userId);
    }

    /**
     * Scope to get top-ups with available balance.
     */
    public function scopeWithAvailableBalance($query)
    {
        return $query->whereHas('disbursements', function ($subQuery) {
            $subQuery->selectRaw('SUM(amount) as total_disbursed')
                     ->where('status', 'active')
                     ->groupBy('top_up_id')
                     ->havingRaw('total_disbursed < petty_cash_top_ups.amount');
        })->orWhereDoesntHave('disbursements');
    }

    /**
     * Scope to order by most recent first.
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Scope to filter not archived transactions.
     */
    public function scopeNotArchived($query)
    {
        return $query->where('is_archived', false);
    }

    /**
     * Scope to filter archived transactions.
     */
    public function scopeArchived($query)
    {
        return $query->where('is_archived', true);
    }

    /**
     * Boot the model and set up event listeners.
     */
    protected static function boot()
    {
        parent::boot();

        // Update balance when top-up is created
        static::created(function ($topUp) {
            if (!$topUp->is_archived) {
                $topUp->updateBalance('add', (float) $topUp->amount);
            }
        });

        // Update balance when top-up is updated (amount or archived changes)
        static::updating(function ($topUp) {
            $oldAmount = (float) $topUp->getOriginal('amount');
            $newAmount = (float) $topUp->amount;
            
            $oldArchived = (bool) $topUp->getOriginal('is_archived');
            $newArchived = (bool) $topUp->is_archived;

            $wasEffective = !$oldArchived;
            $isEffective = !$newArchived;

            if ($wasEffective && $isEffective) {
                // Both were not archived, just adjust the difference
                $difference = $newAmount - $oldAmount;
                if ($difference !== 0.0) {
                    $topUp->updateBalance('add', $difference);
                }
            } elseif ($wasEffective && !$isEffective) {
                // Was not archived but now it is
                // Subtract the old amount from balance
                $topUp->updateBalance('subtract', $oldAmount);
            } elseif (!$wasEffective && $isEffective) {
                // Was archived but now it isn't
                // Add the new amount to balance
                $topUp->updateBalance('add', $newAmount);
            }
        });

        // Update balance when top-up is deleted
        static::deleted(function ($topUp) {
            if (!$topUp->is_archived) {
                $topUp->updateBalance('subtract', (float) $topUp->amount);
            }
        });
    }

    /**
     * Update the petty cash balance.
     */
    private function updateBalance(string $operation, float $amount)
    {
        // current() automatically calls recalculateBalance()
        $balance = PettyCashBalance::current();
        
        $balance->last_transaction_id = $this->id;
        $balance->last_transaction_type = 'top_up';
        $balance->save();
    }
}