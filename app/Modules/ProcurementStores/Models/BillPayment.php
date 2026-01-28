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
        'payment_method_id',
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
        });

        // Update bill payment status after payment is created
        static::created(function ($payment) {
            $payment->bill->updatePaymentStatus();
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
        $lastPayment = self::orderBy('id', 'desc')->first();
        $number = $lastPayment ? intval(substr($lastPayment->payment_code, 4)) + 1 : 1;
        return 'PAY-' . str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Get the bill that owns this payment
     */
    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    /**
     * Get the payment method
     */
    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * Get the user who created this payment
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}