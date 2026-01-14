<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'po_number',
        'date',
        'supplier_id',
        'due_date',
        'delivery_address',
        'description',
        'total_amount',
        'status',
        'user_id',
    ];

    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
    ];

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
        return $this->belongsTo('App\Models\User', 'user_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
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

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'purchase_order_id',
        'material_id',
        'quantity',
        'unit_price',
        'total',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function material()
    {
        return $this->belongsTo('App\Modules\MaterialsLibrary\Models\Material', 'material_id');
    }
}

class Invoice extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'invoice_number',
        'purchase_order_id',
        'supplier_id',
        'invoice_date',
        'due_date',
        'amount',
        'status',
        'payment_date',
        'payment_method',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'payment_date' => 'date',
    ];

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
        return $this->belongsTo('App\Models\User', 'user_id');
    }

    public static function generateInvoiceNumber()
    {
        $year = date('Y');
        $prefix = "INV-{$year}-";
        
        $lastInvoice = self::where('invoice_number', 'like', "{$prefix}%")
            ->orderBy('invoice_number', 'desc')
            ->first();
        
        if ($lastInvoice) {
            $lastNumber = (int) substr($lastInvoice->invoice_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}