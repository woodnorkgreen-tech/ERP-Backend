<?php

namespace App\Modules\Teams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamCategory extends Model
{
    protected $fillable = [
        'category_key',
        'name',
        'display_name',
        'color_code',
        'sort_order',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];

    // Relationships
    public function teamTasks(): HasMany
    {
        return $this->hasMany(TeamsTask::class);
    }

    public function availableTypes(): HasMany
    {
        return $this->hasMany(TeamCategoryType::class, 'category_id')
                    ->where('is_available', true)
                    ->with('teamType');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByKey($query, string $key)
    {
        return $query->where('category_key', $key);
    }
}