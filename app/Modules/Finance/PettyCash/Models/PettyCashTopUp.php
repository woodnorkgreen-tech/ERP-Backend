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
        'payment_method',
        'transaction_code',
        'description',
        'created_by',
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
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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
        return $query->whereBetween('created_at', [$startDate, $endDate]);
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
     * Boot the model and set up event listeners.
     */
    protected static function boot()
    {
        parent::boot();

        // Update balance when top-up is created
        static::created(function ($topUp) {
            $balance = PettyCashBalance::current();
            $balance->addTopUp($topUp->amount, $topUp->id);
        });
    }
}