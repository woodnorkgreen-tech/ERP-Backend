<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetCategory extends Model
{
    protected $table = 'asset_categories';

    protected $fillable = [
        'name',
        'code',
        'parent_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Derive a short, unique code from a category name for use in generated
     * asset tags — e.g. "Workshop Machinery" -> "WM", "Printing" -> "PRI".
     * Appends a digit if the natural abbreviation is already taken.
     */
    public static function suggestCode(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z\s]/', ' ', $name);
        $skip = ['and', 'of', 'the', 'for'];
        $words = array_values(array_filter(
            preg_split('/\s+/', trim($clean)),
            fn ($w) => $w !== '' && !in_array(strtolower($w), $skip)
        ));

        if (count($words) >= 2) {
            $base = strtoupper(substr(implode('', array_map(fn ($w) => $w[0], array_slice($words, 0, 4))), 0, 4));
        } else {
            $base = strtoupper(substr($words[0] ?? 'GEN', 0, 3));
        }

        $code = $base !== '' ? $base : 'GEN';
        $suffix = 1;
        while (static::where('code', $code)->exists()) {
            $suffix++;
            $code = $base . $suffix;
        }

        return $code;
    }

    /**
     * The parent category, if this is a sub-category.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Sub-categories under this category.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Assets filed directly under this category.
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'category_id');
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
