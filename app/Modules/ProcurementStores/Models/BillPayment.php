<?php

namespace App\Modules\ProcurementStores\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BillPayment extends Model
{
    /**
     * CHANGED: 'notes' -> 'reference_number'
     */
    protected $fillable = [
        'payment_code',
        'bill_id',
        'amount_paid',
        'payment_date',
        'payment_method',
        'payment_source_id',
        'disbursement_id',
        'reference_number', // CHANGED from 'notes'
        'user_id'
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount_paid' => 'decimal:2',
    ];

    /**
     * Boot method to auto-generate payment code and update bill status
     */
    protected static function boot()
    {
        parent::boot();
        
        // Auto-generate payment code if not provided
        static::creating(function ($payment) {
            if (empty($payment->payment_code)) {
                $payment->payment_code = self::generatePaymentCode();
            }

            /*
             * The three-way match is enforced here rather than in each caller
             * so that no payment path — screen, batch run, or petty cash
             * disbursement — can create cash movement against an unverified
             * supplier invoice.
             */
            $bill = $payment->bill ?: Bill::find($payment->bill_id);
            if (! $bill) {
                throw new \RuntimeException('A supplier payment must belong to a bill.');
            }
            app(\App\Modules\ProcurementStores\Services\SupplierPaymentGuard::class)
                ->assertPayable($bill, (string) $payment->amount_paid);
        });

        // Update bill payment status after payment is created
        static::created(function ($payment) {
            $payment->bill->updatePaymentStatus();

            /*
             * The cash leg of the supplier rail, posted here for the same
             * reason the three-way match is enforced here: three paths create a
             * BillPayment — the single-invoice screen, the batch run, and a
             * petty cash disbursement against a linked bill — and a control
             * that lives in one of them is a control none of them has. The
             * posting is a no-op unless the invoice itself posted, so a
             * grandfathered legacy bill settles without relieving a payable it
             * never raised.
             */
            app(\App\Modules\Finance\Services\JournalPostingService::class)
                ->postSupplierPayment($payment);
        });

        // Update bill payment status after payment is deleted
        static::deleted(function ($payment) {
            if ($payment->bill) {
                $payment->bill->updatePaymentStatus();
            }
        });
    }

    /**
     * Generate unique payment code
     */
    public static function generatePaymentCode()
    {
        return \App\Modules\Finance\Support\DocumentNumber::next(
            \App\Modules\Finance\Support\DocumentNumber::PAYMENT,
        );
    }

    /**
     * Get the bill that owns this payment
     */
    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    /** How the money reached the supplier, as a person reads it. */
    public function getPaymentMethodLabelAttribute(): ?string
    {
        return $this->payment_method
            ? \App\Modules\Finance\Support\PaymentMethods::label($this->payment_method)
            : null;
    }

    /**
     * Get the user who created this payment
     */
    public function paymentSource()
    {
        return $this->belongsTo(\App\Modules\Finance\Models\PaymentSource::class);
    }

    public function disbursement()
    {
        return $this->belongsTo(\App\Modules\Finance\Models\Payment::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}