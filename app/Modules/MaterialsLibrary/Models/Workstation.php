<?php

namespace App\Modules\MaterialsLibrary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Workstation extends Model
{
    use SoftDeletes;
    /**
     * The table associated with the model.
     */
    protected $table = 'workstations';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'sort_order',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Get the materials for this workstation.
     */
    public function materials(): HasMany
    {
        return $this->hasMany(LibraryMaterial::class);
    }

    /**
     * Get active materials for this workstation.
     */
    public function activeMaterials(): HasMany
    {
        return $this->hasMany(LibraryMaterial::class)->where('is_active', true);
    }

    /**
     * Scope a query to only include active workstations.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to order by sort_order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc');
    }

    /**
     * Get workstation by code.
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }
}
