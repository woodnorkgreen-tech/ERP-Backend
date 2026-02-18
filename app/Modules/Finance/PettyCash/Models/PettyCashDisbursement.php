<?php

namespace App\Modules\Finance\PettyCash\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashDisbursement extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'petty_cash_disbursements';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'top_up_id',
        'receiver',
        'account',
        'amount',
        'description',
        'project_name',
        'venue',
        'classification',
        'job_number',
        'payment_method',
        'transaction_code',
        'status',
        'void_reason',
        'created_by',
        'voided_by',
        'voided_at',
        'tax',
        'date_disbursed',
        'is_archived',
        'archived_at',
        'archived_by',
        'requisition_id',
        'transaction_cost',
        'budget_category',
        'created_at',
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
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'date_disbursed' => 'date',
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
            'transaction_cost' => 'decimal:2',
        ];
    }

    /**
     * Get the top-up this disbursement belongs to.
     */
    public function topUp(): BelongsTo
    {
        return $this->belongsTo(PettyCashTopUp::class, 'top_up_id');
    }

    /**
     * Get the user who created this disbursement.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who voided this disbursement.
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * Get the requisition this disbursement was created for.
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PettyCashRequisition::class, 'requisition_id');
    }

    /**
     * Scope to filter active disbursements.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to filter voided disbursements.
     */
    public function scopeVoided($query)
    {
        return $query->where('status', 'voided');
    }

    /**
     * Scope to filter by classification.
     */
    public function scopeByClassification($query, string $classification)
    {
        return $query->where('classification', $classification);
    }

    /**
     * Scope to filter by payment method.
     */
    public function scopeByPaymentMethod($query, string $paymentMethod)
    {
        return $query->where('payment_method', $paymentMethod);
    }

    /**
     * Scope to filter by project name.
     */
    public function scopeByProject($query, string $projectName)
    {
        return $query->where('project_name', 'like', '%' . $projectName . '%');
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope to filter by creator.
     */
    public function scopeByCreator($query, int $userId)
    {
        return $query->where('created_by', $userId);
    }

    /**
     * Scope to search across multiple fields.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('receiver', 'like', '%' . $search . '%')
              ->orWhere('account', 'like', '%' . $search . '%')
              ->orWhere('description', 'like', '%' . $search . '%')
              ->orWhere('project_name', 'like', '%' . $search . '%')
              ->orWhere('job_number', 'like', '%' . $search . '%')
              ->orWhere('transaction_code', 'like', '%' . $search . '%');
        });
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
     * Check if this disbursement is active.
     */
    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if this disbursement is voided.
     */
    public function getIsVoidedAttribute(): bool
    {
        return $this->status === 'voided';
    }

    /**
     * Boot the model and set up event listeners.
     */
    protected static function boot()
    {
        parent::boot();

        // Update balance when disbursement is created
        static::created(function ($disbursement) {
            if ($disbursement->status === 'active' && !$disbursement->is_archived) {
                $disbursement->updateBalance('subtract', (float) $disbursement->amount);
            }
        });

        // Update balance when disbursement is updated (amount, status, or archived changes)
        static::updating(function ($disbursement) {
            $oldAmount = (float) $disbursement->getOriginal('amount');
            $newAmount = (float) $disbursement->amount;
            
            $oldStatus = $disbursement->getOriginal('status');
            $newStatus = $disbursement->status;
            
            $oldArchived = (bool) $disbursement->getOriginal('is_archived');
            $newArchived = (bool) $disbursement->is_archived;

            $wasEffective = ($oldStatus === 'active' && !$oldArchived);
            $isEffective = ($newStatus === 'active' && !$newArchived);

            if ($wasEffective && $isEffective) {
                // Both were active/not archived, just adjust the difference
                $difference = $newAmount - $oldAmount;
                if ($difference !== 0.0) {
                    $disbursement->updateBalance('subtract', $difference);
                }
            } elseif ($wasEffective && !$isEffective) {
                // Was active/effective but now it isn't (voided or archived)
                // Restore the old amount to balance
                $disbursement->updateBalance('add', $oldAmount);
            } elseif (!$wasEffective && $isEffective) {
                // Wasn't active/effective but now it is (reactivated or unarchived)
                // Subtract the new amount from balance
                $disbursement->updateBalance('subtract', $newAmount);
            }
        });

        // Update balance when disbursement is deleted
        static::deleted(function ($disbursement) {
            if ($disbursement->status === 'active' && !$disbursement->is_archived) {
                $disbursement->updateBalance('add', (float) $disbursement->amount);
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
        $balance->last_transaction_type = 'disbursement';
        $balance->save();
    }

    /**
     * Void this disbursement.
     */
    public function void(int $voidedBy, string $reason): bool
    {
        $this->update([
            'status' => 'voided',
            'void_reason' => $reason,
            'voided_by' => $voidedBy,
            'voided_at' => now(),
        ]);

        return true;
    }
}