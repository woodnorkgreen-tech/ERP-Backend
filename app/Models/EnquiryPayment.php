<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\ClientReceipt;

class EnquiryPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_enquiry_id',
        'client_receipt_id',
        'amount',
        'payment_date',
        'payment_method',
        'payment_source_id',
        'transaction_reference',
        'evidence_path',
        'recorded_by',
        'notes',
        'status',
        'verified_at',
        'verified_by',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'reversed_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'project_enquiry_id');
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class, 'payment_source_id');
    }

    public function clientReceipt(): BelongsTo
    {
        return $this->belongsTo(ClientReceipt::class, 'client_receipt_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
