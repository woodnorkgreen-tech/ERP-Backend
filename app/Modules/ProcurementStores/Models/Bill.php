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
        'user_id',
        'supplier_invoice_number',
        'verified_by',
        'verified_at',
        'verification_basis',
        'verification_fingerprint',
        'verification_notes',
        'net_amount',
        'vat_amount',
        'wht_amount',
        'vat_treatment_id',
        'wht_category_id',
        'etims_invoice_no',
        'supplier_pin',
        'tax_point_date',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'verified_at' => 'datetime',
        'net_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'wht_amount' => 'decimal:2',
        'tax_point_date' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($bill) {
            $bill->balance = $bill->amount;
            $bill->paid_amount = 0;
        });

        /*
         * net + VAT = gross, always.
         *
         * Held here rather than trusted to callers because three things read
         * the split — the three-way match, the ledger legs and the VAT return —
         * and a row where they disagree would let an invoice be matched on one
         * value, posted on another and claimed on a third. A bill created
         * outside the controller (a fixture, an import, a future path) gets the
         * honest default of "no VAT stated" rather than a null net.
         */
        static::saving(function ($bill) {
            $gross = (float) $bill->amount;
            $vat = (float) ($bill->vat_amount ?? 0);

            if ($vat > $gross) {
                $vat = $gross;
                $bill->vat_amount = $vat;
            }

            $bill->net_amount = round($gross - $vat, 2);
        });

        static::updating(function ($bill) {
            $bill->withdrawVerificationOnChange();
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

    /**
     * What the supplier is actually owed: the invoice less anything withheld.
     *
     * Withholding is retained and paid to KRA instead, so it is never part of
     * the balance a supplier can be paid. Rows predating tax capture carry
     * `wht_amount` 0 and are unaffected.
     */
    public function payableAmount(): string
    {
        return bcsub(
            number_format((float) $this->amount, 2, '.', ''),
            number_format((float) ($this->wht_amount ?? 0), 2, '.', ''),
            2,
        );
    }

    /** The VAT-exclusive value of the invoice — what the three-way match compares. */
    public function netAmount(): string
    {
        return number_format((float) ($this->net_amount ?? $this->amount), 2, '.', '');
    }

    public function updatePaymentStatus()
    {
        $this->paid_amount = $this->payments()->sum('amount_paid');
        $this->balance = $this->payableAmount() - $this->paid_amount;
        
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

    public function vatTreatment()
    {
        return $this->belongsTo(\App\Modules\Finance\Models\VatTreatment::class, 'vat_treatment_id');
    }

    public function whtCategory()
    {
        return $this->belongsTo(\App\Modules\Finance\Models\WhtCategory::class, 'wht_category_id');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /*
     * Any edit to the invoice's own figures withdraws the verification: a
     * sign-off is a statement about a particular amount and reference, and the
     * moment either changes the statement is about something else. Accounts
     * re-checks and re-signs. The fingerprint in PurchaseOrderWorkflow catches
     * the same drift coming from the order or the receipt side.
     */
    public function withdrawVerificationOnChange(): void
    {
        if ($this->isDirty(['amount', 'supplier_invoice_number', 'supplier_id', 'purchase_order_id'])) {
            $this->verified_at = null;
            $this->verified_by = null;
            $this->verification_basis = null;
            $this->verification_fingerprint = null;
        }
    }
}