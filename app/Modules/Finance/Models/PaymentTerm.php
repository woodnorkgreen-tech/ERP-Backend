<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A configurable payment-term template (W1-7).
 *
 * `days` null means "custom" — the invoice's own due date is picked by hand
 * rather than computed. No row here is ever assumed to reflect confirmed WNG
 * commercial policy; Finance owns what actually exists in this table.
 */
class PaymentTerm extends Model
{
    protected $fillable = ['name', 'days', 'is_custom', 'is_active', 'is_default', 'created_by'];

    protected $casts = [
        'days' => 'integer',
        'is_custom' => 'boolean',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** The due date this term produces from an invoice date. Null for a custom term — the caller must supply one directly. */
    public function dueDateFrom(string $invoiceDate): ?string
    {
        if ($this->is_custom || $this->days === null) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($invoiceDate)->addDays($this->days)->toDateString();
    }
}
