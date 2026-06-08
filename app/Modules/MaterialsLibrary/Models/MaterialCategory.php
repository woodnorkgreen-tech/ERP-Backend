<?php

namespace App\Modules\MaterialsLibrary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaterialCategory extends Model
{
    use SoftDeletes;

    protected $table = 'material_categories';

    protected $fillable = [
        'name',
        'code',
        'parent_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'sort_order'  => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MaterialCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MaterialCategory::class, 'parent_id')->orderBy('sort_order');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(LibraryMaterial::class, 'material_category_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function isRoot(): bool
    {
        return is_null($this->parent_id);
    }

    public function getRootName(): string
    {
        return $this->parent?->name ?? $this->name;
    }
}
