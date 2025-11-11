<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetAddition extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_budget_data_id',
        'title',
        'description',
        'materials',
        'labour',
        'expenses',
        'logistics',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'approval_notes',
        'budget_type',
        'source_type',
        'source_material_id',
        'source_element_id',
        'total_amount',
        'rejection_reason',
        'rejected_by',
        'rejected_at',
    ];

    protected $casts = [
        'materials' => 'array',
        'labour' => 'array',
        'expenses' => 'array',
        'logistics' => 'array',
        'approved_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'rejected_at' => 'datetime',
    ];

    // Relationships
    public function budget(): BelongsTo
    {
        return $this->belongsTo(TaskBudgetData::class, 'task_budget_data_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function sourceMaterial(): BelongsTo
    {
        return $this->belongsTo(ElementMaterial::class, 'source_material_id');
    }

    public function sourceElement(): BelongsTo
    {
        return $this->belongsTo(ProjectElement::class, 'source_element_id');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending_approval');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    // Helper methods
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function approve(int $userId, ?string $notes = null): bool
    {
        return $this->update([
            'status' => 'approved',
            'approved_by' => $userId,
            'approved_at' => now(),
            'approval_notes' => $notes,
        ]);
    }

    public function reject(int $userId, ?string $reason = null): bool
    {
        return $this->update([
            'status' => 'rejected',
            'rejected_by' => $userId,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }
}
