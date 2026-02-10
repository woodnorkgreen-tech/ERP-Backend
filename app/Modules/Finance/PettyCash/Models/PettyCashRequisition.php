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
    use HasFactory, SoftDeletes;

    protected $table = 'petty_cash_requisitions';

    protected $fillable = [
        'requisition_number',
        'user_id',
        'department_id',
        'category',
        'purpose',
        'total_amount',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'digital_signature',
        'received_at',
        'payee_name',
        'project_id',
        'enquiry_id',
        'signing_token',
        'received_by',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
     * Generate a unique requisition number.
     */
    public static function generateRequisitionNumber(): string
    {
        $last = self::orderBy('id', 'desc')->first();
        $nextId = $last ? $last->id + 1 : 1;
        return 'PCR-' . str_pad($nextId, 6, '0', STR_PAD_LEFT);
    }
}
