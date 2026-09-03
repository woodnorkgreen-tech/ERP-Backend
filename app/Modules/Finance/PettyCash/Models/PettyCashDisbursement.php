<?php

namespace App\Modules\Finance\PettyCash\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PettyCashDisbursement extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'petty_cash_disbursements';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'top_up_id',
        'receiver',
        'account',
        'expense_code_id',
        'amount',
        'description',
        'project_name',
        'project_id',
        'project_enquiry_id',
        'venue',
        'classification',
        'job_number',
        'payment_method',
        'payment_source_id',
        'transaction_code',
        'status',
        'void_reason',
        'created_by',
        'voided_by',
        'voided_at',
        'tax',
        'receipt_type',
        'receipt_number',
        'tax_amount',
        'date_disbursed',
        'is_archived',
        'archived_at',
        'archived_by',
        'requisition_id',
        'direct_payment_reason',
        'transaction_cost',
        'budget_category',
        'planned_cost_line_id',
        'idempotency_key',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string,string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'voided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'date_disbursed' => 'date',
        'is_archived' => 'boolean',
        'archived_at' => 'datetime',
        'transaction_cost' => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    /**
     * Get the top-up this disbursement belongs to.
     */
    public function topUp(): BelongsTo
    {
        return $this->belongsTo(PettyCashTopUp::class, 'top_up_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PettyCashDisbursementAllocation::class, 'disbursement_id');
    }

    public function expenseCode(): BelongsTo
    {
        return $this->belongsTo(
            \App\Modules\Finance\CostCollector\Models\ExpenseCode::class,
            'expense_code_id',
        );
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Models\PaymentSource::class, 'payment_source_id');
    }

    /**
     * Get the user who created this disbursement.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who voided this disbursement.
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * Get the requisition this disbursement was created for.
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PettyCashRequisition::class, 'requisition_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ProjectEnquiry::class, 'project_enquiry_id');
    }

    public function plannedCostLine(): BelongsTo
    {
        return $this->belongsTo(
            \App\Modules\Finance\CostCollector\Models\CostLine::class,
            'planned_cost_line_id',
        );
    }

    /**
     * Scope to filter active disbursements.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to filter voided disbursements.
     */
    public function scopeVoided($query)
    {
        return $query->where('status', 'voided');
    }

    /**
     * Scope to filter by classification.
     */
    public function scopeByClassification($query, string $classification)
    {
        return $query->where('classification', $classification);
    }

    /**
     * Scope to filter by payment method.
     */
    public function scopeByPaymentMethod($query, string $paymentMethod)
    {
        return $query->where('payment_method', $paymentMethod);
    }

    /**
     * Scope to filter by project name.
     */
    public function scopeByProject($query, string $projectName)
    {
        return $query->where('project_name', 'like', '%' . $projectName . '%');
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope to filter by creator.
     */
    public function scopeByCreator($query, int $userId)
    {
        return $query->where('created_by', $userId);
    }

    /**
     * Scope to search across multiple fields.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('receiver', 'like', '%' . $search . '%')
              ->orWhere('account', 'like', '%' . $search . '%')
              ->orWhere('description', 'like', '%' . $search . '%')
              ->orWhere('project_name', 'like', '%' . $search . '%')
              ->orWhere('job_number', 'like', '%' . $search . '%')
              ->orWhere('transaction_code', 'like', '%' . $search . '%');
        });
    }

    /**
     * Scope to order by most recent first.
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Scope to filter not archived transactions.
     */
    public function scopeNotArchived($query)
    {
        return $query->where('is_archived', false);
    }

    /**
     * Scope to filter archived transactions.
     */
    public function scopeArchived($query)
    {
        return $query->where('is_archived', true);
    }

    /**
     * Check if this disbursement is active.
     */
    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if this disbursement is voided.
     */
    public function getIsVoidedAttribute(): bool
    {
        return $this->status === 'voided';
    }

    /**
     * Void this disbursement.
     */
    public function void(int $voidedBy, string $reason): bool
    {
        $this->update([
            'status' => 'voided',
            'void_reason' => $reason,
            'voided_by' => $voidedBy,
            'voided_at' => now(),
        ]);

        return true;
    }
}
