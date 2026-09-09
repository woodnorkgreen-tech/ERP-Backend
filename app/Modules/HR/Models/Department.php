<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'manager_id',
        'budget',
        'location',
        // 'direct' means this department's people deliver client work, so their
        // pay is a cost of sales rather than office overhead. Null is treated as
        // indirect, which is what payroll did before the distinction existed.
        'labour_classification',
    ];

    /** Departments whose payroll is a direct cost of delivering client work. */
    public const LABOUR_DIRECT = 'direct';

    /** Everything else: administration, support, and anything unclassified. */
    public const LABOUR_INDIRECT = 'indirect';

    protected $casts = [
        'budget' => 'decimal:2'
    ];

    /**
     * Get the employees for the department.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Get the department manager.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /**
     * Get active employees count.
     */
    public function getActiveEmployeesCountAttribute(): int
    {
        return $this->employees()->active()->count();
    }

    /**
     * Scope to filter departments with managers.
     */
    public function scopeHasManager($query)
    {
        return $query->whereNotNull('manager_id');
    }

    /**
     * Scope to filter departments by location.
     */
    public function scopeInLocation($query, $location)
    {
        return $query->where('location', $location);
    }
}