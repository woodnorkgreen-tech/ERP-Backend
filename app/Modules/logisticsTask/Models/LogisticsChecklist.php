<?php

namespace App\Modules\logisticsTask\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsChecklist extends Model
{
    use HasFactory;

    protected $fillable = [
        'logistics_task_id',
        'checklist_data',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'checklist_data' => 'array',
    ];

    // Relationships
    public function logisticsTask(): BelongsTo
    {
        return $this->belongsTo(LogisticsTask::class);
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(LogisticsChecklistItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    // Helper methods
    public function getChecklistDataAttribute($value)
    {
        return json_decode($value, true) ?? [];
    }

    public function setChecklistDataAttribute($value)
    {
        $this->attributes['checklist_data'] = json_encode($value ?? []);
    }

    public function getCompletionPercentageAttribute()
    {
        $data = $this->checklist_data;

        if (empty($data) || !isset($data['items'])) {
            return 0;
        }

        $totalItems = count($data['items']);
        if ($totalItems === 0) {
            return 0;
        }

        $completedItems = collect($data['items'])->filter(function ($item) {
            return isset($item['status']) && $item['status'] === 'present';
        })->count();

        return round(($completedItems / $totalItems) * 100, 1);
    }
}
