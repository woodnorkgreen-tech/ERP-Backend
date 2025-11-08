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
        'location'
    ];

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