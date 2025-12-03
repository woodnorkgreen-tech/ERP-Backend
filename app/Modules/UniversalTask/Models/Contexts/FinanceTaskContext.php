<?php

namespace App\Modules\UniversalTask\Models\Contexts;

use App\Models\User;
use App\Modules\UniversalTask\Models\Task;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceTaskContext extends Model
{
    use HasFactory;

    protected $table = 'finance_task_contexts';

    protected $fillable = [
        'task_id',
        'transaction_type',
        'budget_code',
        'amount',
        'currency',
        'payment_status',
        'payment_method',
        'vendor_name',
        'vendor_account_number',
        'invoice_number',
        'invoice_date',
        'due_date',
        'payment_date',
        'reference_number',
        'payment_description',
        'line_items',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'approval_status',
        'approved_by',
        'approved_at',
        'approval_notes',
        'supporting_documents',
        'account_code',
        'cost_center',
        'project_code',
        'requires_receipt',
        'receipt_path',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'invoice_date' => 'datetime',
        'due_date' => 'datetime',
        'payment_date' => 'datetime',
        'line_items' => 'array',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'supporting_documents' => 'array',
        'requires_receipt' => 'boolean',
    ];

    // ==================== Relationships ====================

    /**
     * Get the task that owns this finance context.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the user who approved this finance task.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ==================== Methods ====================

    /**
     * Check if the payment is approved.
     */
    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    /**
     * Check if the payment is rejected.
     */
    public function isRejected(): bool
    {
        return $this->approval_status === 'rejected';
    }

    /**
     * Check if the payment is completed.
     */
    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    /**
     * Check if the payment is overdue.
     */
    public function isOverdue(): bool
    {
        if (!$this->due_date) {
            return false;
        }

        return $this->due_date->isPast() && !$this->isPaid();
    }

    /**
     * Approve the finance task.
     */
    public function approve(int $userId, string $notes = null): void
    {
        $this->approval_status = 'approved';
        $this->approved_by = $userId;
        $this->approved_at = now();
        $this->approval_notes = $notes;
        $this->save();
    }

    /**
     * Reject the finance task.
     */
    public function reject(string $notes = null): void
    {
        $this->approval_status = 'rejected';
        $this->approval_notes = $notes;
        $this->save();
    }

    /**
     * Mark the payment as paid.
     */
    public function markAsPaid(string $paymentMethod = null): void
    {
        $this->payment_status = 'paid';
        $this->payment_date = now();
        
        if ($paymentMethod) {
            $this->payment_method = $paymentMethod;
        }
        
        $this->save();
    }

    /**
     * Calculate the total amount including tax and discount.
     */
    public function calculateTotal(): float
    {
        $subtotal = $this->amount ?? 0;
        $tax = $this->tax_amount ?? 0;
        $discount = $this->discount_amount ?? 0;

        return round($subtotal + $tax - $discount, 2);
    }

    /**
     * Get the number of days until payment is due.
     */
    public function getDaysUntilDue(): ?int
    {
        if (!$this->due_date) {
            return null;
        }

        return now()->diffInDays($this->due_date, false);
    }

    /**
     * Get the number of days overdue.
     */
    public function getDaysOverdue(): ?int
    {
        if (!$this->due_date || !$this->isOverdue()) {
            return null;
        }

        return $this->due_date->diffInDays(now());
    }

    /**
     * Check if all required documents are present.
     */
    public function hasRequiredDocuments(): bool
    {
        if ($this->requires_receipt && empty($this->receipt_path)) {
            return false;
        }

        return true;
    }
}
