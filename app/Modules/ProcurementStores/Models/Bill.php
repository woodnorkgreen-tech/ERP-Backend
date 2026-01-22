<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Bill extends Model
{
    protected $fillable = [
        'bill_number',
        'purchase_order_id',
        'supplier_id',
        'bill_date',
        'due_date',
        'amount',
        'paid_amount',
        'balance',
        'status',
        'notes',
        'user_id'
    ];

    protected $casts = [
        'bill_date' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($bill) {
            $bill->balance = $bill->amount;
            $bill->paid_amount = 0;
        });
    }

    public static function generateBillNumber()
{
    $year = date('Y');
    $prefix = 'BILL-' . $year . '-';
    
    // Get the last bill for the current year
    $lastBill = self::where('bill_number', 'like', $prefix . '%')
                    ->orderBy('id', 'desc')
                    ->first();
    
    if ($lastBill) {
        // Extract the number part after the last dash
        $lastNumber = intval(substr($lastBill->bill_number, strrpos($lastBill->bill_number, '-') + 1));
        $number = $lastNumber + 1;
    } else {
        // First bill of the year
        $number = 1;
    }
    
    return $prefix . str_pad($number, 4, '0', STR_PAD_LEFT);
}

    public function updatePaymentStatus()
    {
        $this->paid_amount = $this->payments()->sum('amount_paid');
        $this->balance = $this->amount - $this->paid_amount;
        
        if ($this->balance <= 0) {
            $this->status = 'paid';
        } elseif ($this->paid_amount > 0) {
            $this->status = 'partial';
        } elseif ($this->due_date < now() && $this->balance > 0) {
            $this->status = 'overdue';
        }
        
        $this->save();
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function payments()
    {
        return $this->hasMany(BillPayment::class);
    }
}