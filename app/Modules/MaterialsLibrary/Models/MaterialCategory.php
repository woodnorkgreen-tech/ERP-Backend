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
        'item_type_id',
        'code',
        'parent_id',
        'sort_order',
        'is_active',
        'is_selectable',
        'default_issue_disposition',
        'default_tracking_mode',
        'allowed_uoms',
        'required_attributes',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'sort_order'  => 'integer',
        'is_selectable' => 'boolean',
        'allowed_uoms' => 'array',
        'required_attributes' => 'array',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MaterialCategory::class, 'parent_id');
    }

    public function itemType(): BelongsTo
    {
        return $this->belongsTo(MaterialItemType::class, 'item_type_id');
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

    /**
     * The specification fields that describe a material in this category.
     *
     * Schema is data, not code: it lives on the category and is inherited down
     * the tree, so shared facts sit on the root ("Boards" carries thickness and
     * sheet size) and a leaf only adds what is special to it. A leaf redefining
     * a key wins over its parent's version of the same key.
     *
     * Each entry is ['key' => ..., 'label' => ..., 'type' => ..., 'required' => bool].
     * A bare list of strings is accepted too, so an administrator can name three
     * fields quickly and refine them later.
     *
     * @return array<int,array<string,mixed>>
     */
    public function resolvedAttributeSchema(): array
    {
        $chain = [];
        for ($node = $this; $node !== null; $node = $node->parent) {
            array_unshift($chain, $node);
        }

        $resolved = [];
        foreach ($chain as $node) {
            foreach (self::normaliseSchema($node->required_attributes) as $field) {
                $resolved[$field['key']] = $field;
            }
        }

        return array_values($resolved);
    }

    /** @return array<int,array<string,mixed>> */
    public static function normaliseSchema(mixed $schema): array
    {
        if (! is_array($schema)) {
            return [];
        }

        $fields = [];
        foreach ($schema as $entry) {
            $field = is_string($entry) ? ['key' => $entry] : $entry;
            if (! is_array($field) || blank($field['key'] ?? null)) {
                continue;
            }

            $fields[] = [
                'key' => $field['key'],
                'label' => $field['label'] ?? \Illuminate\Support\Str::headline($field['key']),
                'type' => $field['type'] ?? 'text',
                'unit' => $field['unit'] ?? null,
                'options' => $field['options'] ?? null,
                'required' => (bool) ($field['required'] ?? false),
            ];
        }

        return $fields;
    }
}
