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
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string,string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'previous_balance' => 'decimal:2',
        'date_topped_up' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'is_archived' => 'boolean',
        'archived_at' => 'datetime',
    ];

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
     * Get allocations that reference this top-up.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(\App\Modules\Finance\PettyCash\Models\PettyCashDisbursementAllocation::class, 'top_up_id');
    }

    /**
     * Calculate the remaining balance for this top-up.
     */
    public function getRemainingBalanceAttribute(): float
    {
        // Sum disbursements that are directly tied to this top-up and that have no allocations
        $directDisbursed = (float) \Illuminate\Support\Facades\DB::table('petty_cash_disbursements as d')
            ->where('d.top_up_id', $this->id)
            ->where('d.status', 'active')
            ->whereNotExists(function ($query) {
                $query->select(\Illuminate\Support\Facades\DB::raw(1))
                      ->from('petty_cash_disbursement_allocations as a')
                      ->whereRaw('a.disbursement_id = d.id');
            })
            ->sum(\Illuminate\Support\Facades\DB::raw('d.amount + COALESCE(d.transaction_cost, 0)'));

        // Sum allocations that reference this top-up
        $allocationsSum = (float) \Illuminate\Support\Facades\DB::table('petty_cash_disbursement_allocations')
            ->where('top_up_id', $this->id)
            ->sum(\Illuminate\Support\Facades\DB::raw('amount + COALESCE(transaction_cost, 0)'));

        $totalDisbursed = $directDisbursed + $allocationsSum;

        return (float) bcsub((string)$this->amount, number_format($totalDisbursed, 2, '.', ''), 2);
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
            $subQuery->selectRaw('SUM(amount + COALESCE(transaction_cost, 0)) as total_disbursed')
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

}
