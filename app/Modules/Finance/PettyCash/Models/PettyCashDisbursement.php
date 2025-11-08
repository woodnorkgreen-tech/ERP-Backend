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
        'classification',
        'job_number',
        'payment_method',
        'transaction_code',
        'status',
        'void_reason',
        'created_by',
        'voided_by',
        'voided_at',
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
            $disbursement->updateBalance('subtract');
        });

        // Update balance when disbursement is updated (status changes)
        static::updated(function ($disbursement) {
            if ($disbursement->wasChanged('status')) {
                if ($disbursement->status === 'voided' && $disbursement->getOriginal('status') === 'active') {
                    // Disbursement was voided, add amount back to balance
                    $disbursement->updateBalance('add');
                } elseif ($disbursement->status === 'active' && $disbursement->getOriginal('status') === 'voided') {
                    // Disbursement was reactivated, subtract amount from balance
                    $disbursement->updateBalance('subtract');
                }
            }
        });
    }

    /**
     * Update the petty cash balance.
     */
    private function updateBalance(string $operation)
    {
        $balance = PettyCashBalance::firstOrCreate(['id' => 1]);
        
        if ($operation === 'add') {
            $balance->current_balance += $this->amount;
        } else {
            $balance->current_balance -= $this->amount;
        }
        
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