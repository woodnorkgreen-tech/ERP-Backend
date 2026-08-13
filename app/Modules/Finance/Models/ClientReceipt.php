<?php

namespace App\Modules\Finance\Models;

use App\Models\EnquiryPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientReceipt extends Model
{
    protected $fillable = [
        'payment_source_id', 'received_amount', 'payment_date', 'payment_method',
        'transaction_reference', 'evidence_path', 'recorded_by',
    ];

    protected $casts = ['received_amount' => 'decimal:2', 'payment_date' => 'date'];

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(EnquiryPayment::class, 'client_receipt_id');
    }
}
