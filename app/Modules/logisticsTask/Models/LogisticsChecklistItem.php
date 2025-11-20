<?php

namespace App\Modules\logisticsTask\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'logistics_checklist_id',
        'item_id',
        'item_name',
        'status',
        'notes',
        'checked_at',
        'checked_by',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'status' => 'string',
    ];

    // Relationships
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(LogisticsChecklist::class, 'logistics_checklist_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'checked_by');
    }

    // Scopes
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeChecked($query)
    {
        return $query->whereNotNull('checked_at');
    }

    public function scopeUnchecked($query)
    {
        return $query->whereNull('checked_at');
    }

    // Helper methods
    public function isPresent(): bool
    {
        return $this->status === 'present';
    }

    public function isMissing(): bool
    {
        return $this->status === 'missing';
    }

    public function isComingLater(): bool
    {
        return $this->status === 'coming_later';
    }

    public function markAsChecked(string $status = 'present', ?string $notes = null): void
    {
        $this->update([
            'status' => $status,
            'notes' => $notes,
            'checked_at' => now(),
            'checked_by' => auth()->id(),
        ]);
    }
}
