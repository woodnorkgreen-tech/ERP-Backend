<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Requisition extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'requisition_number',
        'date',
        'requested_by_type',
        'project_id',
        'employee_id',
        'department_id',
        'urgency',
        'status',
        'total_amount',
        'submitted_at',
        'approved_at',
        'approved_by',
        'rejection_reason',
        'user_id',
        'job_number'
    ];

    protected $casts = [
        'date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'project_id' => 'integer',      // ADD THIS
        'employee_id' => 'integer',     // ADD THIS
        'department_id' => 'integer',   // ADD THIS
    ];

    public function purchaseOrder()
    {
        return $this->hasOne(PurchaseOrder::class);
    }

    /**
     * A requisition can now produce more than one Purchase Order — one per
     * supplier, since different items on the same requisition can be
     * bought from different suppliers.
     */
    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * How this request was eventually settled, and out of which float.
     *
     * A purchase requisition is four hops from its money — requisition, order,
     * invoice, payment — which is why nothing ever showed it. The payment source
     * has been recorded on `bill_payments` all along; the request simply had no
     * way to reach back to it, so "which purchase requests came out of petty
     * cash?" was unanswerable from the requisition screen.
     *
     * A method rather than a relation, and deliberately not eager-loaded on the
     * list: a four-level join on every row of a paginated index would cost far
     * more than the question is worth there. The detail screen asks for one
     * request and can afford one query.
     *
     * @return \Illuminate\Support\Collection<int, array{source: ?string, type: ?string, amount: string, paid_on: ?string}>
     */
    public function settlements()
    {
        return BillPayment::query()
            ->whereIn('bill_id', Bill::query()
                ->whereIn('purchase_order_id', $this->purchaseOrders()->select('id'))
                ->select('id'))
            ->with('paymentSource:id,code,name,type')
            ->orderBy('payment_date')
            ->get()
            ->map(fn (BillPayment $payment) => [
                'source' => $payment->paymentSource?->name,
                'type' => $payment->paymentSource?->type,
                'amount' => (string) $payment->amount_paid,
                'paid_on' => $payment->payment_date?->toDateString(),
                // Present when the tin paid it, and the link to the cash record.
                'disbursement_id' => $payment->disbursement_id,
            ]);
    }

    public function items()
    {
        return $this->hasMany(RequisitionItem::class);
    }

  public function project()
{
    return $this->belongsTo('App\Models\Project', 'project_id');
}

    /**
     * Direct link to the enquiry (used when requested_by_type = 'project').
     * We store the enquiry ID in project_id, so this resolves the full enquiry details.
     */
    public function projectEnquiry()
    {
        return $this->belongsTo('App\Models\ProjectEnquiry', 'project_id');
    }

    public function employee()
    {
        return $this->belongsTo('App\Modules\HR\Models\Employee', 'employee_id');
    }

    public function department()
    {
        return $this->belongsTo('App\Modules\HR\Models\Department', 'department_id');
    }

    public function createdBy()
    {
        return $this->belongsTo('App\Models\User', 'user_id')->with('employee');
    }

    public function approvedBy()
    {
        return $this->belongsTo('App\Models\User', 'approved_by')->with('employee');
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

    public function reject($userId, $reason)
    {
        $this->update([
            'status' => 'rejected',
            'approved_by' => $userId,
            'rejection_reason' => $reason
        ]);
    }

    public static function generateRequisitionNumber()
    {
        $year = date('Y');
        $prefix = "PR-{$year}-";

        $lastRequisition = self::where('requisition_number', 'like', "{$prefix}%")
            ->orderBy('requisition_number', 'desc')
            ->first();

        if ($lastRequisition) {
            $lastNumber = (int) substr($lastRequisition->requisition_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}