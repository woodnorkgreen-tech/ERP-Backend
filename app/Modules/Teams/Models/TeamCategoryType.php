<?php

namespace App\Modules\Teams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamCategoryType extends Model
{
    protected $fillable = [
        'category_id',
        'team_type_id',
        'is_available',
        'required',
        'min_members',
        'max_members'
    ];

    protected $casts = [
        'is_available' => 'boolean',
        'required' => 'boolean',
        'min_members' => 'integer',
        'max_members' => 'integer'
    ];

    // Relationships
    public function category(): BelongsTo
    {
        return $this->belongsTo(TeamCategory::class);
    }

    public function teamType(): BelongsTo
    {
        return $this->belongsTo(TeamType::class);
    }

    // Scopes
    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    public function scopeRequired($query)
    {
        return $query->where('required', true);
    }
}