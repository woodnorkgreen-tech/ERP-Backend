<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'requisition_id',
        'po_number',
        'date',
        'supplier_id',
        'due_date',
        'delivery_address',
        'description',
        'total_amount',
        'status',
        'user_id',
        'submitted_at',
        'approved_at',
        'approved_by',
    ];

    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function requisition()
    {
        return $this->belongsTo(Requisition::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy()
    {
        return $this->belongsTo('App\Models\User', 'user_id')->with('employee');
    }

    public function approvedBy()
    {
        return $this->belongsTo('App\Models\User', 'approved_by')->with('employee');
    }

    // CHANGED: invoices() -> bills() and Invoice::class -> Bill::class
    public function bills()
    {
        return $this->hasMany(Bill::class);
    }

    public function submitForApproval()
    {
        $this->update([
            'status' => 'pending_approval',
            'submitted_at' => now()
        ]);
    }

    public function approve($userId)
    {
        $this->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $userId
        ]);
    }
public function goodsReceiptNote()
{
    return $this->hasOne(GoodsReceiptNote::class);
}
    public static function generatePONumber()
    {
        $year = date('Y');
        $prefix = "PO-{$year}-";

        $lastPO = self::where('po_number', 'like', "{$prefix}%")
            ->orderBy('po_number', 'desc')
            ->first();

        if ($lastPO) {
            $lastNumber = (int) substr($lastPO->po_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}