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