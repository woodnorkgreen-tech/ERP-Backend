<?php

namespace App\Modules\ProcurementStores\Models;
use App\Models\User;

use Illuminate\Database\Eloquent\Model;

class BillPayment extends Model
{
    protected $fillable = [
        'payment_code',
        'bill_id',
        'amount_paid',
        'payment_date',
        'payment_method_id',
        'notes',
        'user_id'
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount_paid' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($payment) {
            $payment->payment_code = self::generatePaymentCode();
        });

        static::created(function ($payment) {
            $payment->bill->updatePaymentStatus();
        });
    }

    public static function generatePaymentCode()
    {
        $lastPayment = self::orderBy('id', 'desc')->first();
        $number = $lastPayment ? intval(substr($lastPayment->payment_code, 4)) + 1 : 1;
        return 'PAY-' . str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
