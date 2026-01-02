<?php

namespace App\Modules\MaterialsLibrary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;

class LibraryMaterial extends Model
{
    use SoftDeletes;
    /**
     * The table associated with the model.
     */
    protected $table = 'library_materials';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'workstation_id',
        'material_code',
        'material_name',
        'category',
        'subcategory',
        'unit_of_measure',
        'unit_cost',
        'attributes',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'attributes' => 'array',
        'unit_cost' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [];

    /**
     * Get the workstation that owns the material.
     */
    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    /**
     * Get the user who created this material.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this material.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope a query to only include active materials.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query by workstation.
     */
    public function scopeByWorkstation($query, $workstationId)
    {
        return $query->where('workstation_id', $workstationId);
    }

    /**
     * Scope a query by category.
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Query JSON attributes (works with MySQL 5.7+, PostgreSQL, SQLite 3.38+)
     * Example: Material::whereAttribute('thickness', '18mm')->get()
     */
    public function scopeWhereAttribute($query, string $key, $value)
    {
        return $query->where("attributes->{$key}", $value);
    }

    /**
     * Search materials by name or code.
     */
    public function scopeSearch($query, ?string $searchTerm)
    {
        return $query->where(function ($q) use ($searchTerm) {
            $q->where('material_name', 'like', "%{$searchTerm}%")
              ->orWhere('material_code', 'like', "%{$searchTerm}%")
              ->orWhere('category', 'like', "%{$searchTerm}%")
              ->orWhere('subcategory', 'like', "%{$searchTerm}%");
        });
    }

    // Removed custom getAttribute to avoid potential conflicts with Eloquent accessor logic

    // Removed incompatible methods to use default Eloquent behavior
}
