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
    ];

    protected $casts = [
        'materials' => 'array',
        'labour' => 'array',
        'expenses' => 'array',
        'logistics' => 'array',
        'approved_at' => 'datetime',
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

    // Helper methods
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending_approval';
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

    public function reject(int $userId, ?string $notes = null): bool
    {
        return $this->update([
            'status' => 'rejected',
            'approved_by' => $userId,
            'approved_at' => now(),
            'approval_notes' => $notes,
        ]);
    }
}
