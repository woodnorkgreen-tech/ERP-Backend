<?php

namespace App\Modules\Teams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamType extends Model
{
    protected $fillable = [
        'type_key',
        'name',
        'display_name',
        'description',
        'default_hourly_rate',
        'max_members_per_team',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'default_hourly_rate' => 'decimal:2',
        'max_members_per_team' => 'integer'
    ];

    // Relationships
    public function teamTasks(): HasMany
    {
        return $this->hasMany(TeamsTask::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(TeamCategoryType::class)
                    ->with('category');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByKey($query, string $key)
    {
        return $query->where('type_key', $key);
    }
}