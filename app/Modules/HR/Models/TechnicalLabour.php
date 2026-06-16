<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TechnicalLabour extends Model
{
    use HasFactory;

    protected $table = 'technical_labours';

    protected $fillable = [
        'full_name',
        'phone',
        'email',
        'id_number',
        'specialization',
        'day_rate',
        'status',
        'rating',
        'notes'
    ];

    protected $casts = [
        'day_rate' => 'decimal:2',
        'rating' => 'decimal:2',
    ];

    protected $appends = [
        'name',
        'ot_balance',
    ];

    public function getNameAttribute(): string
    {
        return $this->full_name;
    }

    /**
     * Get overtime entries for the technical labour.
     */
    public function otEntries()
    {
        return $this->hasMany(OTEntry::class);
    }

    /**
     * Get ledger entries for the technical labour.
     */
    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * Get the current OT balance from the latest ledger entry.
     */
    public function getOtBalanceAttribute(): float
    {
        try {
            $latest = $this->ledgerEntries()
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->first();
            return $latest ? (float) $latest->balance_after : 0.0;
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * Scope to filter active technical labour.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
