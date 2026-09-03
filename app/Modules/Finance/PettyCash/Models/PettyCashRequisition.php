<?php

namespace App\Modules\Finance\PettyCash\Models;

use App\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\HR\Models\Employee;

use Illuminate\Database\Eloquent\SoftDeletes;

class PettyCashRequisition extends Model
{
    // A pending requisition is a request for review, not yet a financial
    // commitment. Governance is deliberately evaluated when Finance approves
    // it; running the gate on model creation made it impossible to request a
    // budget correction through the normal workflow.
    use HasFactory, SoftDeletes;

    protected $table = 'petty_cash_requisitions';

    protected $fillable = [
        'requisition_number',
        'user_id',
        'department_id',
        'category',
        'requisition_type_id',
        'purpose',
        'custom_fields',
        'type_snapshot',
        'total_amount',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'digital_signature',
        'received_at',
        'payee_name',
        'project_id',
        'project_name',
        'venue',
        'enquiry_id',
        'signing_token',
        'received_by',
        'payee_id',
        'payee_phone',
        'bill_id',
        'is_public',
        'requester_name',
        'requester_phone',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'custom_fields' => 'array',
        'type_snapshot' => 'array',
    ];

    /**
     * Get the requester.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the department.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function requisitionType(): BelongsTo
    {
        return $this->belongsTo(PettyCashRequisitionType::class, 'requisition_type_id');
    }

    /**
     * Get the items (for bulk requests).
     */
    public function items(): HasMany
    {
        return $this->hasMany(PettyCashRequisitionItem::class, 'requisition_id');
    }

    /**
     * Get the approver.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the associated disbursement.
     */
    public function disbursement(): HasOne
    {
        return $this->hasOne(PettyCashDisbursement::class, 'requisition_id');
    }

    /**
     * Get the payee (individual receiving cash).
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'payee_id');
    }

    /**
     * Get the project.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    /**
     * Get the enquiry.
     */
    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ProjectEnquiry::class, 'enquiry_id');
    }

    /**
     * Get the associated bill.
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\ProcurementStores\Models\Bill::class, 'bill_id');
    }

    /**
     * Generate a unique requisition number.
     */
    public static function generateRequisitionNumber(): string
    {
        $maxNum = self::withTrashed()
            ->where('requisition_number', 'LIKE', 'PCR-%')
            ->selectRaw("MAX(CAST(SUBSTRING(requisition_number, 5) AS UNSIGNED)) as max_val")
            ->value('max_val');
            
        $nextNum = $maxNum ? $maxNum + 1 : 1;
        return 'PCR-' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
    }
}
