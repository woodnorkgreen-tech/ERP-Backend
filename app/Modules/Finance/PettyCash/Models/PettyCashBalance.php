<?php

namespace App\Modules\Finance\PettyCash\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Exception;

class PettyCashBalance extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'petty_cash_balances';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'current_balance',
        'last_transaction_id',
        'last_transaction_type',
        'updated_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:2',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the current balance instance (singleton pattern).
     */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'current_balance' => 0.00,
            'last_transaction_id' => null,
            'last_transaction_type' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Add a top-up amount to the balance.
     */
    public function addTopUp(float $amount, int $topUpId): self
    {
        if ($amount <= 0) {
            throw new Exception('Top-up amount must be greater than zero.');
        }

        $this->current_balance += $amount;
        $this->last_transaction_id = $topUpId;
        $this->last_transaction_type = 'top_up';
        $this->updated_at = now();
        $this->save();

        return $this;
    }

    /**
     * Subtract a disbursement amount from the balance.
     */
    public function subtractDisbursement(float $amount, int $disbursementId): self
    {
        if ($amount <= 0) {
            throw new Exception('Disbursement amount must be greater than zero.');
        }

        if ($this->current_balance < $amount) {
            throw new Exception('Insufficient balance for this disbursement.');
        }

        $this->current_balance -= $amount;
        $this->last_transaction_id = $disbursementId;
        $this->last_transaction_type = 'disbursement';
        $this->updated_at = now();
        $this->save();

        return $this;
    }

    /**
     * Get the current balance amount.
     */
    public function getCurrentBalance(): float
    {
        return (float) $this->current_balance;
    }

    /**
     * Check if there's sufficient balance for a disbursement.
     */
    public function hasSufficientBalance(float $amount): bool
    {
        return $this->current_balance >= $amount;
    }

    /**
     * Check if the balance is low (less than a threshold).
     */
    public function isLow(float $threshold = 1000.00): bool
    {
        return $this->current_balance < $threshold;
    }

    /**
     * Check if the balance is critical (less than a critical threshold).
     */
    public function isCritical(float $threshold = 500.00): bool
    {
        return $this->current_balance < $threshold;
    }

    /**
     * Get balance status (normal, low, critical).
     */
    public function getStatusAttribute(): string
    {
        if ($this->isCritical()) {
            return 'critical';
        }
        
        if ($this->isLow()) {
            return 'low';
        }
        
        return 'normal';
    }

    /**
     * Recalculate balance from all transactions.
     * This method can be used to fix any balance discrepancies.
     */
    public function recalculateBalance(): self
    {
        $totalTopUps = PettyCashTopUp::sum('amount');
        $totalDisbursements = PettyCashDisbursement::active()->sum('amount');
        
        $this->current_balance = $totalTopUps - $totalDisbursements;
        $this->updated_at = now();
        $this->save();

        return $this;
    }

    /**
     * Get the last transaction details.
     */
    public function getLastTransactionAttribute(): ?array
    {
        if (!$this->last_transaction_id || !$this->last_transaction_type) {
            return null;
        }

        if ($this->last_transaction_type === 'top_up') {
            $transaction = PettyCashTopUp::find($this->last_transaction_id);
            return $transaction ? [
                'type' => 'top_up',
                'amount' => $transaction->amount,
                'created_at' => $transaction->created_at,
                'description' => $transaction->description,
            ] : null;
        }

        if ($this->last_transaction_type === 'disbursement') {
            $transaction = PettyCashDisbursement::find($this->last_transaction_id);
            return $transaction ? [
                'type' => 'disbursement',
                'amount' => $transaction->amount,
                'created_at' => $transaction->created_at,
                'receiver' => $transaction->receiver,
                'description' => $transaction->description,
            ] : null;
        }

        return null;
    }

    /**
     * Validate balance before saving.
     */
    public function save(array $options = [])
    {
        if ($this->current_balance < 0) {
            throw new Exception('Balance cannot be negative.');
        }

        return parent::save($options);
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Prevent deletion of balance records
        static::deleting(function ($balance) {
            throw new Exception('Balance records cannot be deleted.');
        });

        // Update timestamp on save
        static::saving(function ($balance) {
            $balance->updated_at = now();
        });
    }
}