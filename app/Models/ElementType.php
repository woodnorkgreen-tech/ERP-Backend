<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElementType extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'display_name',
        'category',
        'is_predefined',
        'order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_predefined' => 'boolean',
        'order' => 'integer',
    ];

    /**
     * Scope a query to only include predefined element types.
     */
    public function scopePredefined($query)
    {
        return $query->where('is_predefined', true);
    }

    /**
     * Scope a query to only include custom (non-predefined) element types.
     */
    public function scopeCustom($query)
    {
        return $query->where('is_predefined', false);
    }

    /**
     * Check if this element type is being used by any project elements.
     *
     * @return bool
     */
    public function isInUse(): bool
    {
        // Check if any project_elements use this element type
        return \DB::table('project_elements')
            ->where('element_type', $this->name)
            ->exists();
    }
}
